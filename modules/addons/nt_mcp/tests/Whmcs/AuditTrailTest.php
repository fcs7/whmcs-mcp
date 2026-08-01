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

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API call: AddClient'), 'faltou o início');
        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API OK AddClient'), 'faltou o desfecho');
    }

    public function test_error_call_logs_explicit_error_outcome_and_not_ok(): void
    {
        $this->client(['write' => true], fn() => ['result' => 'error', 'message' => 'Email already exists'])
            ->call('AddClient', ['firstname' => 'a', 'noemail' => true]);

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API ERROR AddClient'));
        $this->assertFalse(ActivityLogSpy::hasEntryContaining('MCP API OK AddClient'));
    }

    public function test_outcome_line_does_not_duplicate_the_parameter_dump(): void
    {
        $this->client(['write' => true])->call('AddClient', ['firstname' => 'Zoe', 'noemail' => true]);

        $ok = ActivityLogSpy::matching('MCP API OK AddClient');
        $this->assertCount(1, $ok);
        $this->assertStringNotContainsString('Zoe', $ok[0], 'params já foram logados no início');
    }

    public function test_gate_block_is_audited_with_redacted_params(): void
    {
        try {
            $this->client([])->call('AddClient', ['password2' => 'hunter2', 'tax_id' => '111', 'cardnum' => '4111']);
        } catch (\RuntimeException) {
            // esperado
        }

        $blocked = ActivityLogSpy::matching('MCP BLOCKED WRITE');
        $this->assertCount(1, $blocked);
        $this->assertStringContainsString('[REDACTED]', $blocked[0]);
        $this->assertStringNotContainsString('hunter2', $blocked[0]);
        $this->assertStringNotContainsString('4111', $blocked[0]);
    }

    public function test_comms_block_is_audited(): void
    {
        try {
            $this->client(['write' => true])->call('OpenTicket', ['deptid' => 1]);
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP BLOCKED COMMS'));
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
            ActivityLogSpy::hasEntryContaining('MCP BLOCKED DB INSERT: mod_mgcrm_contacts'),
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

        $this->assertTrue(ActivityLogSpy::hasEntryContaining("MCP BLOCKED DB {$operation}"));
    }

    public static function capsuleWriteProvider(): array
    {
        return [
            'INSERT' => ['INSERT', fn(CapsuleClient $c) => $c->insert('mod_mgcrm_contacts', ['name' => 'x'])],
            'UPDATE' => ['UPDATE', fn(CapsuleClient $c) => $c->update('mod_mgcrm_contacts', ['id' => 1], ['name' => 'x'])],
            'DELETE' => ['DELETE', fn(CapsuleClient $c) => $c->delete('mod_mgcrm_contacts', ['id' => 1])],
        ];
    }

    public function test_blocked_capsule_write_redacts_sensitive_columns(): void
    {
        $capsule = new CapsuleClient();
        $capsule->setWritableForTests(false);

        try {
            $capsule->insert('mod_mgcrm_contacts', ['name' => 'Ana', 'tax_id' => '123.456.789-00']);
        } catch (\InvalidArgumentException) {
            // esperado
        }

        $entry = ActivityLogSpy::matching('MCP BLOCKED DB INSERT')[0];
        $this->assertStringContainsString('[REDACTED]', $entry);
        $this->assertStringNotContainsString('123.456.789-00', $entry);
    }

    // ---------------------------------------------------------------
    // m1 — recusas por confirm=false (retornam antes do cliente central)
    // ---------------------------------------------------------------

    public function test_cancel_order_confirm_false_is_audited(): void
    {
        (new OrderTools($this->client(['destructive' => true])))->cancelOrder(orderid: 12, confirm: false);

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP REFUSED whmcs_cancel_order (confirm=false)'));
    }

    public function test_delete_quote_confirm_false_is_audited(): void
    {
        (new QuoteTools($this->client(['destructive' => true])))->deleteQuote(quoteid: 7, confirm: false);

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP REFUSED whmcs_delete_quote (confirm=false)'));
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

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP PARTIAL convert_quote_to_invoice'));
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

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('nt_mcp_readonly'));
        $this->assertTrue(ActivityLogSpy::hasEntryContaining('falhando fechado'));
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
