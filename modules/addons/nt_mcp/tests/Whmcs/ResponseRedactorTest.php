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
            'client' => [
                'firstname' => 'Jane',
                'password' => 'hash-should-not-leak',
                'nested' => [
                    'securityqans' => 'my-answer',
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
        $this->assertArrayNotHasKey('password2', $data['client']['nested']['deeper']);
        $this->assertSame('keep-me', $data['client']['nested']['deeper']['ok']);
        $this->assertSame('Jane', $data['client']['firstname']);
    }

    public function test_strip_pay_methods_keeps_only_allowlisted_keys(): void
    {
        $result = [
            'paymethods' => [
                [
                    'id' => 1,
                    'payment_method_type' => 'creditcard',
                    'description' => 'Visa ending in 1234',
                    'is_default' => true,
                    'created_at' => '2026-01-01',
                    'updated_at' => '2026-02-01',
                    'card_number' => '4111111111111111',
                    'expiry' => '12/30',
                    'account_number' => '000123456',
                    'routing_number' => '021000021',
                    'token' => 'tok_secret',
                    'gateway_customer_id' => 'cus_secret',
                ],
            ],
        ];

        ResponseRedactor::stripPayMethods($result);

        $pm = $result['paymethods'][0];

        $this->assertArrayNotHasKey('card_number', $pm);
        $this->assertArrayNotHasKey('expiry', $pm);
        $this->assertArrayNotHasKey('account_number', $pm);
        $this->assertArrayNotHasKey('routing_number', $pm);
        $this->assertArrayNotHasKey('token', $pm);
        $this->assertArrayNotHasKey('gateway_customer_id', $pm);

        $this->assertSame(1, $pm['id']);
        $this->assertSame('creditcard', $pm['payment_method_type']);
        $this->assertSame('Visa ending in 1234', $pm['description']);
        $this->assertTrue($pm['is_default']);
        $this->assertSame('2026-01-01', $pm['created_at']);
        $this->assertSame('2026-02-01', $pm['updated_at']);
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
}
