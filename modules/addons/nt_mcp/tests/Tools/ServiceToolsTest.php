<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\ServiceTools;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

class ServiceToolsTest extends TestCase
{
    private function makeTools(?callable $callable = null): ServiceTools
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable($callable ?? function (string $cmd, array $params) {
            return ['result' => 'success'];
        });
        return new ServiceTools($api);
    }

    public function test_list_services_strips_password_from_products(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return [
                'result' => 'success',
                'products' => [
                    'product' => [
                        ['id' => 1, 'name' => 'Hosting', 'password' => 's3cr3t'],
                        ['id' => 2, 'name' => 'VPS',     'password' => 'hunter2'],
                    ],
                ],
            ];
        });

        $json = $tools->listServices(42);
        $data = json_decode($json, true);

        $this->assertArrayNotHasKey('password', $data['products']['product'][0]);
        $this->assertArrayNotHasKey('password', $data['products']['product'][1]);
        $this->assertSame('Hosting', $data['products']['product'][0]['name']);
    }

    public function test_list_services_no_password_field_survives_intact(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return [
                'result' => 'success',
                'products' => [
                    'product' => [
                        ['id' => 1, 'name' => 'Hosting'],
                    ],
                ],
            ];
        });

        $json = $tools->listServices(42);
        $data = json_decode($json, true);

        $this->assertSame('Hosting', $data['products']['product'][0]['name']);
    }

    public function test_list_services_empty_products_does_not_error(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return ['result' => 'success', 'products' => []];
        });

        $json = $tools->listServices(42);
        $data = json_decode($json, true);

        $this->assertSame('success', $data['result']);
    }

    public function test_list_services_sends_clientid_and_limitnum(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = ['cmd' => $cmd, 'params' => $params];
            return ['result' => 'success'];
        });

        $tools->listServices(clientid: 7, limitnum: 10);

        $this->assertSame('GetClientsProducts', $capturedParams['cmd']);
        $this->assertSame(7, $capturedParams['params']['clientid']);
        $this->assertSame(10, $capturedParams['params']['limitnum']);
    }

    public function test_list_services_sends_optional_status(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->listServices(clientid: 1, status: 'Active');

        $this->assertSame('Active', $capturedParams['status']);
    }
}
