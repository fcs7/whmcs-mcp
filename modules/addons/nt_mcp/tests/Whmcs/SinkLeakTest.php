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
    }

    protected function tearDown(): void
    {
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
        $capsule = new class($error) extends CapsuleClient {
            public function __construct(private \Throwable $error) {}

            protected function runWithOutcome(string $verb, string $table, string $correlationId, callable $operation): int
            {
                return parent::runWithOutcome($verb, $table, $correlationId, function (): int {
                    throw $this->error;
                });
            }
        };
        $capsule->setWritableForTests(true);

        return $capsule;
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
            LocalApiClient::auditLog('MCP API call: AddClient', [
                'password2' => self::SECRETS['senha'],
                'tax_id' => self::SECRETS['cpf'],
            ]);
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
