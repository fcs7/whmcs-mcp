<?php

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\BillingTools;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

class BillingToolsTest extends TestCase
{
    private function makeTools(?callable $callable = null): BillingTools
    {
        $api = new LocalApiClient('testadmin');
        // Financial gate is disabled by default (WO-2); enable it here so these
        // tests can exercise BillingTools' param-building logic. The gate's
        // default-deny behavior itself is covered by LocalApiClientGateTest.
        $api->setGates(['financial' => true]);
        $api->setCallable($callable ?? function (string $cmd, array $params) {
            return ['result' => 'success', 'invoiceid' => 1];
        });
        return new BillingTools($api);
    }

    public function test_create_invoice_sends_duedate_and_paymentmethod(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success', 'invoiceid' => 1];
        });

        $tools->createInvoice(
            userid: 1,
            date: '2026-03-30',
            itemdescription: ['Service A'],
            itemamount: [100.0],
            duedate: '2026-04-30',
            paymentmethod: 'banktransfer'
        );

        $this->assertSame('2026-04-30', $capturedParams['duedate']);
        $this->assertSame('banktransfer', $capturedParams['paymentmethod']);
    }

    public function test_create_invoice_sends_itemtaxed_indexed(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success', 'invoiceid' => 1];
        });

        $tools->createInvoice(
            userid: 1,
            date: '2026-03-30',
            itemdescription: ['Item A', 'Item B'],
            itemamount: [50.0, 75.0],
            itemtaxed: [true, false]
        );

        $this->assertSame(1, $capturedParams['itemtaxed[0]']);
        $this->assertSame(0, $capturedParams['itemtaxed[1]']);
    }

    public function test_create_invoice_sends_draft_when_true(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success', 'invoiceid' => 1];
        });

        $tools->createInvoice(
            userid: 1,
            date: '2026-03-30',
            itemdescription: ['Item A'],
            itemamount: [100.0],
            draft: true
        );

        $this->assertTrue($capturedParams['draft']);
    }

    public function test_update_invoice_sends_credit_and_notes(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->updateInvoice(invoiceid: 5, credit: 25.50, notes: 'Partial credit applied');

        $this->assertSame(25.50, $capturedParams['credit']);
        $this->assertSame('Partial credit applied', $capturedParams['notes']);
    }

    public function test_update_invoice_sends_credit_zero(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->updateInvoice(invoiceid: 5, credit: 0.0);

        $this->assertArrayHasKey('credit', $capturedParams);
        $this->assertSame(0.0, $capturedParams['credit']);
    }

    public function test_update_invoice_omits_credit_when_null(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->updateInvoice(invoiceid: 5);

        $this->assertArrayNotHasKey('credit', $capturedParams);
    }

    public function test_add_payment_sends_negative_fees(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->addPayment(
            invoiceid: 10,
            transid: 'TX999',
            amount: 100.0,
            gateway: 'banktransfer',
            fees: -5.0
        );

        $this->assertArrayHasKey('fees', $capturedParams);
        $this->assertSame(-5.0, $capturedParams['fees']);
    }

    public function test_add_payment_sends_date_and_noemail(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->addPayment(
            invoiceid: 10,
            transid: 'TX123',
            amount: 200.0,
            gateway: 'banktransfer',
            date: '2026-03-30',
            noemail: true
        );

        $this->assertSame('2026-03-30', $capturedParams['date']);
        $this->assertTrue($capturedParams['noemail']);
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
