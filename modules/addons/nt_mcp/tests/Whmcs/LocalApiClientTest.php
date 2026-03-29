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

    public function test_call_returns_error_response(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setCallable(function () {
            return ['result' => 'error', 'message' => 'Client not found'];
        });

        $result = $client->call('GetClientsDetails', ['clientid' => 999]);
        $this->assertEquals('error', $result['result']);
        $this->assertEquals('Client not found', $result['message']);
    }

    public function test_call_rejects_unlisted_command(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setCallable(function () {
            return ['result' => 'success'];
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not in the allowed list');
        $client->call('AddAdmin', ['username' => 'hacker']);
    }
}
