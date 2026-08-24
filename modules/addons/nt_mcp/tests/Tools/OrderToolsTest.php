<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\OrderTools;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

class OrderToolsTest extends TestCase
{
    private function makeTools(?callable $callable = null, array $gates = []): OrderTools
    {
        $api = new LocalApiClient('testadmin');
        $api->setGates($gates);
        $api->setCallable($callable ?? function (string $cmd, array $params) {
            return ['result' => 'success'];
        });

        return new OrderTools($api);
    }

    // ---------------------------------------------------------------
    // Leitura (READ) — disponível mesmo com todos os gates desligados.
    // ---------------------------------------------------------------

    public function test_list_orders_works_with_all_gates_off(): void
    {
        $captured = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$captured) {
            $captured = ['cmd' => $cmd, 'params' => $params];
            return ['result' => 'success'];
        }, ['readonly' => true]);

        $tools->listOrders(status: 'Pending', clientid: 5);

        $this->assertSame('GetOrders', $captured['cmd']);
        $this->assertSame('Pending', $captured['params']['status']);
        $this->assertSame(5, $captured['params']['userid']);
    }

    public function test_get_order_strips_maxmind_fraud_dump(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return [
                'result' => 'success',
                'id' => 70,
                'ipaddress' => '203.0.113.70',
                'fraudmodule' => 'maxmind',
                'fraudoutput' => '{"billing_address":{"latitude":-15.7783}}',
            ];
        }, ['readonly' => true]);

        $result = json_decode($tools->getOrder(orderid: 70), true);

        $this->assertArrayNotHasKey('fraudoutput', $result);
        $this->assertArrayNotHasKey('ipaddress', $result);
        $this->assertSame('maxmind', $result['fraudmodule']);
    }

    public function test_list_orders_strips_client_ip_from_every_order(): void
    {
        $tools = $this->makeTools(fn() => [
            'result' => 'success',
            'orders' => ['order' => [
                ['id' => 70, 'ipaddress' => '203.0.113.70', 'status' => 'Pending'],
                ['id' => 71, 'ipaddress' => '203.0.113.71', 'status' => 'Active'],
            ]],
        ], ['readonly' => true]);

        $result = json_decode($tools->listOrders(), true);

        foreach ($result['orders']['order'] as $order) {
            $this->assertArrayNotHasKey('ipaddress', $order);
        }
    }

    public function test_list_orders_defaults_to_lite_without_customer_identity(): void
    {
        $tools = $this->makeTools(fn() => [
            'result' => 'success',
            'orders' => ['order' => [[
                'id' => 70,
                'name' => 'Ana Cliente',
                'firstname' => 'Ana',
                'email' => 'ana@example.test',
                'status' => 'Pending',
                'lineitems' => ['lineitem' => [[
                    'id' => 9,
                    'product' => 'Hospedagem Premium',
                    'name' => 'dominio.example',
                ]]],
            ]]],
        ], ['readonly' => true]);

        $order = json_decode($tools->listOrders(), true)['orders']['order'][0];

        foreach (['name', 'firstname', 'email'] as $pii) {
            $this->assertArrayNotHasKey($pii, $order);
        }
        $this->assertSame('Hospedagem Premium', $order['lineitems']['lineitem'][0]['product']);
        $this->assertSame('dominio.example', $order['lineitems']['lineitem'][0]['name']);
    }

    public function test_get_order_full_keeps_identity_but_never_secrets(): void
    {
        $tools = $this->makeTools(fn() => [
            'result' => 'success',
            'id' => 70,
            'name' => 'Ana Cliente',
            'email' => 'ana@example.test',
            'transfersecret' => 'domain-secret',
            'ipaddress' => '203.0.113.70',
            'fraudoutput' => 'raw fraud dump',
        ], ['readonly' => true]);

        $order = json_decode($tools->getOrder(70, 'full'), true);

        $this->assertSame('Ana Cliente', $order['name']);
        $this->assertSame('ana@example.test', $order['email']);
        foreach (['transfersecret', 'ipaddress', 'fraudoutput'] as $secret) {
            $this->assertArrayNotHasKey($secret, $order);
        }
    }

    public function test_order_reads_reject_invalid_fields(): void
    {
        $tools = $this->makeTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("fields deve ser 'lite' ou 'full'");
        $tools->getOrder(70, 'identity');
    }

    // ---------------------------------------------------------------
    // whmcs_get_products
    // ---------------------------------------------------------------

    public function test_get_products_calls_get_products_and_strips_passwords(): void
    {
        $captured = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$captured) {
            $captured = ['cmd' => $cmd, 'params' => $params];
            return [
                'result' => 'success',
                'products' => ['product' => [
                    ['pid' => 1, 'name' => 'NT VPS 1GB', 'password' => 'should-not-leak'],
                ]],
            ];
        }, ['readonly' => true]);

        $result = json_decode($tools->getProducts(gid: 3), true);

        $this->assertSame('GetProducts', $captured['cmd']);
        $this->assertSame(3, $captured['params']['gid']);
        $this->assertArrayNotHasKey('password', $result['products']['product'][0]);
        $this->assertSame('NT VPS 1GB', $result['products']['product'][0]['name']);
    }

    // ---------------------------------------------------------------
    // cancel_order (DESTRUCTIVE + confirm)
    // ---------------------------------------------------------------

    public function test_cancel_order_requires_confirm_true(): void
    {
        $called = false;
        $tools = $this->makeTools(function () use (&$called) {
            $called = true;
            return ['result' => 'success'];
        }, ['destructive' => true]);

        $json = $tools->cancelOrder(orderid: 12, confirm: false);
        $result = json_decode($json, true);

        $this->assertFalse($called, 'sem confirm=true nada pode chegar à LocalAPI');
        $this->assertSame('error', $result['result']);
        $this->assertSame(12, $result['orderid']);
        $this->assertSame('Cancellation requires confirm=true', $result['message']);
    }

    public function test_cancel_order_calls_cancel_order_when_confirmed(): void
    {
        $captured = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$captured) {
            $captured = ['cmd' => $cmd, 'params' => $params];
            return ['result' => 'success'];
        }, ['destructive' => true]);

        $json = $tools->cancelOrder(orderid: 12, confirm: true);
        $result = json_decode($json, true);

        $this->assertSame('CancelOrder', $captured['cmd']);
        $this->assertSame('success', $result['result']);
        $this->assertSame(12, $result['orderid']);
    }

    public function test_cancel_order_outside_client_allowlist_never_reaches_destructive_api(): void
    {
        $calls = [];
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$calls) {
            $calls[] = $cmd;
            if ($cmd === 'GetOrders') {
                return [
                    'result' => 'success',
                    'orders' => ['order' => [[
                        'id' => (int) $params['id'],
                        'userid' => 99,
                    ]]],
                ];
            }
            return ['result' => 'success'];
        }, ['destructive' => true, 'allowlist_clientids' => [5]]);

        try {
            $tools->cancelOrder(orderid: 12, confirm: true);
            $this->fail('pedido fora da allowlist deveria ser negado');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('write_target_not_allowed', $exception->getMessage());
        }

        $this->assertSame(['GetOrders'], $calls, 'CancelOrder nunca pode chegar à LocalAPI');
    }

    /** Decisão fechada: a tool não envia e-mail. */
    public function test_cancel_order_always_suppresses_email(): void
    {
        $captured = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$captured) {
            $captured = $params;
            return ['result' => 'success'];
        }, ['destructive' => true]);

        $tools->cancelOrder(orderid: 12, confirm: true);

        $this->assertTrue($captured['noemail']);
        $this->assertArrayNotHasKey('sendemail', $captured, 'o parâmetro incorreto sendEmail foi removido');
    }

    /** Decisão fechada: a tool não expõe cancelsub nem sendEmail. */
    public function test_cancel_order_does_not_expose_cancelsub_or_sendemail(): void
    {
        $params = (new \ReflectionMethod(OrderTools::class, 'cancelOrder'))->getParameters();
        $names = array_map(fn(\ReflectionParameter $p) => $p->getName(), $params);

        $this->assertSame(['orderid', 'confirm'], $names);
    }

    public function test_cancel_order_never_forwards_cancelsub(): void
    {
        $captured = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$captured) {
            $captured = $params;
            return ['result' => 'success'];
        }, ['destructive' => true]);

        $tools->cancelOrder(orderid: 12, confirm: true);

        $this->assertArrayNotHasKey('cancelsub', $captured);
    }

    /** confirm=true é defesa adicional — NÃO substitui o gate DESTRUCTIVE. */
    public function test_cancel_order_blocked_by_gate_even_with_confirm_true(): void
    {
        $tools = $this->makeTools(null, ['write' => true]); // DESTRUCTIVE off

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('class DESTRUCTIVE disabled');

        $tools->cancelOrder(orderid: 12, confirm: true);
    }

    public function test_cancel_order_blocked_by_readonly_master_switch(): void
    {
        $tools = $this->makeTools(null, ['readonly' => true, 'destructive' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked');

        $tools->cancelOrder(orderid: 12, confirm: true);
    }

    // ---------------------------------------------------------------
    // pending_order (WRITE)
    // ---------------------------------------------------------------

    public function test_pending_order_is_write_and_blocked_by_default(): void
    {
        $tools = $this->makeTools(null, []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('class WRITE disabled');

        $tools->pendingOrder(orderid: 12);
    }

    public function test_pending_order_allowed_when_write_gate_on(): void
    {
        $captured = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$captured) {
            $captured = ['cmd' => $cmd, 'params' => $params];
            return ['result' => 'success'];
        }, ['write' => true]);

        $tools->pendingOrder(orderid: 12);

        $this->assertSame('PendingOrder', $captured['cmd']);
        $this->assertSame(['orderid' => 12], $captured['params']);
    }

    /**
     * O catálogo real devolvia ~126 KB porque cada `description` é HTML de
     * landing page. O default entrega texto truncado; o HTML continua
     * disponível sob `full_description=true`.
     */
    public function test_get_products_summarises_descriptions_by_default(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'products' => ['product' => [
            ['pid' => 1, 'name' => 'NT KVM', 'description' => '<p>Plano <b>rápido</b></p>'],
        ]]]);

        $data = json_decode($tools->getProducts(), true);
        $product = $data['products']['product'][0];

        $this->assertArrayNotHasKey('description', $product);
        $this->assertSame('Plano rápido', $product['description_plain']);
    }

    public function test_get_products_returns_original_html_when_full_description_is_requested(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'products' => ['product' => [
            ['pid' => 1, 'name' => 'NT KVM', 'description' => '<p>Plano <b>rápido</b></p>'],
        ]]]);

        $data = json_decode($tools->getProducts(full_description: true), true);
        $product = $data['products']['product'][0];

        $this->assertSame('<p>Plano <b>rápido</b></p>', $product['description']);
        $this->assertArrayNotHasKey('description_plain', $product);
    }

    public function test_get_order_statuses_calls_the_read_command(): void
    {
        $seen = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$seen) {
            $seen = [$cmd, $params];
            return ['result' => 'success', 'statuses' => ['status' => []]];
        });

        $data = json_decode($tools->getOrderStatuses(), true);

        $this->assertSame(['GetOrderStatuses', []], $seen);
        $this->assertSame('success', $data['result']);
    }

    public function test_get_promotions_sends_the_code_filter_only_when_given(): void
    {
        $seen = [];
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$seen) {
            $seen[] = [$cmd, $params];
            return ['result' => 'success', 'promotions' => ['promotion' => []]];
        });

        $tools->getPromotions();
        $tools->getPromotions('NATAL10');

        $this->assertSame(['GetPromotions', []], $seen[0]);
        $this->assertSame(['GetPromotions', ['code' => 'NATAL10']], $seen[1]);
    }

    // ---------------------------------------------------------------
    // #19 — fields=lite, ciclos negativos, paginação local
    // ---------------------------------------------------------------

    private function catalogFixture(int $count = 3): array
    {
        $products = [];
        for ($i = 1; $i <= $count; $i++) {
            $products[] = [
                'pid' => $i, 'gid' => 2, 'type' => 'hostingaccount', 'name' => "Plano $i",
                'description' => '<p>HTML longo</p>', 'module' => 'plesk', 'paytype' => 'recurring',
                'product_url' => 'https://desenv.example/index.php?rp=/store/x',
                'pricing' => ['BRL' => [
                    'prefix' => 'R$', 'suffix' => '', 'msetupfee' => '0.00',
                    'monthly' => '10.00', 'quarterly' => '-1.00', 'annually' => '100.00',
                ]],
                'customfields' => ['customfield' => []],
                'configoptions' => ['configoption' => []],
            ];
        }
        return ['result' => 'success', 'totalresults' => $count, 'products' => ['product' => $products]];
    }

    public function test_get_products_default_is_lite_without_bulky_keys_and_negative_cycles(): void
    {
        $tools = $this->makeTools(fn() => $this->catalogFixture());
        $data = json_decode($tools->getProducts(gid: 2), true);
        $p = $data['products']['product'][0];

        foreach (['customfields', 'configoptions', 'description', 'product_url'] as $k) {
            $this->assertArrayNotHasKey($k, $p, "lite não deve conter $k");
        }
        $this->assertSame('Plano 1', $p['name']);
        $this->assertArrayNotHasKey('quarterly', $p['pricing']['BRL'], 'ciclo -1.00 deve sumir');
        $this->assertSame('10.00', $p['pricing']['BRL']['monthly']);
        $this->assertSame('R$', $p['pricing']['BRL']['prefix'], 'prefix não é ciclo, fica');
    }

    public function test_get_products_full_keeps_description_but_drops_negative_cycles(): void
    {
        $tools = $this->makeTools(fn() => $this->catalogFixture());
        $data = json_decode($tools->getProducts(gid: 2, fields: 'full'), true);
        $p = $data['products']['product'][0];

        $this->assertArrayHasKey('description_plain', $p);
        $this->assertArrayHasKey('customfields', $p);
        $this->assertArrayNotHasKey('quarterly', $p['pricing']['BRL']);
    }

    public function test_get_products_full_description_implies_full_fields(): void
    {
        $tools = $this->makeTools(fn() => $this->catalogFixture());
        $data = json_decode($tools->getProducts(gid: 2, full_description: true), true);
        $this->assertSame('<p>HTML longo</p>', $data['products']['product'][0]['description']);
    }

    public function test_get_products_paginates_locally_and_warns_without_filter(): void
    {
        $tools = $this->makeTools(fn() => $this->catalogFixture(5));
        $data = json_decode($tools->getProducts(limit: 2, limitstart: 1), true);

        $this->assertCount(2, $data['products']['product']);
        $this->assertSame(2, $data['products']['product'][0]['pid']);
        $this->assertSame(5, $data['totalresults']);
        $this->assertSame(2, $data['numreturned']);
        $this->assertSame(3, $data['next_limitstart']);
        $this->assertArrayHasKey('warning', $data);

        $filtered = json_decode($tools->getProducts(gid: 2), true);
        $this->assertArrayNotHasKey('warning', $filtered);
    }

    public function test_get_products_rejects_unknown_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeTools(fn() => $this->catalogFixture())->getProducts(fields: 'x');
    }

    // ---------------------------------------------------------------
    // product_url stripping
    // ---------------------------------------------------------------

    /** Por padrão, product_url é removido para reduzir payload. */
    public function test_get_products_strips_product_url_by_default(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'products' => ['product' => [
            ['pid' => 1, 'name' => 'NT VPS', 'product_url' => 'https://example.com/vps'],
        ]]]);

        $data = json_decode($tools->getProducts(fields: 'full'), true);
        $product = $data['products']['product'][0];

        $this->assertArrayNotHasKey('product_url', $product, 'product_url deve estar ausente por padrão');
        $this->assertSame('NT VPS', $product['name']);
    }

    /** product_url perde scheme/host, mas preserva path/query/fragment. */
    public function test_get_products_relativizes_product_url_when_include_urls_true(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'products' => ['product' => [
            ['pid' => 1, 'name' => 'NT VPS', 'product_url' => 'https://desenv.example:8443/vps?cycle=annual#buy'],
        ]]]);

        $data = json_decode($tools->getProducts(fields: 'full', include_urls: true), true);
        $product = $data['products']['product'][0];

        $this->assertArrayHasKey('product_url', $product, 'product_url deve estar presente com include_urls=true');
        $this->assertSame('/vps?cycle=annual#buy', $product['product_url']);
        $this->assertStringNotContainsString('desenv.example', $product['product_url']);
    }

    public function test_get_products_keeps_relative_url_and_drops_non_web_scheme(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'products' => ['product' => [
            ['pid' => 1, 'name' => 'Relativo', 'product_url' => 'index.php?rp=/store/vps'],
            ['pid' => 2, 'name' => 'Inválido', 'product_url' => 'javascript:alert(1)'],
        ]]]);

        $data = json_decode($tools->getProducts(fields: 'full', include_urls: true), true);

        $this->assertSame('/index.php?rp=/store/vps', $data['products']['product'][0]['product_url']);
        $this->assertArrayNotHasKey('product_url', $data['products']['product'][1]);
    }

    public function test_get_products_lite_caps_mcp_envelope_without_skipping_cursor(): void
    {
        $tools = $this->makeTools(fn() => $this->bulkyCatalogFixture());

        $firstJson = $tools->getProducts();
        $first = json_decode($firstJson, true);
        $firstBody = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['content' => [['type' => 'text', 'text' => $firstJson]], 'isError' => false],
        ]);

        $this->assertLessThanOrEqual(40000, strlen($firstBody));
        $this->assertTrue($first['payload_capped']);
        $this->assertLessThan(20, $first['numreturned']);
        $this->assertSame($first['numreturned'], $first['next_limitstart']);
        foreach ($first['products']['product'] as $product) {
            $this->assertArrayHasKey('pricing', $product);
        }

        $next = json_decode($tools->getProducts(limitstart: $first['next_limitstart']), true);
        $this->assertSame(
            $first['products']['product'][$first['numreturned'] - 1]['pid'] + 1,
            $next['products']['product'][0]['pid']
        );
    }

    public function test_get_products_lite_omits_only_pricing_when_one_product_exceeds_budget(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'products' => ['product' => [[
            'pid' => 1,
            'name' => 'Preço patológico',
            'description' => 'Produto único',
            'pricing' => ['BRL' => ['monthly' => str_repeat('9', 50000)]],
        ]]]]);

        $data = json_decode($tools->getProducts(pid: 1), true);

        $this->assertCount(1, $data['products']['product']);
        $this->assertArrayNotHasKey('pricing', $data['products']['product'][0]);
        $this->assertTrue($data['products']['product'][0]['pricing_omitted_for_size']);
        $this->assertTrue($data['payload_capped']);
    }

    /** Guarda contra is_array, elementos não-array e keys ausentes. */
    public function test_get_products_handles_malformed_products_gracefully(): void
    {
        // products.product não é array
        $tools = $this->makeTools(fn() => ['result' => 'success', 'products' => ['product' => 'not-an-array']]);
        $data = json_decode($tools->getProducts(), true);
        $this->assertSame('not-an-array', $data['products']['product']);

        // products.product ausente
        $tools = $this->makeTools(fn() => ['result' => 'success', 'products' => []]);
        $data = json_decode($tools->getProducts(), true);
        $this->assertArrayNotHasKey('product', $data['products']);

        // products ausente
        $tools = $this->makeTools(fn() => ['result' => 'success']);
        $data = json_decode($tools->getProducts(), true);
        $this->assertArrayNotHasKey('products', $data);
    }

    private function bulkyCatalogFixture(int $count = 20): array
    {
        $products = [];
        for ($i = 1; $i <= $count; $i++) {
            $pricing = [];
            foreach (['BRL', 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF'] as $currency) {
                $pricing[$currency] = [
                    'prefix' => $currency . ' ', 'suffix' => '',
                    'msetupfee' => '0.00', 'qsetupfee' => '0.00', 'ssetupfee' => '0.00',
                    'asetupfee' => '0.00', 'bsetupfee' => '0.00', 'tsetupfee' => '0.00',
                    'monthly' => '19.90', 'quarterly' => '55.00', 'semiannually' => '105.00',
                    'annually' => '199.00', 'biennially' => '379.00', 'triennially' => '539.00',
                ];
            }
            $products[] = [
                'pid' => $i,
                'gid' => 2,
                'type' => 'hostingaccount',
                'name' => "Plano {$i}",
                'description' => '<p>' . str_repeat('Descrição ampla ', 30) . '</p>',
                'module' => 'plesk',
                'paytype' => 'recurring',
                'pricing' => $pricing,
            ];
        }

        return ['result' => 'success', 'totalresults' => $count, 'products' => ['product' => $products]];
    }

}
