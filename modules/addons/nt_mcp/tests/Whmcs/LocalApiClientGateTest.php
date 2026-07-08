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

    public function test_every_allowed_command_has_an_explicit_security_class(): void
    {
        $reflection = new \ReflectionClass(LocalApiClient::class);
        $allowed = $reflection->getReflectionConstant('ALLOWED_COMMANDS')->getValue();
        $classified = array_keys(
            $reflection->getReflectionConstant('COMMAND_CLASS')->getValue()
        );

        sort($allowed);
        sort($classified);

        $this->assertSame($allowed, $classified);
    }

    public function test_unclassified_command_fails_closed(): void
    {
        $client = new LocalApiClient('testadmin');
        $method = new \ReflectionMethod(LocalApiClient::class, 'classOf');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has no explicit security classification');

        $method->invoke($client, 'FutureUnclassifiedCommand');
    }

    public function test_read_command_always_passes_regardless_of_gates(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['write' => false, 'destructive' => false, 'financial' => false, 'readonly' => true]);
        $client->setCallable(fn() => ['result' => 'success']);

        $result = $client->call('GetClients', []);

        $this->assertSame('success', $result['result']);
    }

    // Nota: os comandos destrutivos/financeiros de client/order/invoice
    // (CloseClient, ModuleTerminate, DeleteOrder, CreateInvoice, AddCredit...)
    // foram REMOVIDOS do allowlist — não apenas gated. Os testes de gate abaixo
    // usam os únicos comandos remanescentes de cada classe: DeleteProjectTask
    // (DESTRUCTIVE) e AcceptQuote (FINANCIAL). A remoção física é garantida pelo
    // regression guard em test_removed_*_rejected_by_allowlist.

    public function test_delete_project_task_blocked_when_destructive_gate_off(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['destructive' => false]);
        $client->setCallable(fn() => ['result' => 'success']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked');
        $client->call('DeleteProjectTask', ['projectid' => 1, 'taskid' => 1]);
    }

    public function test_delete_project_task_allowed_when_destructive_gate_on(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['destructive' => true]);
        $client->setCallable(fn() => ['result' => 'success']);

        $result = $client->call('DeleteProjectTask', ['projectid' => 1, 'taskid' => 1]);

        $this->assertSame('success', $result['result']);
    }

    public function test_accept_quote_blocked_by_default_financial_gate(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates([]); // financial off by default
        $client->setCallable(fn() => ['result' => 'success']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked');
        $client->call('AcceptQuote', ['quoteid' => 1]);
    }

    /**
     * Regression guard: as 10 tools destrutivas/financeiras foram REMOVIDAS
     * fisicamente do allowlist. Mesmo com todos os gates habilitados, o
     * allowlist rejeita esses comandos antes de qualquer classificação —
     * é a defesa que teria pego o merge incoerente que reintroduziu o gate.
     */
    public function test_removed_destructive_financial_commands_rejected_by_allowlist_even_with_gates_on(): void
    {
        $removed = [
            'CloseClient', 'ModuleTerminate', 'DeleteOrder', 'CreateInvoice',
            'AddInvoicePayment', 'UpdateInvoice', 'AddCredit', 'AddTransaction',
            'UpdateTransaction', 'AddBillableItem',
        ];

        foreach ($removed as $cmd) {
            $client = new LocalApiClient('testadmin');
            $client->setGates(['write' => true, 'destructive' => true, 'financial' => true, 'cost' => true, 'comms' => true]);
            $client->setCallable(fn() => ['result' => 'success']);

            try {
                $client->call($cmd, []);
                $this->fail("Comando removido '{$cmd}' não deveria ser aceito pelo allowlist");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('not in the allowed list', $e->getMessage());
            }
        }
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
        $this->assertArrayNotHasKey('adminusername', $captured);
    }

    public function test_update_project_task_clamps_adminid_via_resolver(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setAdminIdResolver(fn(string $username) => 42);
        $captured = null;
        $client->setCallable(function (string $cmd, array $params) use (&$captured) {
            $captured = $params;
            return ['result' => 'success'];
        });

        $client->call('UpdateProjectTask', ['taskid' => 5, 'adminid' => 999]);

        $this->assertSame(42, $captured['adminid']);
        $this->assertArrayNotHasKey('adminusername', $captured);
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
