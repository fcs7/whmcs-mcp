<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use NtMcp\Mcp\PhpMcpV1Adapter;
use NtMcp\Tests\Support\ActivityLogSpy;
use NtMcp\Tests\Support\ErrorLogSpy;
use NtMcp\Whmcs\CapsuleClient;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F2 adversarial — o mesmo payload de segredos atravessa TODOS os sinks e
 * nenhum pode carregá-lo: resposta real de `tools/call`, Activity Log e
 * `error_log` (capturado de verdade, não presumido).
 *
 * A suíte anterior ficava verde porque só inspecionava o Activity Log; os
 * vazamentos apareciam no stderr e no payload MCP.
 */
class SinkLeakTest extends TestCase
{
    /** Amostras de coisas que NUNCA podem aparecer em sink nenhum. */
    private const SECRETS = [
        'senha'    => 'hunter2SuperSecret',
        'token'    => 'tok_abcdef0123456789',
        'cpf'      => '123.456.789-00',
        'cnpj'     => '12.345.678/0001-95',
        'pan'      => '4111111111111111',
        'path'     => '/var/www/httpdocs/configuration.php',
        'sql'      => "SQLSTATE[42000] SELECT * FROM tblclients WHERE id=1",
    ];

    private static function poisonedText(): string
    {
        return 'Rejected: ' . implode(' ', self::SECRETS);
    }

    private string $cacheDir;
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = dirname(__DIR__, 2) . '/src';
        $this->cacheDir = sys_get_temp_dir() . '/nt_mcp_sink_' . bin2hex(random_bytes(6));
        @mkdir($this->cacheDir, 0700, true);
        ActivityLogSpy::start();
        ErrorLogSpy::start();
        // D10: sem chave provisionada o fingerprint é OMITIDO. Os testes que
        // afirmam sobre fingerprint precisam de uma chave válida.
        \NtMcp\Whmcs\Diagnostics::setFingerprintKey(hash('sha256', 'nt-mcp test diagnostics key A'));
    }

    protected function tearDown(): void
    {
        \NtMcp\Whmcs\Diagnostics::resetFingerprintKey();
        ErrorLogSpy::stop();
        ActivityLogSpy::stop();
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    private function adapter(callable $cb): PhpMcpV1Adapter
    {
        $api = new LocalApiClient('testadmin');
        $api->setGates(['write' => true, 'financial' => true, 'destructive' => true]);
        $api->setCallable($cb);
        $api->setAdminIdResolver(static fn(string $u): int => 7);

        return new PhpMcpV1Adapter($api, new CapsuleClient(), $this->baseDir, $this->cacheDir);
    }

    private function callTool(PhpMcpV1Adapter $adapter, string $name, array $args, string $client): string
    {
        $messages = $adapter->handle(
            json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $args],
            ]),
            $client,
            'tools/call'
        );

        foreach ($messages as $m) {
            if (($m['id'] ?? null) === 1) {
                return json_encode($m['error'] ?? $m['result'] ?? []);
            }
        }

        return '';
    }

    /** Todos os sinks juntos, para cada segredo. */
    private function assertNoSinkLeaked(string $mcpPayload): void
    {
        $sinks = [
            'payload MCP'  => $mcpPayload,
            'Activity Log' => implode("\n", ActivityLogSpy::entries()),
            'error_log'    => ErrorLogSpy::contents(),
        ];

        foreach ($sinks as $sinkName => $content) {
            foreach (self::SECRETS as $label => $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $content,
                    "vazou {$label} no sink '{$sinkName}'"
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // LocalAPI: erro em array e exceção
    // ---------------------------------------------------------------

    public function test_error_array_with_secrets_leaks_nowhere(): void
    {
        $adapter = $this->adapter(fn() => ['result' => 'error', 'message' => self::poisonedText()]);

        $payload = $this->callTool($adapter, 'whmcs_list_clients', [], 'sink-err-000001');

        $this->assertNoSinkLeaked($payload);
        // O contrato estável sobrevive, com correlação.
        $this->assertStringContainsString('correlation_id', $payload);
    }

    /** D6 pelo adapter real: eco exato nunca ganha código semântico falso. */
    public function test_exact_caller_input_echo_is_downstream_and_leaks_nowhere_via_adapter(): void
    {
        $echo = 'Email Address Already Exists';
        $adapter = $this->adapter(static fn(): array => ['result' => 'error', 'message' => $echo]);

        $payload = $this->callTool($adapter, 'whmcs_create_client', [
            'firstname' => $echo,
            'lastname' => 'Tester',
            'email' => 'echo@example.test',
            'password2' => 'safe-enough-value',
        ], 'sink-echo-adapter');

        $this->assertStringContainsString('downstream_error', $payload);
        $this->assertStringContainsString('downstream', $payload);
        $this->assertStringNotContainsString($echo, $payload);
        $this->assertStringNotContainsString($echo, implode("\n", ActivityLogSpy::entries()));
        $this->assertStringNotContainsString($echo, ErrorLogSpy::contents());
    }

    public function test_thrown_exception_with_secrets_leaks_nowhere(): void
    {
        $adapter = $this->adapter(function () {
            throw new \RuntimeException(self::poisonedText());
        });

        $payload = $this->callTool($adapter, 'whmcs_list_clients', [], 'sink-exc-000001');

        $this->assertNoSinkLeaked($payload);
        $this->assertStringContainsString('did not complete successfully', $payload);
    }

    /** O `error_log` guarda categoria, classe e fingerprint — não a mensagem. */
    public function test_error_log_records_structural_diagnostics_only(): void
    {
        $adapter = $this->adapter(function () {
            throw new \RuntimeException(self::poisonedText());
        });

        $this->callTool($adapter, 'whmcs_list_clients', [], 'sink-diag-00001');

        $log = ErrorLogSpy::contents();
        $this->assertStringContainsString('category=downstream_api_exception', $log);
        $this->assertStringContainsString('exception=RuntimeException', $log);
        $this->assertMatchesRegularExpression('/fingerprint=[0-9a-f]{12}/', $log);
        $this->assertMatchesRegularExpression('/\[corr:[0-9a-f]{8}\]/', $log);
    }

    // ---------------------------------------------------------------
    // Segredos ecoados a partir dos PRÓPRIOS parâmetros da chamada
    // ---------------------------------------------------------------

    public function test_secrets_echoed_from_call_params_leak_nowhere(): void
    {
        $adapter = $this->adapter(fn() => ['result' => 'error', 'message' => self::poisonedText()]);

        $payload = $this->callTool($adapter, 'whmcs_create_client', [
            'firstname' => 'Ana',
            'lastname' => 'Silva',
            'email' => 'ana@example.com',
            'password2' => self::SECRETS['senha'],
            'tax_id' => self::SECRETS['cpf'],
        ], 'sink-echo-00001');

        $this->assertNoSinkLeaked($payload);
    }

    // ---------------------------------------------------------------
    // Conversão parcial: efeito financeiro + exceção envenenada
    // ---------------------------------------------------------------

    public function test_partial_conversion_keeps_contract_without_leaking(): void
    {
        $adapter = $this->adapter(function (string $cmd) {
            if ($cmd === 'GetQuotes') {
                return ['result' => 'success', 'quotes' => ['quote' => [['id' => 10, 'stage' => 'Delivered']]]];
            }
            if ($cmd === 'AcceptQuote') {
                return ['result' => 'success', 'invoiceid' => 99];
            }

            throw new \RuntimeException(self::poisonedText());
        });

        $payload = $this->callTool($adapter, 'whmcs_convert_quote_to_invoice', [
            'quoteid' => 10,
            'duedate' => '2026-08-10T00:00:00Z',
        ], 'sink-part-00001');

        $this->assertNoSinkLeaked($payload);

        // O contrato parcial permanece íntegro (payload vem JSON dentro de JSON).
        foreach (['partial', 'quoteid', 'invoiceid', 'repetir', 'correlation_id'] as $needle) {
            $this->assertStringContainsString($needle, $payload, "faltou {$needle} no contrato parcial");
        }
    }

    // ---------------------------------------------------------------
    // Capsule
    // ---------------------------------------------------------------

    /**
     * Driver de banco lançando com credencial de conexão e SQL na mensagem —
     * o cenário real de um `PDOException`.
     */
    #[DataProvider('capsuleOperationProvider')]
    public function test_capsule_driver_exception_leaks_nowhere(string $operation, callable $invoke): void
    {
        $capsule = $this->capsuleThrowing(new \RuntimeException(self::poisonedText()));

        try {
            $invoke($capsule);
            $this->fail("{$operation} deveria lançar");
        } catch (\Throwable $e) {
            // A exceção pública também não pode carregar o texto do driver.
            $this->assertStringNotContainsString('SQLSTATE[42000] SELECT', $e->getMessage());
        }

        $this->assertNoSinkLeaked('');
        $this->assertTrue(ErrorLogSpy::hasLineContaining('category=database_exception'));
        $this->assertTrue(ErrorLogSpy::hasLineContaining('exception=RuntimeException'));
    }

    public static function capsuleOperationProvider(): array
    {
        return [
            'INSERT' => ['INSERT', fn(CapsuleClient $c) => $c->insert('mod_mgcrm_contacts', ['name' => 'Ana'])],
            'UPDATE' => ['UPDATE', fn(CapsuleClient $c) => $c->update('mod_mgcrm_contacts', ['id' => 1], ['name' => 'Ana'])],
            'DELETE' => ['DELETE', fn(CapsuleClient $c) => $c->delete('mod_mgcrm_contacts', ['id' => 1])],
        ];
    }

    private function capsuleThrowing(\Throwable $error): CapsuleClient
    {
        $capsule = new CapsuleClient();
        $capsule->setWritableForTests(true);
        $capsule->setExecutorForTests(static function () use ($error): int {
            throw $error;
        });

        return $capsule;
    }

    // ---------------------------------------------------------------
    // Falha de CONFIG — o ramo que a revisão reproduziu vazando nos dois sinks
    // ---------------------------------------------------------------

    /**
     * `Setting::getValue()` lançando com DSN/credencial dentro. Antes, tanto
     * `LocalApiClient` quanto `CapsuleClient` concatenavam a mensagem e
     * gravavam nos DOIS sinks.
     */
    public function test_config_read_failure_leaks_nowhere_in_localapi(): void
    {
        \WHMCS\Config\Setting::$throwOnRead = true;

        $api = new LocalApiClient('testadmin');
        $api->setCallable(fn() => ['result' => 'success']);

        try {
            $api->call('AddClient', ['firstname' => 'a', 'noemail' => true]);
            $this->fail('deveria falhar fechado');
        } catch (\RuntimeException) {
            // esperado
        } finally {
            \WHMCS\Config\Setting::reset();
        }

        $this->assertNoSinkLeaked('');
        $this->assertTrue(ErrorLogSpy::hasLineContaining('category=config_read_failure'));
        $this->assertTrue(ActivityLogSpy::hasEntryContaining('readonly config read failed'));
    }

    public function test_config_read_failure_leaks_nowhere_in_capsule(): void
    {
        \WHMCS\Config\Setting::$throwOnRead = true;

        try {
            (new CapsuleClient())->insert('mod_mgcrm_contacts', ['name' => 'Ana']);
            $this->fail('deveria falhar fechado');
        } catch (\InvalidArgumentException) {
            // esperado
        } finally {
            \WHMCS\Config\Setting::reset();
        }

        $this->assertNoSinkLeaked('');
        $this->assertTrue(ErrorLogSpy::hasLineContaining('category=config_read_failure'));
    }

    /**
     * A mensagem simulada carrega os segredos; provamos que nem o Activity Log
     * nem o error_log a reproduzem, em nenhuma das duas rotas.
     */
    public function test_poisoned_config_exception_never_appears_in_any_sink(): void
    {
        \WHMCS\Config\Setting::$throwOnRead = true;
        \WHMCS\Config\Setting::$readFailure = new \RuntimeException(self::poisonedText());

        $api = new LocalApiClient('testadmin');
        $api->setCallable(fn() => ['result' => 'success']);

        try {
            $api->call('AddClient', ['firstname' => 'a', 'noemail' => true]);
        } catch (\RuntimeException) {
            // esperado
        }

        try {
            (new CapsuleClient())->insert('mod_mgcrm_contacts', ['name' => 'Ana']);
        } catch (\InvalidArgumentException) {
            // esperado
        }

        \WHMCS\Config\Setting::reset();

        $this->assertNoSinkLeaked('');
    }

    // ---------------------------------------------------------------
    // Texto livre pelo adapter — proposal/notes/description
    // ---------------------------------------------------------------

    /**
     * D7: o segredo dentro de um campo de TEXTO LIVRE. Nenhuma denylist de
     * nomes protege isso — foi assim que `proposal` gravou a string inteira.
     */
    #[DataProvider('freeTextFieldProvider')]
    public function test_free_text_field_value_never_reaches_the_activity_log(string $tool, array $args): void
    {
        $adapter = $this->adapter(fn() => ['result' => 'success', 'quoteid' => 1, 'ticketid' => 1, 'clientid' => 1]);

        $payload = $this->callTool($adapter, $tool, $args, 'sink-free-' . substr(md5($tool), 0, 6));

        $this->assertNoSinkLeaked($payload);
    }

    public static function freeTextFieldProvider(): array
    {
        $poison = 'POISON ' . implode(' ', self::SECRETS);

        return [
            'quote.proposal' => ['whmcs_create_quote', [
                'subject' => 'S', 'stage' => 'Draft', 'proposal' => $poison,
            ]],
            'quote.customernotes' => ['whmcs_create_quote', [
                'subject' => 'S', 'stage' => 'Draft', 'proposal' => 'P', 'customernotes' => $poison,
            ]],
            'quote.adminnotes' => ['whmcs_create_quote', [
                'subject' => 'S', 'stage' => 'Draft', 'proposal' => 'P', 'adminnotes' => $poison,
            ]],
            'ticket.message' => ['whmcs_open_ticket', [
                'deptid' => 1, 'subject' => 'S', 'message' => $poison,
            ]],
            'client.notes' => ['whmcs_create_client', [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.c', 'password2' => 'x', 'notes' => $poison,
            ]],
            'project.notes' => ['whmcs_create_project', [
                'title' => 'T', 'adminid' => 1, 'notes' => $poison,
            ]],
        ];
    }

    // ---------------------------------------------------------------
    // Fingerprint da causa: estável e ligado pela correlação
    // ---------------------------------------------------------------

    /** Mesma causa em duas execuções ⇒ mesmo fingerprint. */
    public function test_same_cause_yields_the_same_fingerprint_across_partial_failures(): void
    {
        $fingerprints = [];
        $correlations = [];

        for ($i = 0; $i < 2; $i++) {
            ErrorLogSpy::start();

            $adapter = $this->adapter(function (string $cmd) {
                if ($cmd === 'GetQuotes') {
                    return ['result' => 'success', 'quotes' => ['quote' => [['id' => 10, 'stage' => 'Delivered']]]];
                }
                if ($cmd === 'AcceptQuote') {
                    return ['result' => 'success', 'invoiceid' => 99];
                }
                throw new \RuntimeException('the very same downstream cause');
            });

            $payload = $this->callTool($adapter, 'whmcs_convert_quote_to_invoice', [
                'quoteid' => 10, 'duedate' => '2026-08-10T00:00:00Z',
            ], 'sink-fp-' . str_pad((string) $i, 7, '0'));

            $log = ErrorLogSpy::contents();
            $this->assertSame(1, preg_match('/fingerprint=([0-9a-f]{32})/', $log, $fp), 'faltou fingerprint');
            $fingerprints[] = $fp[1];

            // O payload vem JSON dentro de JSON, então as aspas estão escapadas.
            $this->assertSame(1, preg_match('/correlation_id\D{0,8}([0-9a-f]{8})/', $payload, $corr));
            $correlations[] = $corr[1];

            // A correlação do payload precisa aparecer no diagnóstico.
            $this->assertStringContainsString("[corr:{$corr[1]}]", $log, 'payload e log precisam se ligar');
        }

        $this->assertSame($fingerprints[0], $fingerprints[1], 'mesma causa deve dar o mesmo fingerprint');
        $this->assertNotSame($correlations[0], $correlations[1], 'correlações são por execução');
    }

    /** Error-array de UpdateInvoice usa UMA correlação nos três sinks. */
    public function test_update_invoice_error_array_reuses_one_causal_correlation_everywhere(): void
    {
        $adapter = $this->adapter(static function (string $command): array {
            if ($command === 'GetQuotes') {
                return ['result' => 'success', 'quotes' => ['quote' => [['id' => 10, 'stage' => 'Delivered']]]];
            }
            if ($command === 'AcceptQuote') {
                return ['result' => 'success', 'invoiceid' => 99];
            }

            return ['result' => 'error', 'message' => 'Invalid Date Format'];
        });

        $payload = $this->callTool($adapter, 'whmcs_convert_quote_to_invoice', [
            'quoteid' => 10,
            'duedate' => '2026-08-10T00:00:00Z',
        ], 'sink-one-correlation');

        $this->assertSame(1, preg_match('/correlation_id\D{0,8}([0-9a-f]{8})/', $payload, $match));
        $correlation = $match[1];

        $updateError = ActivityLogSpy::matching('MCP API ERROR UpdateInvoice');
        $partial = ActivityLogSpy::matching('MCP PARTIAL convert_quote_to_invoice');
        $this->assertCount(1, $updateError);
        $this->assertCount(1, $partial);
        $this->assertStringContainsString("[corr:{$correlation}]", $updateError[0]);
        $this->assertStringContainsString("[corr:{$correlation}]", $partial[0]);
        $this->assertStringContainsString("[corr:{$correlation}]", ErrorLogSpy::contents());

        preg_match_all('/\[corr:([0-9a-f]{8})\]/', implode("\n", [$updateError[0], $partial[0], ErrorLogSpy::contents()]), $all);
        $this->assertSame([$correlation], array_values(array_unique($all[1])));
    }

    /** AcceptQuote error-array também liga causa e contrato parcial pelo mesmo ID. */
    public function test_accept_quote_error_array_reuses_one_causal_correlation(): void
    {
        $adapter = $this->adapter(static function (string $command): array {
            if ($command === 'GetQuotes') {
                return ['result' => 'success', 'quotes' => ['quote' => [['id' => 10, 'stage' => 'Delivered']]]];
            }

            return ['result' => 'error', 'message' => 'Quote Already Accepted'];
        });

        $payload = $this->callTool(
            $adapter,
            'whmcs_convert_quote_to_invoice',
            ['quoteid' => 10],
            'sink-accept-correlation'
        );
        $this->assertSame(1, preg_match('/correlation_id\D{0,8}([0-9a-f]{8})/', $payload, $match));
        $correlation = $match[1];

        $acceptError = ActivityLogSpy::matching('MCP API ERROR AcceptQuote');
        $partial = ActivityLogSpy::matching('MCP PARTIAL convert_quote_to_invoice');
        $this->assertCount(1, $acceptError);
        $this->assertCount(1, $partial);
        $this->assertStringContainsString("[corr:{$correlation}]", $acceptError[0]);
        $this->assertStringContainsString("[corr:{$correlation}]", $partial[0]);
        $this->assertStringContainsString("[corr:{$correlation}]", ErrorLogSpy::contents());
        $this->assertStringNotContainsString('invoiceid', $payload);
    }

    /** IP externo não aparece nem no Activity Log nem no diagnóstico. */
    public function test_external_ip_is_absent_from_both_operational_sinks(): void
    {
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';

        try {
            LocalApiClient::auditLog('MCP TEST', \NtMcp\Whmcs\AuditMetadata::none());
            \NtMcp\Whmcs\Diagnostics::event(\NtMcp\Whmcs\Diagnostics::CATEGORY_TLS, 'allow_http_bypass');
        } finally {
            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }

        $this->assertStringNotContainsString('203.0.113.77', implode("\n", ActivityLogSpy::entries()));
        $this->assertStringNotContainsString('203.0.113.77', ErrorLogSpy::contents());
    }

    public function test_operational_event_rejects_arbitrary_category_context_and_detail(): void
    {
        \NtMcp\Whmcs\Diagnostics::event(
            'tok_abcdef0123456789',
            'hunter2SuperSecret',
            ['sk_live_51H8yQ2eZvKYlo2C' => '4111111111111111']
        );

        $log = ErrorLogSpy::contents();
        $this->assertStringContainsString('category=runtime_failure', $log);
        $this->assertStringContainsString('context=unknown_event', $log);
        foreach (['tok_abcdef0123456789', 'hunter2SuperSecret', 'sk_live_51H8yQ2eZvKYlo2C', '4111111111111111'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }

    /** Causas diferentes ⇒ fingerprints diferentes. */
    public function test_different_causes_yield_different_fingerprints(): void
    {
        $seen = [];

        foreach (['cause A', 'cause B'] as $i => $cause) {
            ErrorLogSpy::start();

            $adapter = $this->adapter(function (string $cmd) use ($cause) {
                if ($cmd === 'GetQuotes') {
                    return ['result' => 'success', 'quotes' => ['quote' => [['id' => 10, 'stage' => 'Delivered']]]];
                }
                if ($cmd === 'AcceptQuote') {
                    return ['result' => 'success', 'invoiceid' => 99];
                }
                throw new \RuntimeException($cause);
            });

            $this->callTool($adapter, 'whmcs_convert_quote_to_invoice', [
                'quoteid' => 10, 'duedate' => '2026-08-10T00:00:00Z',
            ], 'sink-fpd-' . str_pad((string) $i, 6, '0'));

            preg_match('/fingerprint=([0-9a-f]{32})/', ErrorLogSpy::contents(), $fp);
            $seen[] = $fp[1] ?? '';
        }

        $this->assertNotSame($seen[0], $seen[1]);
    }

    /** HMAC de 128 bits — hash nu era oráculo de dicionário. */
    public function test_fingerprint_is_keyed_and_128_bits(): void
    {
        \NtMcp\Whmcs\Diagnostics::setFingerprintKey(hash('sha256', 'nt-mcp test diagnostics key one'));
        $withKeyOne = \NtMcp\Whmcs\Diagnostics::fingerprint('Client Not Found');

        \NtMcp\Whmcs\Diagnostics::setFingerprintKey(hash('sha256', 'nt-mcp test diagnostics key two'));
        $withKeyTwo = \NtMcp\Whmcs\Diagnostics::fingerprint('Client Not Found');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $withKeyOne, '128 bits');
        $this->assertNotSame($withKeyOne, $withKeyTwo, 'sem chave, o log confirmaria palpites');
        $this->assertNotSame(
            substr(hash('sha256', 'Client Not Found'), 0, 32),
            $withKeyOne,
            'não pode ser SHA-256 nu'
        );

        \NtMcp\Whmcs\Diagnostics::setFingerprintKey(null);
    }

    // ---------------------------------------------------------------
    // Falha do PRÓPRIO sink de auditoria
    // ---------------------------------------------------------------

    /**
     * Se o `logActivity()` falha, o fallback não pode despejar a entrada (que
     * carrega o dump de params) nem a mensagem da exceção no error_log — seria
     * transformar uma falha de log em vazamento.
     */
    public function test_audit_sink_failure_does_not_dump_entry_or_exception(): void
    {
        ActivityLogSpy::failWith(new \RuntimeException(self::poisonedText()));

        try {
            LocalApiClient::auditLog(
                'MCP API call: AddClient',
                \NtMcp\Whmcs\AuditMetadata::forParams([
                    'password2' => self::SECRETS['senha'],
                    'tax_id' => self::SECRETS['cpf'],
                ])
            );
        } finally {
            ActivityLogSpy::failWith(null);
        }

        $log = ErrorLogSpy::contents();

        $this->assertStringContainsString('category=audit_sink_failure', $log);
        $this->assertStringNotContainsString('MCP API call: AddClient', $log, 'entry não pode ser despejada');
        foreach (self::SECRETS as $label => $secret) {
            $this->assertStringNotContainsString($secret, $log, "vazou {$label} no fallback do audit sink");
        }
    }
}
