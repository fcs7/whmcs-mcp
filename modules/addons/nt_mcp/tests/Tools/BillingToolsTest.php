<?php

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\BillingTools;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

/**
 * BillingTools expõe apenas 5 tools READ após a remoção das ferramentas
 * financeiras (create_invoice, add_payment, update_invoice, add_credit,
 * add_transaction, update_transaction, add_billable_item). Os únicos testes
 * relevantes cobrem a redação de dados sensíveis em GetPayMethods.
 */
class BillingToolsTest extends TestCase
{
    private function makeTools(?callable $callable = null): BillingTools
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable($callable ?? function (string $cmd, array $params) {
            return ['result' => 'success'];
        });
        return new BillingTools($api);
    }

    public function test_get_pay_methods_strips_sensitive_fields(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return [
                'result' => 'success',
                'paymethods' => [
                    [
                        'id' => 1,
                        'type' => 'RemoteCreditCard',
                        'description' => 'Visa ending 1234',
                        'card_last_four' => '1234',
                        'expiry_date' => '12/30',
                        'card_number' => '4111111111111234',
                        'remote_token' => '{"customer":"cus_xyz"}',
                        'token' => 'tok_abc123',
                        'gateway_customer_id' => 'cus_xyz789',
                        'account_number' => '000123456',
                        'routing_number' => '021000021',
                    ],
                ],
            ];
        });

        $json = $tools->getPayMethods(1);
        $data = json_decode($json, true);

        $pm = $data['paymethods'][0];
        $this->assertArrayNotHasKey('card_number', $pm);
        $this->assertArrayNotHasKey('remote_token', $pm);
        $this->assertArrayNotHasKey('token', $pm);
        $this->assertArrayNotHasKey('gateway_customer_id', $pm);
        $this->assertArrayNotHasKey('account_number', $pm);
        $this->assertArrayNotHasKey('routing_number', $pm);
        $this->assertSame(1, $pm['id']);
        $this->assertSame('RemoteCreditCard', $pm['type']);
        $this->assertSame('1234', $pm['card_last_four']);
        $this->assertSame('12/30', $pm['expiry_date']);
    }

    public function test_get_pay_methods_no_paymethods_field_does_not_error(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return ['result' => 'success'];
        });

        $json = $tools->getPayMethods(1);
        $data = json_decode($json, true);

        $this->assertSame('success', $data['result']);
    }
}
