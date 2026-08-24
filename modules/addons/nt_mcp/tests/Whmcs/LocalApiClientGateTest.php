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

    // ---------------------------------------------------------------
    // #14) Gate por alvo — allowlist de clientid/ticketid
    // ---------------------------------------------------------------

    /** @param array<string,mixed> $lists */
    private function targetClient(array $lists, ?callable $fn = null, array &$cmds = []): LocalApiClient
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates(['write' => true, 'destructive' => true, 'financial' => true] + $lists);
        $client->setCallable(function (string $cmd, array $params) use ($fn, &$cmds) {
            $cmds[] = $cmd;
            return $fn ? $fn($cmd, $params) : ['result' => 'success'];
        });
        return $client;
    }

    private function assertTargetDenied(callable $fn, string $target): void
    {
        try {
            $fn();
            $this->fail('deveria negar por alvo');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('write_target_not_allowed', $e->getMessage());
            $this->assertStringContainsString($target, $e->getMessage());
        }
    }

    public function test_target_allowlist_unconfigured_keeps_current_behaviour(): void
    {
        $c = $this->targetClient([]);
        $this->assertSame('success', $c->call('UpdateClient', ['clientid' => 999])['result']);
        $this->assertSame('success', $c->call('UpdateTicket', ['ticketid' => 999])['result']);
    }

    public function test_client_allowlist_allows_inside_and_denies_outside(): void
    {
        $c = $this->targetClient(['allowlist_clientids' => [5, 7]]);
        $this->assertSame('success', $c->call('UpdateClient', ['clientid' => 5])['result']);
        $this->assertSame('success', $c->call('UpdateClient', ['clientid' => '7'])['result']);
        $this->assertTargetDenied(fn() => $c->call('UpdateClient', ['clientid' => 6]), 'clientid');
        // `userid` também é alvo de cliente
        $this->assertTargetDenied(fn() => $c->call('UpdateClient', ['userid' => 6]), 'clientid');
    }

    public function test_client_allowlist_accepts_csv_string_override(): void
    {
        $c = $this->targetClient(['allowlist_clientids' => ' 5, 7 ']);
        $this->assertSame('success', $c->call('UpdateClient', ['clientid' => 7])['result']);
        $this->assertTargetDenied(fn() => $c->call('UpdateClient', ['clientid' => 8]), 'clientid');
    }

    public function test_invalid_csv_token_fails_closed_for_every_target(): void
    {
        $c = $this->targetClient(['allowlist_clientids' => '5,abc']);
        $this->assertTargetDenied(fn() => $c->call('UpdateClient', ['clientid' => 5]), 'clientid');
    }

    public function test_ticket_allowlist_allows_inside_and_denies_outside(): void
    {
        $c = $this->targetClient(['allowlist_ticketids' => [10]]);
        $this->assertSame('success', $c->call('AddTicketReply', ['ticketid' => 10, 'message' => 'x', 'noemail' => true])['result']);
        $this->assertTargetDenied(fn() => $c->call('UpdateTicket', ['ticketid' => 11]), 'ticketid');
    }

    public function test_client_allowlist_resolves_ticket_to_client_before_gate(): void
    {
        $cmds = [];
        $fn = fn(string $cmd, array $p) => $cmd === 'GetTicket'
            ? ['result' => 'success', 'ticketid' => $p['ticketid'], 'userid' => $p['ticketid'] === 10 ? 5 : 99]
            : ['result' => 'success'];
        $c = $this->targetClient(['allowlist_clientids' => [5]], $fn, $cmds);

        $this->assertSame('success', $c->call('UpdateTicket', ['ticketid' => 10])['result']);
        $this->assertSame(['GetTicket', 'UpdateTicket'], $cmds, 'GetTicket precede o write');

        $cmds = [];
        $this->assertTargetDenied(fn() => $c->call('UpdateTicket', ['ticketid' => 11]), 'clientid');
        $this->assertSame(['GetTicket'], $cmds, 'write nunca chegou à API');
    }

    public function test_client_allowlist_denies_when_ticket_cannot_be_resolved(): void
    {
        $fn = fn(string $cmd) => $cmd === 'GetTicket'
            ? ['result' => 'error', 'message' => 'Ticket ID Not Found']
            : ['result' => 'success'];
        $c = $this->targetClient(['allowlist_clientids' => [5]], $fn);
        $this->assertTargetDenied(fn() => $c->call('UpdateTicket', ['ticketid' => 404]), 'ticketid');
    }

    public function test_client_allowlist_skips_client_check_for_guest_ticket_but_ticket_list_still_applies(): void
    {
        $fn = fn(string $cmd) => $cmd === 'GetTicket'
            ? ['result' => 'success', 'userid' => 0]
            : ['result' => 'success'];
        $c = $this->targetClient(['allowlist_clientids' => [5]], $fn);
        $this->assertSame('success', $c->call('UpdateTicket', ['ticketid' => 3])['result']);

        $both = $this->targetClient(['allowlist_clientids' => [5], 'allowlist_ticketids' => [1]], $fn);
        $this->assertTargetDenied(fn() => $both->call('UpdateTicket', ['ticketid' => 3]), 'ticketid');
    }

    public function test_explicit_clientid_param_wins_over_ticket_lookup(): void
    {
        $cmds = [];
        $c = $this->targetClient(['allowlist_clientids' => [5]], null, $cmds);
        $this->assertTargetDenied(fn() => $c->call('AddTicketReply', ['ticketid' => 1, 'clientid' => 9, 'noemail' => true]), 'clientid');
        $this->assertSame([], $cmds, 'sem lookup quando clientid já veio');
    }

    public function test_read_commands_ignore_target_allowlists(): void
    {
        $c = $this->targetClient(['allowlist_clientids' => [5], 'allowlist_ticketids' => [1]]);
        $this->assertSame('success', $c->call('GetClientsDetails', ['clientid' => 999])['result']);
        $this->assertSame('success', $c->call('GetTicket', ['ticketid' => 999])['result']);
    }

    public function test_commands_without_any_target_param_follow_class_gate_only(): void
    {
        $c = $this->targetClient(['allowlist_clientids' => [5], 'allowlist_ticketids' => [1]]);
        $this->assertSame('success', $c->call('AddClient', ['firstname' => 'x', 'noemail' => true])['result']);
    }

    // ---------------------------------------------------------------
    // #14 (achado 38) — orderid/quoteid resolvidos ao dono antes do gate
    // ---------------------------------------------------------------

    /** Fixture: pedido 10 e cotação 20 pertencem ao cliente 5; 11/21 ao 99; 12/22 órfãos. */
    private static function ownerLookupFixture(): callable
    {
        $owner = fn(int $id): int => match ($id) { 10, 20 => 5, 11, 21 => 99, default => 0 };
        return function (string $cmd, array $p) use ($owner) {
            if ($cmd === 'GetOrders') {
                $id = (int) $p['id'];
                return ['result' => 'success', 'orders' => ['order' => [['id' => $id, 'userid' => $owner($id)]]]];
            }
            if ($cmd === 'GetQuotes') {
                $id = (int) $p['quoteid'];
                return ['result' => 'success', 'quotes' => ['quote' => [['id' => $id, 'userid' => $owner($id)]]]];
            }
            return ['result' => 'success'];
        };
    }

    public function test_cancel_order_resolves_owner_via_get_orders_before_gate(): void
    {
        $cmds = [];
        $c = $this->targetClient(['allowlist_clientids' => [5]], self::ownerLookupFixture(), $cmds);

        $this->assertSame('success', $c->call('CancelOrder', ['orderid' => 10, 'noemail' => true])['result']);
        $this->assertSame(['GetOrders', 'CancelOrder'], $cmds);

        $cmds = [];
        $this->assertTargetDenied(fn() => $c->call('CancelOrder', ['orderid' => 11, 'noemail' => true]), 'clientid');
        $this->assertSame(['GetOrders'], $cmds, 'CancelOrder nunca chegou à API');
    }

    public function test_delete_and_update_quote_resolve_owner_via_get_quotes_before_gate(): void
    {
        $cmds = [];
        $c = $this->targetClient(['allowlist_clientids' => [5]], self::ownerLookupFixture(), $cmds);

        $this->assertSame('success', $c->call('DeleteQuote', ['quoteid' => 20])['result']);
        $this->assertSame('success', $c->call('UpdateQuote', ['quoteid' => 20, 'subject' => 'x'])['result']);
        $this->assertSame('success', $c->call('AcceptQuote', ['quoteid' => 20])['result']);
        $this->assertSame(['GetQuotes', 'DeleteQuote', 'GetQuotes', 'UpdateQuote', 'GetQuotes', 'AcceptQuote'], $cmds);

        $cmds = [];
        $this->assertTargetDenied(fn() => $c->call('DeleteQuote', ['quoteid' => 21]), 'clientid');
        $this->assertSame(['GetQuotes'], $cmds);
    }

    public function test_orphan_order_or_quote_skips_client_check(): void
    {
        $c = $this->targetClient(['allowlist_clientids' => [5]], self::ownerLookupFixture());
        $this->assertSame('success', $c->call('CancelOrder', ['orderid' => 12, 'noemail' => true])['result']);
        $this->assertSame('success', $c->call('DeleteQuote', ['quoteid' => 22])['result']);
    }

    public function test_unresolvable_order_or_quote_is_denied(): void
    {
        // lookup com erro
        $err = fn(string $cmd) => in_array($cmd, ['GetOrders', 'GetQuotes'], true)
            ? ['result' => 'error', 'message' => 'not found'] : ['result' => 'success'];
        $c = $this->targetClient(['allowlist_clientids' => [5]], $err);
        $this->assertTargetDenied(fn() => $c->call('CancelOrder', ['orderid' => 1, 'noemail' => true]), 'orderid→clientid');
        $this->assertTargetDenied(fn() => $c->call('DeleteQuote', ['quoteid' => 1]), 'quoteid→clientid');

        // lookup devolve lista vazia
        $empty = fn(string $cmd) => match ($cmd) {
            'GetOrders' => ['result' => 'success', 'orders' => ['order' => []]],
            'GetQuotes' => ['result' => 'success', 'quotes' => ['quote' => []]],
            default => ['result' => 'success'],
        };
        $c = $this->targetClient(['allowlist_clientids' => [5]], $empty);
        $this->assertTargetDenied(fn() => $c->call('CancelOrder', ['orderid' => 1, 'noemail' => true]), 'orderid→clientid');

        // lookup devolve registro de OUTRO id (filtro ignorado) — mesmo que o dono seja permitido
        $wrongId = fn(string $cmd) => $cmd === 'GetQuotes'
            ? ['result' => 'success', 'quotes' => ['quote' => [['id' => 999, 'userid' => 5]]]]
            : ['result' => 'success'];
        $c = $this->targetClient(['allowlist_clientids' => [5]], $wrongId);
        $this->assertTargetDenied(fn() => $c->call('DeleteQuote', ['quoteid' => 1]), 'quoteid→clientid');

        // lookup lança exceção
        $throws = function (string $cmd) { if ($cmd === 'GetOrders') throw new \RuntimeException('boom'); return ['result' => 'success']; };
        $c = $this->targetClient(['allowlist_clientids' => [5]], $throws);
        $this->assertTargetDenied(fn() => $c->call('CancelOrder', ['orderid' => 1, 'noemail' => true]), 'orderid→clientid');
    }

    public function test_explicit_userid_on_quote_wins_over_lookup(): void
    {
        $cmds = [];
        $c = $this->targetClient(['allowlist_clientids' => [5]], self::ownerLookupFixture(), $cmds);
        $this->assertTargetDenied(fn() => $c->call('UpdateQuote', ['quoteid' => 20, 'userid' => 9]), 'clientid');
        $this->assertSame([], $cmds, 'sem lookup quando userid já veio');
    }

    public function test_order_quote_lookup_not_performed_without_client_allowlist(): void
    {
        $cmds = [];
        $c = $this->targetClient(['allowlist_ticketids' => [1]], self::ownerLookupFixture(), $cmds);
        $this->assertSame('success', $c->call('CancelOrder', ['orderid' => 11, 'noemail' => true])['result']);
        $this->assertSame(['CancelOrder'], $cmds);
    }

    public function test_class_gate_still_applies_before_target_resolution(): void
    {
        $cmds = [];
        $c = new LocalApiClient('testadmin');
        $c->setGates(['write' => true, 'destructive' => false, 'allowlist_clientids' => [5]]);
        $c->setCallable(function (string $cmd) use (&$cmds) { $cmds[] = $cmd; return ['result' => 'success']; });
        try {
            $c->call('CancelOrder', ['orderid' => 10, 'noemail' => true]);
            $this->fail('DESTRUCTIVE desligado deve negar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('class DESTRUCTIVE disabled', $e->getMessage());
        }
        $this->assertSame([], $cmds, 'nenhum lookup quando a classe já nega');
    }
}
