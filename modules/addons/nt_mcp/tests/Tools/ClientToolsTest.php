<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\ClientTools;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

class ClientToolsTest extends TestCase
{
    private function makeTools(?callable $callable = null): ClientTools
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable($callable ?? function (string $cmd, array $params) {
            return ['result' => 'success'];
        });
        return new ClientTools($api);
    }

    public function test_get_client_strips_password_and_securityqans(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return [
                'result' => 'success',
                'clientid' => 1,
                'firstname' => 'John',
                'password' => 'hash-of-secret',
                'securityqans' => 'my-answer',
            ];
        });

        $json = $tools->getClient(1);
        $data = json_decode($json, true);

        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('securityqans', $data);
        $this->assertSame('John', $data['firstname']);
    }

    public function test_get_client_no_password_field_survives_intact(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return ['result' => 'success', 'clientid' => 1, 'firstname' => 'Jane'];
        });

        $json = $tools->getClient(1);
        $data = json_decode($json, true);

        $this->assertSame('Jane', $data['firstname']);
    }

    public function test_get_client_sends_clientid_and_stats(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = ['cmd' => $cmd, 'params' => $params];
            return ['result' => 'success'];
        });

        $tools->getClient(7);

        $this->assertSame('GetClientsDetails', $capturedParams['cmd']);
        $this->assertSame(7, $capturedParams['params']['clientid']);
        $this->assertTrue($capturedParams['params']['stats']);
    }

    public function test_get_client_products_strips_password_from_products(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return [
                'result' => 'success',
                'products' => [
                    'product' => [
                        ['id' => 1, 'name' => 'Hosting', 'password' => 's3cr3t'],
                    ],
                ],
            ];
        });

        $json = $tools->getClientProducts(1);
        $data = json_decode($json, true);

        $this->assertArrayNotHasKey('password', $data['products']['product'][0]);
        $this->assertSame('Hosting', $data['products']['product'][0]['name']);
    }
}
