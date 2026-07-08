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

    /**
     * Regression guard: 23 comandos de risco foram REMOVIDOS fisicamente do
     * allowlist (10 destrutivos/financeiros no corte de 2026-04 + 13 no corte
     * de 2026-07: suspend/unsuspend/upgrade de serviço, registro/renovação/
     * alteração de domínio, envio de e-mail/orçamento, aceite de orçamento/
     * pedido, criação de pedido, deleção de tarefa). Mesmo com todos os gates
     * habilitados, o allowlist rejeita esses comandos antes de qualquer
     * classificação.
     */
    public function test_removed_commands_rejected_by_allowlist_even_with_gates_on(): void
    {
        $removed = [
            // corte 2026-04 (10 destrutivas/financeiras)
            'CloseClient', 'ModuleTerminate', 'DeleteOrder', 'CreateInvoice',
            'AddInvoicePayment', 'UpdateInvoice', 'AddCredit', 'AddTransaction',
            'UpdateTransaction', 'AddBillableItem',
            // corte 2026-07 (13 tools de risco)
            'ModuleSuspend', 'ModuleUnsuspend', 'UpgradeProduct',
            'DomainRegister', 'DomainRenew', 'DomainUpdateNameservers',
            'UpdateClientDomain', 'SendEmail', 'SendQuote', 'AcceptQuote',
            'AcceptOrder', 'AddOrder', 'DeleteProjectTask',
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
