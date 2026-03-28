<?php
// tests/Whmcs/LocalApiClientTest.php
namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

class LocalApiClientTest extends TestCase
{
    public function test_call_returns_result_on_success(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setCallable(function (string $cmd, array $params) {
            return ['result' => 'success', 'numreturns' => 1];
        });

        $result = $client->call('GetClients', []);
        $this->assertEquals('success', $result['result']);
    }

    public function test_call_throws_on_error(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setCallable(function () {
            return ['result' => 'error', 'message' => 'Client not found'];
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Client not found');
        $client->call('GetClientsDetails', ['clientid' => 999]);
    }
}
