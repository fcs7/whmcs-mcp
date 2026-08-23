<?php
// tests/Whmcs/ResponseRedactorTest.php
namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\ResponseRedactor;
use PHPUnit\Framework\TestCase;

class ResponseRedactorTest extends TestCase
{
    public function test_scrub_sensitive_removes_nested_password(): void
    {
        $data = [
            'result' => 'success',
            'system_host' => 'desenv.ntweb.com.br',
            'client' => [
                'firstname' => 'Jane',
                'password' => 'hash-should-not-leak',
                'nested' => [
                    'securityqans' => 'my-answer',
                    'ipaddress' => '203.0.113.42',
                    'deeper' => [
                        'password2' => 'still-here',
                        'ok' => 'keep-me',
                    ],
                ],
            ],
        ];

        ResponseRedactor::scrubSensitive($data);

        $this->assertArrayNotHasKey('password', $data['client']);
        $this->assertArrayNotHasKey('securityqans', $data['client']['nested']);
        $this->assertArrayNotHasKey('ipaddress', $data['client']['nested']);
        $this->assertArrayNotHasKey('password2', $data['client']['nested']['deeper']);
        $this->assertSame('keep-me', $data['client']['nested']['deeper']['ok']);
        $this->assertSame('Jane', $data['client']['firstname']);
        $this->assertSame('desenv.ntweb.com.br', $data['system_host'], 'host explícito do boot-check é intencional');
    }

    public function test_strip_pay_methods_keeps_only_allowlisted_keys(): void
    {
        // Payload shaped like the real WHMCS GetPayMethods response.
        $result = [
            'paymethods' => [
                [
                    'id' => 1,
                    'type' => 'RemoteCreditCard',
                    'description' => 'Default Card',
                    'gateway_name' => 'stripe',
                    'contact_type' => 'Client',
                    'contact_id' => 1,
                    'card_last_four' => '4242',
                    'expiry_date' => '02/30',
                    'card_type' => 'Visa',
                    'last_updated' => '2026-05-17 10:01',
                    // sensitive — must be dropped by the allowlist:
                    'card_number' => '4111111111111111',
                    'remote_token' => '{"customer":"cus_x","method":"pm_x"}',
                    'token' => 'tok_secret',
                    'gateway_customer_id' => 'cus_secret',
                    'account_number' => '000123456',
                    'routing_number' => '021000021',
                ],
            ],
        ];

        ResponseRedactor::stripPayMethods($result);

        $pm = $result['paymethods'][0];

        foreach (['card_number', 'remote_token', 'token', 'gateway_customer_id', 'account_number', 'routing_number'] as $secret) {
            $this->assertArrayNotHasKey($secret, $pm);
        }

        $this->assertSame(1, $pm['id']);
        $this->assertSame('RemoteCreditCard', $pm['type']);
        $this->assertSame('Default Card', $pm['description']);
        $this->assertSame('stripe', $pm['gateway_name']);
        $this->assertSame('4242', $pm['card_last_four']);
        $this->assertSame('02/30', $pm['expiry_date']);
        $this->assertSame('Visa', $pm['card_type']);
    }

    public function test_strip_pay_methods_keeps_card_last_four_when_present(): void
    {
        $result = [
            'paymethods' => [
                [
                    'id' => 2,
                    'card_number' => '4111111111111111',
                    'card_last_four' => '1111',
                ],
            ],
        ];

        ResponseRedactor::stripPayMethods($result);

        $pm = $result['paymethods'][0];

        $this->assertArrayNotHasKey('card_number', $pm);
        $this->assertSame('1111', $pm['card_last_four']);
    }

    public function test_scrub_sensitive_removes_ticket_public_access_code(): void
    {
        $data = ['ticket' => ['id' => 29, 'c' => '8N8A4zvS', 'subject' => 'ticket aberto']];

        ResponseRedactor::scrubSensitive($data);

        $this->assertArrayNotHasKey('c', $data['ticket']);
        $this->assertSame(29, $data['ticket']['id']);
        $this->assertSame('ticket aberto', $data['ticket']['subject']);
    }

    /**
     * Regressão (2026-08-23, desenv): `GetInvoice`/`GetOrders` devolvem
     * `""` para lista vazia em vez de `[]`/`array()` — reproduzido em
     * `transactions`, `nameservers`, `renewals`, `products`. Só as chaves da allowlist
     * convertem; um campo de texto legitimamente vazio (`notes`) tem que
     * continuar string.
     */
    public function test_normalize_types_converts_empty_string_to_empty_list_only_on_allowlisted_keys(): void
    {
        $data = [
            'transactions' => '',
            'nameservers' => '',
            'renewals' => '',
            'products' => '',
            'notes' => '',
            'nested' => ['domains' => '', 'services' => '', 'addons' => ''],
        ];

        ResponseRedactor::normalizeTypes($data);

        $this->assertSame([], $data['transactions']);
        $this->assertSame([], $data['nameservers']);
        $this->assertSame([], $data['renewals']);
        $this->assertSame([], $data['products']);
        $this->assertSame('', $data['notes'], 'campo de texto livre nao pode virar lista');
        $this->assertSame([], $data['nested']['domains']);
        $this->assertSame([], $data['nested']['services']);
        $this->assertSame([], $data['nested']['addons']);
    }

    /** `0000-00-00` (com ou sem hora) vira `null` em QUALQUER chave — sentinela inequívoco. */
    public function test_normalize_types_converts_zero_date_to_null(): void
    {
        $data = [
            'datepaid' => '0000-00-00 00:00:00',
            'renewaldate' => '0000-00-00',
            'date' => '2026-07-06 12:51:06',
            'notes' => 'evento em 0000-00-00 mencionado no texto',
        ];

        ResponseRedactor::normalizeTypes($data);

        $this->assertNull($data['datepaid']);
        $this->assertNull($data['renewaldate']);
        $this->assertSame('2026-07-06 12:51:06', $data['date']);
        $this->assertSame(
            'evento em 0000-00-00 mencionado no texto',
            $data['notes'],
            'so o valor EXATO 0000-00-00 vira null, nao substring dentro de texto livre'
        );
    }

    /**
     * Regressão (2026-08-23, desenv): `GetOrders` embute `fraudoutput` — JSON
     * cru do MaxMind com geolocalização, 3+ KB por pedido. Cobre as duas
     * formas de payload: lista (`whmcs_list_orders`) e item único filtrado
     * por `id` (`whmcs_get_order`).
     */
    public function test_strip_order_fraud_dump_removes_it_from_order_list(): void
    {
        $result = [
            'orders' => ['order' => [
                ['id' => 70, 'fraudmodule' => 'maxmind', 'fraudoutput' => '{"billing_address":{"latitude":-15.7}}'],
                ['id' => 71, 'fraudmodule' => 'maxmind', 'fraudoutput' => '{"billing_address":{}}'],
            ]],
        ];

        ResponseRedactor::stripOrderFraudDump($result);

        foreach ($result['orders']['order'] as $order) {
            $this->assertArrayNotHasKey('fraudoutput', $order);
        }
        $this->assertSame('maxmind', $result['orders']['order'][0]['fraudmodule']);
        $this->assertSame(70, $result['orders']['order'][0]['id']);
    }

    public function test_strip_order_fraud_dump_removes_it_from_single_order(): void
    {
        $result = ['id' => 70, 'fraudmodule' => 'maxmind', 'fraudoutput' => '{"billing_address":{}}'];

        ResponseRedactor::stripOrderFraudDump($result);

        $this->assertArrayNotHasKey('fraudoutput', $result);
        $this->assertSame('maxmind', $result['fraudmodule']);
    }

    public function test_strip_client_details_removes_password_and_securityqans(): void
    {
        $result = [
            'firstname' => 'John',
            'lastname' => 'Doe',
            'password' => 'hashed-password',
            'securityqans' => 'answer',
        ];

        ResponseRedactor::stripClientDetails($result);

        $this->assertArrayNotHasKey('password', $result);
        $this->assertArrayNotHasKey('securityqans', $result);
        $this->assertSame('John', $result['firstname']);
        $this->assertSame('Doe', $result['lastname']);
    }

    /**
     * Regressão (2026-08-23, desenv): `GetClientsDetails` real devolvia 8889
     * chars, 66 chaves duplicadas em `client` (mesmos valores do topo, zero
     * diferença medida), `lastlogin` em HTML e `customfields1..15` repetindo
     * `customfields` sem id.
     */
    public function test_strip_client_details_removes_duplicated_nested_client_block(): void
    {
        $result = [
            'firstname' => 'Jane',
            'client' => ['firstname' => 'Jane', 'email' => 'jane@example.com'],
        ];

        ResponseRedactor::stripClientDetails($result);

        $this->assertArrayNotHasKey('client', $result);
        $this->assertSame('Jane', $result['firstname']);
    }

    public function test_strip_client_details_removes_numbered_customfields_duplicate(): void
    {
        $result = [
            'customfields' => [['id' => 171, 'value' => '050.334.171-18']],
            'customfields1' => '',
            'customfields6' => '050.334.171-18',
            'customfields15' => '',
        ];

        ResponseRedactor::stripClientDetails($result, null, [171]);

        for ($i = 1; $i <= 15; $i++) {
            $this->assertArrayNotHasKey('customfields' . $i, $result);
        }
        $this->assertSame([['id' => 171, 'value' => '050.334.171-18']], $result['customfields']);
    }

    public function test_strip_client_details_converts_lastlogin_html_to_plain_text(): void
    {
        $result = [
            'lastlogin' => 'Date: 17/08/2026 10:19<br>IP Address: 189.6.10.214<br>Host: bd060ad6.virtua.com.br',
        ];

        ResponseRedactor::stripClientDetails($result);

        // Convertido de HTML para text plano, e truncado ao primeiro '; '
        $this->assertSame(
            'Date: 17/08/2026 10:19',
            $result['lastlogin']
        );
        $this->assertStringNotContainsString('<br>', $result['lastlogin']);
        $this->assertStringNotContainsString('IP Address', $result['lastlogin']);
    }

    public function test_strip_client_details_preserves_lastlogin_when_format_is_unexpected(): void
    {
        $result = ['lastlogin' => 'Never'];

        ResponseRedactor::stripClientDetails($result);

        $this->assertSame('Never', $result['lastlogin']);
    }

    public function test_strip_client_details_truncates_lastlogin_at_first_semicolon(): void
    {
        $result = [
            'lastlogin' => 'Date: 17/08/2026 10:19<br>IP Address: 1.2.3.4<br>Host: example.com',
        ];

        ResponseRedactor::stripClientDetails($result);

        // Esperado: HTML convertido + truncado ao primeiro '; '
        $this->assertSame('Date: 17/08/2026 10:19', $result['lastlogin']);
        $this->assertStringNotContainsString('IP', $result['lastlogin']);
        $this->assertStringNotContainsString('Host', $result['lastlogin']);
    }

    public function test_strip_client_details_always_removes_cclastfour_and_cardlastfour(): void
    {
        $result = [
            'clientid' => 1,
            'cclastfour' => '4242',
            'cardlastfour' => '1111',
        ];

        ResponseRedactor::stripClientDetails($result);

        $this->assertArrayNotHasKey('cclastfour', $result);
        $this->assertArrayNotHasKey('cardlastfour', $result);
        $this->assertSame(1, $result['clientid']);
    }

    public function test_strip_client_details_filters_customfields_to_visible_ids(): void
    {
        $result = [
            'customfields' => [
                ['id' => 5, 'value' => 'field5'],
                ['id' => 9, 'value' => 'field9'],
                ['id' => 12, 'value' => 'field12'],
            ],
        ];

        ResponseRedactor::stripClientDetails($result, null, [5, 9]);

        $this->assertCount(2, $result['customfields']);
        $this->assertSame(5, $result['customfields'][0]['id']);
        $this->assertSame(9, $result['customfields'][1]['id']);
    }

    public function test_strip_client_details_with_no_visible_ids_empties_customfields(): void
    {
        $result = [
            'customfields' => [
                ['id' => 5, 'value' => 'field5'],
                ['id' => 9, 'value' => 'field9'],
            ],
        ];

        ResponseRedactor::stripClientDetails($result, null, []);

        $this->assertSame([], $result['customfields']);
    }

    public function test_client_lite_view_removes_sensitive_fields(): void
    {
        $result = [
            'clientid' => 1,
            'firstname' => 'John',
            'lastname' => 'Doe',
            'email' => 'john@example.com',
            'status' => 'active',
            'address1' => '123 Main St',
            'address2' => 'Apt 4B',
            'phonenumber' => '555-1234',
            'customfields' => [['id' => 5, 'value' => 'test']],
            'notes' => 'Important notes',
            'currency' => 'USD',
        ];

        ResponseRedactor::clientLiteView($result);

        // Deve remover todos os campos em CLIENT_LITE_DROP
        foreach (ResponseRedactor::CLIENT_LITE_DROP as $key) {
            $this->assertArrayNotHasKey($key, $result, "Campo $key deve ter sido removido");
        }

        // Deve manter os campos de identificação
        $this->assertSame(1, $result['clientid']);
        $this->assertSame('John', $result['firstname']);
        $this->assertSame('Doe', $result['lastname']);
        $this->assertSame('john@example.com', $result['email']);
        $this->assertSame('active', $result['status']);
        $this->assertSame('USD', $result['currency']);
    }

    // ---------------------------------------------------------------
    // #17 — GUARANTEED_LIST_KEYS: uma asserção por chave confirmada
    // ---------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function guaranteedListKeys(): array
    {
        return [
            'GetContacts'      => ['GetContacts', 'contacts'],
            'GetToDoItems'     => ['GetToDoItems', 'todoitems'],
            'GetClientGroups'  => ['GetClientGroups', 'groups'],
            'GetClientsAddons' => ['GetClientsAddons', 'addons'],
            'GetPromotions'    => ['GetPromotions', 'promotions'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guaranteedListKeys')]
    public function test_guaranteed_list_key_is_reinserted_as_empty_array(string $command, string $key): void
    {
        $data = ['result' => 'success', 'totalresults' => 0];
        ResponseRedactor::normalizeResponse($data, $command);
        $this->assertSame([], $data[$key], "$command deve garantir $key=[]");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guaranteedListKeys')]
    public function test_guaranteed_list_key_is_not_overwritten_when_present(string $command, string $key): void
    {
        $data = ['result' => 'success', $key => [['id' => 1]]];
        ResponseRedactor::normalizeResponse($data, $command);
        $this->assertSame([['id' => 1]], $data[$key]);
    }

    public function test_guaranteed_list_keys_are_command_scoped(): void
    {
        $data = ['result' => 'success'];
        ResponseRedactor::normalizeResponse($data, 'GetClientsDetails');
        foreach (['contacts', 'todoitems', 'groups', 'addons', 'promotions'] as $k) {
            $this->assertArrayNotHasKey($k, $data, 'chave garantida de outro comando não pode virar campo fantasma');
        }
    }

    public function test_guaranteed_list_keys_constant_matches_provider(): void
    {
        $const = (new \ReflectionClass(ResponseRedactor::class))->getReflectionConstant('GUARANTEED_LIST_KEYS')->getValue();
        $expected = [];
        foreach (self::guaranteedListKeys() as [$cmd, $key]) {
            $expected[$cmd][] = $key;
        }
        $this->assertSame($expected, $const, 'nova chave na constante exige caso no provider (e confirmação no payload real)');
    }
}
