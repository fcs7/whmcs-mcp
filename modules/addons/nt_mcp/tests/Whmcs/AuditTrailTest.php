<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use NtMcp\Tests\Support\ActivityLogSpy;
use NtMcp\Tools\OrderTools;
use NtMcp\Tools\QuoteTools;
use NtMcp\Whmcs\CapsuleClient;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\PaymentGatewayDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * m1 — bloqueio + início + resultado precisam ser auditáveis em TODAS as rotas,
 * inclusive nas que nunca chegam a LocalApiClient::call(); e m2 — `noemail` tem
 * de chegar à LocalAPI como boolean canônico.
 */
class AuditTrailTest extends TestCase
{
    protected function setUp(): void
    {
        \WHMCS\Config\Setting::reset();
        ActivityLogSpy::start();
    }

    protected function tearDown(): void
    {
        ActivityLogSpy::stop();
        \WHMCS\Config\Setting::reset();
    }

    private function client(array $gates, ?callable $cb = null): LocalApiClient
    {
        $api = new LocalApiClient('testadmin');
        $api->setGates($gates);
        $api->setCallable($cb ?? fn() => ['result' => 'success']);

        return $api;
    }

    // ---------------------------------------------------------------
    // m1 — LocalAPI: início E desfecho explícito
    // ---------------------------------------------------------------

    public function test_successful_call_logs_start_and_explicit_outcome(): void
    {
        $this->client(['write' => true])->call('AddClient', ['firstname' => 'a', 'noemail' => true]);

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API CALL command=AddClient'), 'faltou o início');
        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API OK command=AddClient'), 'faltou o desfecho');
    }

    public function test_error_call_logs_explicit_error_outcome_and_not_ok(): void
    {
        $this->client(['write' => true], fn() => ['result' => 'error', 'message' => 'Email already exists'])
            ->call('AddClient', ['firstname' => 'a', 'noemail' => true]);

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API ERROR command=AddClient'));
        $this->assertFalse(ActivityLogSpy::hasEntryContaining('MCP API OK command=AddClient'));
    }

    // ---------------------------------------------------------------
    // F2 — texto downstream nunca chega cru ao Activity Log
    // ---------------------------------------------------------------

    /**
     * Cenário exato reproduzido pela revisão: o WHMCS (ou um hook, ou um módulo
     * de terceiro) devolve o input sensível ECOADO dentro de `message`. A
     * redação de parâmetros não alcança uma string já interpolada.
     */
    public function test_secret_echoed_in_error_message_never_reaches_activity_log(): void
    {
        $secrets = [
            'password2' => 'hunter2SuperSecret',
            'cardnum' => '4111111111111111',
            'tax_id' => '123.456.789-00',
        ];

        $this->client(['write' => true], fn() => [
            'result' => 'error',
            'message' => 'Rejected password hunter2SuperSecret card 4111111111111111 tax 123.456.789-00',
        ])->call('AddClient', $secrets + ['firstname' => 'a', 'noemail' => true]);

        $log = implode("\n", ActivityLogSpy::entries());

        $this->assertNotSame('', $log, 'deve haver auditoria');
        foreach ($secrets as $field => $secret) {
            $this->assertStringNotContainsString($secret, $log, "vazou {$field} no Activity Log");
        }
        // O desfecho estável continua registrado.
        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API ERROR command=AddClient'));
        // E o texto downstream inteiro não é interpolado.
        $this->assertStringNotContainsString('Rejected password', $log);
    }

    public function test_exception_message_never_reaches_activity_log(): void
    {
        $client = $this->client(['write' => true], function () {
            throw new \RuntimeException('boom token=abcdef0123456789 password2=hunter2SuperSecret');
        });

        try {
            $client->call('AddClient', ['password2' => 'hunter2SuperSecret', 'firstname' => 'a', 'noemail' => true]);
            $this->fail('deveria propagar');
        } catch (\RuntimeException) {
            // esperado
        }

        $log = implode("\n", ActivityLogSpy::entries());

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API EXCEPTION command=AddClient'));
        $this->assertStringNotContainsString('hunter2SuperSecret', $log);
        $this->assertStringNotContainsString('abcdef0123456789', $log);
        $this->assertStringNotContainsString('boom', $log);
    }

    // ---------------------------------------------------------------
    // m1.1 — desfecho para TODA rota, sem repetir params
    // ---------------------------------------------------------------

    public function test_throwable_from_localapi_emits_outcome(): void
    {
        $client = $this->client(['write' => true], function () {
            throw new \RuntimeException('kaboom');
        });

        try {
            $client->call('AddClient', ['firstname' => 'Zoe', 'noemail' => true]);
        } catch (\RuntimeException) {
            // esperado
        }

        $outcome = ActivityLogSpy::matching('MCP API EXCEPTION command=AddClient');
        $this->assertCount(1, $outcome);
        $this->assertStringNotContainsString('Zoe', $outcome[0], 'outcome não repete params');
    }

    public function test_non_array_return_emits_outcome_without_params(): void
    {
        $client = $this->client(['write' => true], fn() => 'not an array');

        try {
            $client->call('AddClient', ['firstname' => 'Zoe', 'noemail' => true]);
        } catch (\RuntimeException) {
            // esperado
        }

        $outcome = ActivityLogSpy::matching('MCP API MALFORMED RESPONSE command=AddClient');
        $this->assertCount(1, $outcome);
        $this->assertStringContainsString('MALFORMED RESPONSE', $outcome[0]);
        $this->assertStringNotContainsString('Zoe', $outcome[0]);
    }

    /** m1.1 (P2): array sem `result:success` não pode gerar `OK`. */
    public function test_malformed_array_does_not_emit_a_false_ok(): void
    {
        $client = $this->client(['write' => true], fn() => []);

        try {
            $client->call('AddClient', ['firstname' => 'Zoe', 'noemail' => true]);
            $this->fail('array malformado deveria falhar fechado');
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertFalse(ActivityLogSpy::hasEntryContaining('MCP API OK command=AddClient'));
        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API MALFORMED RESPONSE command=AddClient'));
    }

    /** m1.1: o outcome de ERRO também não pode repetir o dump de params. */
    public function test_error_outcome_does_not_repeat_params(): void
    {
        $this->client(['write' => true], fn() => ['result' => 'error', 'message' => 'nope'])
            ->call('AddClient', ['firstname' => 'Zoe', 'noemail' => true]);

        $outcome = ActivityLogSpy::matching('MCP API ERROR command=AddClient');
        $this->assertCount(1, $outcome);
        $this->assertStringNotContainsString('Zoe', $outcome[0]);
    }

    public function test_start_and_outcome_share_a_correlation_id(): void
    {
        $this->client(['write' => true])->call('AddClient', ['firstname' => 'a', 'noemail' => true]);

        $start = ActivityLogSpy::matching('MCP API CALL command=AddClient')[0] ?? '';
        $ok = ActivityLogSpy::matching('MCP API OK command=AddClient')[0] ?? '';

        $this->assertSame(1, preg_match('/\[corr:([0-9a-f]{8})\]/', $start, $m), 'início sem correlação');
        $this->assertStringContainsString("[corr:{$m[1]}]", $ok, 'desfecho deve reusar a correlação do início');
    }

    public function test_outcome_line_does_not_duplicate_the_parameter_dump(): void
    {
        $this->client(['write' => true])->call('AddClient', ['firstname' => 'Zoe', 'noemail' => true]);

        $ok = ActivityLogSpy::matching('MCP API OK command=AddClient');
        $this->assertCount(1, $ok);
    }

    // ---------------------------------------------------------------
    // #21: Activity log reads suppress API_CALL/API_OK
    // ---------------------------------------------------------------

    public function test_get_activity_log_does_not_emit_call_and_ok(): void
    {
        $this->client(['read' => true], fn() => ['result' => 'success', 'activity' => ['entry' => []]])
            ->call('GetActivityLog', ['limitnum' => 25]);

        $this->assertFalse(ActivityLogSpy::hasEntryContaining('MCP API CALL command=GetActivityLog'));
        $this->assertFalse(ActivityLogSpy::hasEntryContaining('MCP API OK command=GetActivityLog'));
    }

    public function test_get_activity_log_error_still_audits(): void
    {
        $this->client(['read' => true], fn() => ['result' => 'error', 'message' => 'Access denied'])
            ->call('GetActivityLog', ['limitnum' => 25]);

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API ERROR command=GetActivityLog'));
    }

    public function test_get_clients_still_emits_call_and_ok(): void
    {
        $this->client(['read' => true], fn() => ['result' => 'success', 'clients' => ['client' => []]])
            ->call('GetClients', ['limitstart' => 0]);

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API CALL command=GetClients'));
        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API OK command=GetClients'));
    }

    /**
     * D7: o bloqueio continua auditado, mas agora com METADADOS de allowlist —
     * nomes de campo e IDs. Nenhum valor livre, nem sequer marcado como
     * redigido, porque valor nenhum é copiado.
     */
    public function test_gate_block_is_audited_with_metadata_only(): void
    {
        try {
            $this->client([])->call('AddClient', [
                'clientid' => 42,
                'password2' => 'hunter2',
                'tax_id' => '111',
                'cardnum' => '4111111111111111',
                'proposal' => 'POISON tok_secret',
            ]);
        } catch (\RuntimeException) {
            // esperado
        }

        $blocked = ActivityLogSpy::matching('MCP API BLOCKED BY GATE command=AddClient');
        $this->assertCount(1, $blocked);

        // Nomes de campo conhecidos aparecem; valores livres, nunca.
        $this->assertStringContainsString('password2', $blocked[0], 'o NOME do campo é metadado');
        $this->assertStringContainsString('42', $blocked[0], 'IDs conhecidos são metadado');
        foreach (['hunter2', '4111111111111111', 'POISON', 'tok_secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $blocked[0], "vazou '{$secret}'");
        }
    }

    /** `cardnum` não está na allowlist de nomes: vira contagem, não nome. */
    public function test_unknown_field_names_are_counted_not_named(): void
    {
        try {
            $this->client([])->call('AddClient', ['segredo_do_cliente' => 'x', 'firstname' => 'Ana']);
        } catch (\RuntimeException) {
            // esperado
        }

        $blocked = ActivityLogSpy::matching('MCP API BLOCKED BY GATE command=AddClient')[0];
        $this->assertStringContainsString('unknown_fields', $blocked);
        $this->assertStringNotContainsString('segredo_do_cliente', $blocked);
        $this->assertStringContainsString('firstname', $blocked);
    }

    public function test_comms_block_is_audited(): void
    {
        try {
            $this->client(['write' => true])->call('OpenTicket', ['deptid' => 1]);
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API BLOCKED BY COMMS GATE command=OpenTicket'));
    }

    // ---------------------------------------------------------------
    // m1 — Capsule: bloqueio antes só existia em silêncio
    // ---------------------------------------------------------------

    public function test_blocked_capsule_write_is_audited(): void
    {
        $capsule = new CapsuleClient();
        $capsule->setWritableForTests(false);

        try {
            $capsule->insert('mod_mgcrm_contacts', ['name' => 'Ana', 'email' => 'ana@example.com']);
            $this->fail('write deveria estar bloqueado');
        } catch (\InvalidArgumentException) {
            // esperado
        }

        $this->assertTrue(
            ActivityLogSpy::hasEntryContaining('MCP DB WRITE BLOCKED'),
            'bloqueio de write CRM precisa deixar rastro'
        );
    }

    #[DataProvider('capsuleWriteProvider')]
    public function test_every_blocked_capsule_operation_is_audited(string $operation, callable $invoke): void
    {
        $capsule = new CapsuleClient();
        $capsule->setWritableForTests(false);

        try {
            $invoke($capsule);
            $this->fail("{$operation} deveria estar bloqueado");
        } catch (\InvalidArgumentException) {
            // esperado
        }

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP DB WRITE BLOCKED'));
    }

    public static function capsuleWriteProvider(): array
    {
        return [
            'INSERT' => ['INSERT', fn(CapsuleClient $c) => $c->insert('mod_mgcrm_contacts', ['name' => 'x'])],
            'UPDATE' => ['UPDATE', fn(CapsuleClient $c) => $c->update('mod_mgcrm_contacts', ['id' => 1], ['name' => 'x'])],
            'DELETE' => ['DELETE', fn(CapsuleClient $c) => $c->delete('mod_mgcrm_contacts', ['id' => 1])],
        ];
    }

    // ---------------------------------------------------------------
    // m1.1 — desfecho das três mutações Capsule
    // ---------------------------------------------------------------

    /**
     * Sem WHMCS bootstrapado, `Capsule::table()` não existe: a mutação lança e
     * precisa deixar desfecho — antes ficava só o log de início.
     */
    #[DataProvider('capsuleWriteProvider')]
    public function test_capsule_mutation_exception_emits_outcome(string $operation, callable $invoke): void
    {
        $capsule = new CapsuleClient();
        $capsule->setWritableForTests(true);

        try {
            $invoke($capsule);
            $this->fail("{$operation} deveria lançar sem o Capsule do WHMCS");
        } catch (\Throwable) {
            // esperado
        }

        $outcome = ActivityLogSpy::matching('MCP DB EXCEPTION');
        $this->assertCount(1, $outcome, "faltou desfecho de exceção em {$operation}");
        $this->assertStringNotContainsString('Ana', $outcome[0], 'desfecho não repete dados');
    }

    /** Desfecho de sucesso, com a contagem de linhas e sem repetir dados. */
    public function test_capsule_mutation_success_emits_outcome_without_repeating_data(): void
    {
        $capsule = $this->capsuleWithFakeExecutor(static fn(): int => 3);

        $rows = $capsule->insert('mod_mgcrm_contacts', ['name' => 'Ana', 'email' => 'ana@example.com']);

        $this->assertSame(3, $rows);
        $outcome = ActivityLogSpy::matching('MCP DB OK');
        $this->assertCount(1, $outcome);
        $this->assertStringContainsString('meta: {}', $outcome[0]);
        $this->assertStringNotContainsString('Ana', $outcome[0]);
        $this->assertStringNotContainsString('ana@example.com', $outcome[0]);
    }

    public function test_capsule_start_and_outcome_share_a_correlation_id(): void
    {
        $capsule = $this->capsuleWithFakeExecutor(static fn(): int => 1);
        $capsule->insert('mod_mgcrm_contacts', ['name' => 'Ana']);

        $start = ActivityLogSpy::matching('MCP DB INSERT')[0] ?? '';
        $ok = ActivityLogSpy::matching('MCP DB OK')[0] ?? '';

        $this->assertSame(1, preg_match('/\[corr:([0-9a-f]{8})\]/', $start, $m));
        $this->assertStringContainsString("[corr:{$m[1]}]", $ok);
    }

    /** Erro do driver não leva a mensagem crua ao Activity Log. */
    public function test_capsule_driver_message_never_reaches_activity_log(): void
    {
        $capsule = $this->capsuleWithFakeExecutor(static function (): int {
            throw new \RuntimeException('SQLSTATE[28000] user=root password=hunter2SuperSecret');
        });

        try {
            $capsule->insert('mod_mgcrm_contacts', ['name' => 'Ana']);
        } catch (\RuntimeException) {
            // esperado
        }

        $log = implode("\n", ActivityLogSpy::entries());
        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP DB EXCEPTION'));
        $this->assertStringNotContainsString('hunter2SuperSecret', $log);
        $this->assertStringNotContainsString('SQLSTATE', $log);
    }

    /** CapsuleClient com o executor de banco substituído pelo seam dedicado. */
    private function capsuleWithFakeExecutor(callable $executor): CapsuleClient
    {
        $capsule = new CapsuleClient();
        $capsule->setWritableForTests(true);
        $capsule->setExecutorForTests($executor);

        return $capsule;
    }

    public function test_blocked_capsule_write_logs_metadata_only(): void
    {
        $capsule = new CapsuleClient();
        $capsule->setWritableForTests(false);

        try {
            $capsule->insert('mod_mgcrm_contacts', [
                'name' => 'Ana',
                'note' => 'POISON 123.456.789-00 tok_secret',
            ]);
        } catch (\InvalidArgumentException) {
            // esperado
        }

        $entry = ActivityLogSpy::matching('MCP DB WRITE BLOCKED')[0];
        $this->assertStringContainsString('name', $entry, 'nome do campo é metadado');
        foreach (['Ana', 'POISON', '123.456.789-00', 'tok_secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $entry, "vazou '{$secret}'");
        }
    }

    // ---------------------------------------------------------------
    // m1 — recusas por confirm=false (retornam antes do cliente central)
    // ---------------------------------------------------------------

    public function test_cancel_order_confirm_false_is_audited(): void
    {
        (new OrderTools($this->client(['destructive' => true])))->cancelOrder(orderid: 12, confirm: false);

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP CONFIRMATION REQUIRED'));
    }

    public function test_delete_quote_confirm_false_is_audited(): void
    {
        (new QuoteTools($this->client(['destructive' => true])))->deleteQuote(quoteid: 7, confirm: false);

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP CONFIRMATION REQUIRED'));
    }

    // ---------------------------------------------------------------
    // m1 — falha parcial de conversão precisa de rastro próprio
    // ---------------------------------------------------------------

    public function test_partial_conversion_is_audited(): void
    {
        $gateways = new PaymentGatewayDirectory();
        $gateways->setResolver(static fn() => ['banktransfer']);

        $api = $this->client(['financial' => true], function (string $cmd) {
            if ($cmd === 'GetQuotes') {
                return ['result' => 'success', 'quotes' => ['quote' => [['id' => 10, 'stage' => 'Delivered']]]];
            }
            if ($cmd === 'AcceptQuote') {
                return ['result' => 'success', 'invoiceid' => 99];
            }
            throw new \RuntimeException('boom');
        });

        (new QuoteTools($api, $gateways))->convertQuoteToInvoice(quoteid: 10, paymentmethod: 'banktransfer');

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP PARTIAL FINANCIAL EFFECT'));
    }

    // ---------------------------------------------------------------
    // m1 — config corrompida é evento de segurança auditável
    // ---------------------------------------------------------------

    public function test_invalid_readonly_config_is_audited(): void
    {
        \WHMCS\Config\Setting::$store = ['nt_mcp_enable_write' => '1', 'nt_mcp_readonly' => 'garbage'];

        $api = new LocalApiClient('testadmin');
        $api->setCallable(fn() => ['result' => 'success']);

        try {
            $api->call('AddClient', ['firstname' => 'a', 'noemail' => true]);
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP CONFIG INVALID'));
        $this->assertStringNotContainsString('nt_mcp_readonly', implode("\n", ActivityLogSpy::entries()));
    }

    // ---------------------------------------------------------------
    // m2 — noemail normalizado antes da LocalAPI
    // ---------------------------------------------------------------

    #[DataProvider('canonicalSuppressionProvider')]
    public function test_canonical_suppression_reaches_localapi_as_boolean_true(mixed $raw): void
    {
        $captured = null;
        $this->client(['write' => true], function (string $cmd, array $params) use (&$captured) {
            $captured = $params;
            return ['result' => 'success'];
        })->call('OpenTicket', ['deptid' => 1, 'noemail' => $raw]);

        $this->assertTrue($captured['noemail'], 'deve ser boolean true, não string');
        $this->assertIsBool($captured['noemail']);
    }

    public static function canonicalSuppressionProvider(): array
    {
        return [[true], [1], ['1']];
    }

    /**
     * `'true'` não é canônico: NÃO suprime, portanto exige COMMS. Antes do
     * fixup era aceito como supressão e repassado como string à LocalAPI.
     */
    #[DataProvider('nonCanonicalNoEmailProvider')]
    public function test_non_canonical_noemail_requires_comms(mixed $raw): void
    {
        $called = false;
        $api = $this->client(['write' => true, 'comms' => false], function () use (&$called) {
            $called = true;
            return ['result' => 'success'];
        });

        try {
            $api->call('OpenTicket', ['deptid' => 1, 'noemail' => $raw]);
            $this->fail('valor não canônico deveria exigir COMMS');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('COMMS gate', $e->getMessage());
        }

        $this->assertFalse($called);
    }

    public static function nonCanonicalNoEmailProvider(): array
    {
        return [['true'], ['yes'], ['on'], [2], ['TRUE']];
    }

    public function test_non_canonical_noemail_is_normalized_to_false_when_comms_allowed(): void
    {
        $captured = null;
        $this->client(['write' => true, 'comms' => true], function (string $cmd, array $params) use (&$captured) {
            $captured = $params;
            return ['result' => 'success'];
        })->call('OpenTicket', ['deptid' => 1, 'noemail' => 'true']);

        $this->assertFalse($captured['noemail'], "'true' não é supressão canônica");
        $this->assertIsBool($captured['noemail']);
    }

    public function test_false_values_reach_localapi_as_boolean_false(): void
    {
        $captured = null;
        $this->client(['write' => true, 'comms' => true], function (string $cmd, array $params) use (&$captured) {
            $captured = $params;
            return ['result' => 'success'];
        })->call('OpenTicket', ['deptid' => 1, 'noemail' => '0']);

        $this->assertFalse($captured['noemail']);
        $this->assertIsBool($captured['noemail']);
    }

    public function test_noemail_is_untouched_for_non_notifying_commands(): void
    {
        $captured = null;
        $this->client(['destructive' => true], function (string $cmd, array $params) use (&$captured) {
            $captured = $params;
            return ['result' => 'success'];
        })->call('CancelOrder', ['orderid' => 1, 'noemail' => true]);

        $this->assertTrue($captured['noemail']);
    }
}
