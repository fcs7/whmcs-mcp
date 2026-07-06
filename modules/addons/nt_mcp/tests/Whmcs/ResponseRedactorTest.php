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
