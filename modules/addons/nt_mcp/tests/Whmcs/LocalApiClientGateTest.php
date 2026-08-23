<?php
// tests/Whmcs/LocalApiClientGateTest.php
namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // Nota: os comandos destrutivos/financeiros/de custo de client/order/invoice
    // (CloseClient, ModuleTerminate, DeleteOrder, CreateInvoice, AddCredit,
    // DomainRegister, AddOrder...) foram REMOVIDOS do allowlist — não apenas
    // gated. Os testes abaixo usam os comandos remanescentes de cada classe:
    // CancelOrder/DeleteQuote (DESTRUCTIVE) e AcceptQuote/UpdateInvoice
    // (FINANCIAL). A remoção física é garantida pelos regression guards em
    // test_removed_*_rejected_by_allowlist.

    // ---------------------------------------------------------------
    // A1) Defaults: TODA classe não-READ nasce desligada.
    // ---------------------------------------------------------------

    #[DataProvider('nonReadCommandProvider')]
    public function test_every_non_read_class_is_disabled_by_default(string $command, string $class): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates([]); // nenhum override ⇒ defaults de produção
        $client->setCallable(fn() => ['result' => 'success']);

        try {
            $client->call($command, []);
            $this->fail("'{$command}' ({$class}) deveria estar bloqueado por default");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("class {$class} disabled", $e->getMessage());
        }
    }

    public static function nonReadCommandProvider(): array
    {
        return [
            'WRITE'              => ['AddClient', 'WRITE'],
            'WRITE (ticket)'     => ['UpdateTicket', 'WRITE'],
            'WRITE (quote)'      => ['CreateQuote', 'WRITE'],
            'DESTRUCTIVE order'  => ['CancelOrder', 'DESTRUCTIVE'],
            'DESTRUCTIVE quote'  => ['DeleteQuote', 'DESTRUCTIVE'],
            'FINANCIAL accept'   => ['AcceptQuote', 'FINANCIAL'],
            'FINANCIAL invoice'  => ['UpdateInvoice', 'FINANCIAL'],
        ];
    }

    /**
     * O master switch read-only bloqueia QUALQUER mutação, mesmo com todos os
     * gates de classe explicitamente ligados.
     */
    #[DataProvider('nonReadCommandProvider')]
    public function test_readonly_master_switch_blocks_every_mutation(string $command, string $class): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates([
            'readonly' => true,
            'write' => true, 'destructive' => true, 'financial' => true,
            'cost' => true, 'comms' => true,
        ]);
        $client->setCallable(fn() => ['result' => 'success']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked');
        $client->call($command, []);
    }

    // ---------------------------------------------------------------
    // A2) Gate ligado libera — por classe.
    // ---------------------------------------------------------------

    public function test_cancel_order_allowed_when_destructive_gate_on(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['destructive' => true]);
        $client->setCallable(fn() => ['result' => 'success']);

        $result = $client->call('CancelOrder', ['orderid' => 1, 'noemail' => true]);

        $this->assertSame('success', $result['result']);
    }

    public function test_delete_quote_allowed_when_destructive_gate_on(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['destructive' => true]);
        $client->setCallable(fn() => ['result' => 'success']);

        $result = $client->call('DeleteQuote', ['quoteid' => 1]);

        $this->assertSame('success', $result['result']);
    }

    public function test_financial_commands_allowed_when_financial_gate_on(): void
    {
        foreach (['AcceptQuote', 'UpdateInvoice'] as $command) {
            $client = new LocalApiClient('testadmin');
            $client->setGates(['financial' => true]);
            $client->setCallable(fn() => ['result' => 'success']);

            $result = $client->call($command, []);

            $this->assertSame('success', $result['result'], $command);
        }
    }

    /** Habilitar WRITE NÃO habilita DESTRUCTIVE nem FINANCIAL. */
    public function test_write_gate_does_not_unlock_destructive_or_financial(): void
    {
        foreach (['CancelOrder' => 'DESTRUCTIVE', 'DeleteQuote' => 'DESTRUCTIVE', 'AcceptQuote' => 'FINANCIAL', 'UpdateInvoice' => 'FINANCIAL'] as $command => $class) {
            $client = new LocalApiClient('testadmin');
            $client->setGates(['write' => true]);
            $client->setCallable(fn() => ['result' => 'success']);

            try {
                $client->call($command, []);
                $this->fail("'{$command}' não deveria ser liberado apenas pelo gate WRITE");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString("class {$class} disabled", $e->getMessage());
            }
        }
    }

    // ---------------------------------------------------------------
    // A3) COMMS ortogonal, verificado no ponto CENTRAL de autorização.
    // ---------------------------------------------------------------

    /**
     * Chamada DIRETA ao LocalApiClient (sem passar pela tool) que omita
     * 'noemail' também exige COMMS — é isso que impede o bypass.
     */
    #[DataProvider('notifyingCommandProvider')]
    public function test_notifying_command_without_noemail_requires_comms_gate(string $command): void
    {
        $called = false;
        $client = new LocalApiClient('testadmin');
        $client->setGates(['write' => true, 'comms' => false]);
        $client->setCallable(function () use (&$called) {
            $called = true;
            return ['result' => 'success'];
        });

        try {
            $client->call($command, ['ticketid' => 1]);
            $this->fail("'{$command}' sem noemail deveria exigir o gate COMMS");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('COMMS gate', $e->getMessage());
        }

        $this->assertFalse($called, 'bloqueio deve ocorrer ANTES da localAPI');
    }

    #[DataProvider('notifyingCommandProvider')]
    public function test_notifying_command_with_noemail_true_does_not_require_comms(string $command): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['write' => true, 'comms' => false]);
        $client->setCallable(fn() => ['result' => 'success']);

        $result = $client->call($command, ['ticketid' => 1, 'noemail' => true]);

        $this->assertSame('success', $result['result']);
    }

    #[DataProvider('notifyingCommandProvider')]
    public function test_notifying_command_allowed_when_comms_gate_on(string $command): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['write' => true, 'comms' => true]);
        $client->setCallable(fn() => ['result' => 'success']);

        $result = $client->call($command, ['ticketid' => 1]);

        $this->assertSame('success', $result['result']);
    }

    /** COMMS ligado não substitui a classe primária. */
    public function test_comms_gate_alone_does_not_unlock_write(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['write' => false, 'comms' => true]);
        $client->setCallable(fn() => ['result' => 'success']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('class WRITE disabled');
        $client->call('AddClient', ['firstname' => 'a']);
    }

    public static function notifyingCommandProvider(): array
    {
        return [
            'AddClient'      => ['AddClient'],
            'OpenTicket'     => ['OpenTicket'],
            'AddTicketReply' => ['AddTicketReply'],
        ];
    }

    // ---------------------------------------------------------------
    // A4) Regression guards de remoção física do allowlist.
    // ---------------------------------------------------------------

    /**
     * Regression guard: comandos destrutivos/financeiros de client/order/invoice
     * foram REMOVIDOS fisicamente do allowlist. Mesmo com todos os gates
     * habilitados, o allowlist rejeita esses comandos antes de qualquer
     * classificação — é a defesa que teria pego o merge incoerente que
     * reintroduziu o gate.
     *
     * `UpdateInvoice` saiu desta lista no T1: agora está no allowlist,
     * classificado como FINANCIAL (segundo passo da conversão de cotação).
     */
    public function test_removed_destructive_financial_commands_rejected_by_allowlist_even_with_gates_on(): void
    {
        $removed = [
            'CloseClient', 'ModuleTerminate', 'DeleteOrder', 'CreateInvoice',
            'AddInvoicePayment', 'AddCredit', 'AddTransaction',
            'UpdateTransaction', 'AddBillableItem',
        ];

        $this->assertAllRejectedByAllowlist($removed);
    }

    /**
     * Regression guard do T1: os 24 comandos que deixaram de ser necessários
     * quando a superfície caiu de 86 para 64 tools saíram do allowlist. Nenhum
     * gate os traz de volta.
     */
    public function test_commands_dropped_by_the_66_tool_surface_are_rejected_by_allowlist(): void
    {
        $removed = [
            // custo / provisionamento
            'ModuleSuspend', 'ModuleUnsuspend', 'UpgradeProduct',
            'AcceptOrder', 'AddOrder',
            'DomainRegister', 'DomainRenew', 'DomainUpdateNameservers', 'UpdateClientDomain',
            // comunicação externa
            'SendEmail', 'SendQuote',
            // destrutivo fora da exceção decidida
            'DeleteProjectTask',
            // lookups auxiliares retirados — `GetProducts`, `GetCurrencies`,
            // `GetOrderStatuses` e `GetPromotions` SAÍRAM desta lista em
            // 2026-08-23: reintroduzidas como tools de leitura
            // (`whmcs_get_products`, `whmcs_get_currencies`,
            // `whmcs_get_order_statuses`, `whmcs_get_promotions`) — eram tools
            // documentadas e nunca implementadas.
            'GetEmailTemplates', 'GetPaymentMethods',
            'GetToDoItemStatuses', 'LogActivity',
            'GetTicketNotes', 'GetTicketPredefinedCats',
            'GetTicketPredefinedReplies', 'GetTicketAttachment',
        ];

        $this->assertCount(20, $removed);
        $this->assertAllRejectedByAllowlist($removed);
    }

    /** @param array<string> $commands */
    private function assertAllRejectedByAllowlist(array $commands): void
    {
        foreach ($commands as $cmd) {
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

    // ---------------------------------------------------------------
    // B) Clamp de impersonacao
    // ---------------------------------------------------------------

    public function test_add_ticket_reply_clamps_adminusername_to_token_admin(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['write' => true]);
        $captured = null;
        $client->setCallable(function (string $cmd, array $params) use (&$captured) {
            $captured = $params;
            return ['result' => 'success'];
        });

        $client->call('AddTicketReply', ['ticketid' => 1, 'adminusername' => 'ghost', 'noemail' => true]);

        $this->assertSame('testadmin', $captured['adminusername']);
        $this->assertArrayNotHasKey('adminid', $captured);
    }

    public function test_create_project_clamps_adminid_via_resolver(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['write' => true]);
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
        $client->setGates(['write' => true]);
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
        $client->setGates(['write' => true]);
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
