<?php
// tests/Whmcs/LocalApiClientGateTest.php
namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

class LocalApiClientGateTest extends TestCase
{
    // ---------------------------------------------------------------
    // A) Gate de classe
    // ---------------------------------------------------------------

    public function test_read_command_always_passes_regardless_of_gates(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['write' => false, 'destructive' => false, 'financial' => false, 'readonly' => true]);
        $client->setCallable(fn() => ['result' => 'success']);

        $result = $client->call('GetClients', []);

        $this->assertSame('success', $result['result']);
    }

    public function test_module_terminate_blocked_when_destructive_gate_off(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['destructive' => false]);
        $client->setCallable(fn() => ['result' => 'success']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked');
        $client->call('ModuleTerminate', ['serviceid' => 1]);
    }

    public function test_module_terminate_allowed_when_destructive_gate_on(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['destructive' => true]);
        $client->setCallable(fn() => ['result' => 'success']);

        $result = $client->call('ModuleTerminate', ['serviceid' => 1]);

        $this->assertSame('success', $result['result']);
    }

    public function test_add_credit_blocked_by_default_financial_gate(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates([]); // financial off by default
        $client->setCallable(fn() => ['result' => 'success']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked');
        $client->call('AddCredit', ['clientid' => 1, 'amount' => 10]);
    }

    public function test_add_client_write_allowed_by_default(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setCallable(fn() => ['result' => 'success']);

        $result = $client->call('AddClient', ['firstname' => 'a']);

        $this->assertSame('success', $result['result']);
    }

    public function test_add_client_blocked_by_readonly_master_switch(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['readonly' => true]);
        $client->setCallable(fn() => ['result' => 'success']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked');
        $client->call('AddClient', ['firstname' => 'a']);
    }

    // ---------------------------------------------------------------
    // B) Clamp de impersonacao
    // ---------------------------------------------------------------

    public function test_add_ticket_reply_clamps_adminusername_to_token_admin(): void
    {
        $client = new LocalApiClient('testadmin');
        $captured = null;
        $client->setCallable(function (string $cmd, array $params) use (&$captured) {
            $captured = $params;
            return ['result' => 'success'];
        });

        $client->call('AddTicketReply', ['ticketid' => 1, 'adminusername' => 'ghost']);

        $this->assertSame('testadmin', $captured['adminusername']);
        $this->assertArrayNotHasKey('adminid', $captured);
    }

    public function test_create_project_clamps_adminid_via_resolver(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setAdminIdResolver(fn(string $username) => 7);
        $captured = null;
        $client->setCallable(function (string $cmd, array $params) use (&$captured) {
            $captured = $params;
            return ['result' => 'success'];
        });

        $client->call('CreateProject', ['name' => 'Project X', 'adminid' => 999]);

        $this->assertSame(7, $captured['adminid']);
        $this->assertArrayNotHasKey('adminusername', $captured);
    }

    public function test_update_project_clamps_adminid_via_resolver(): void
    {
        // UpdateProject/UpdateProjectTask also accept a caller-supplied adminid
        // and must be clamped to the token-bound admin.
        $client = new LocalApiClient('testadmin');
        $client->setAdminIdResolver(fn(string $username) => 42);
        $captured = null;
        $client->setCallable(function (string $cmd, array $params) use (&$captured) {
            $captured = $params;
            return ['result' => 'success'];
        });

        $client->call('UpdateProject', ['projectid' => 5, 'adminid' => 999]);

        $this->assertSame(42, $captured['adminid']);
    }

    // ---------------------------------------------------------------
    // D-server) Scrub de resposta
    // ---------------------------------------------------------------

    public function test_call_scrubs_sensitive_keys_from_response(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setCallable(fn() => [
            'result' => 'success',
            'password' => 'x',
            'a' => ['securityqans' => 'y'],
        ]);

        $result = $client->call('GetClients', []);

        $this->assertArrayNotHasKey('password', $result);
        $this->assertArrayNotHasKey('securityqans', $result['a']);
    }
}
