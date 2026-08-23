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
                'fraudmodule' => 'maxmind',
                'fraudoutput' => '{"billing_address":{"latitude":-15.7783}}',
            ];
        }, ['readonly' => true]);

        $result = json_decode($tools->getOrder(orderid: 70), true);

        $this->assertArrayNotHasKey('fraudoutput', $result);
        $this->assertSame('maxmind', $result['fraudmodule']);
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

}
