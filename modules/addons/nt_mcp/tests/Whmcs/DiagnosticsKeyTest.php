<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use NtMcp\Tests\Support\ErrorLogSpy;
use NtMcp\Whmcs\Diagnostics;
use NtMcp\Whmcs\DiagnosticsKeyStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * D10 — chave HMAC por instalação, provisionada na ativação.
 *
 * O ponto central: sem chave utilizável o fingerprint é OMITIDO. O fallback
 * anterior derivava de `__FILE__ . getmypid()`, que é reconstruível por quem
 * conhece o ambiente — transformava o log em oráculo em vez de protegê-lo.
 */
class DiagnosticsKeyTest extends TestCase
{
    private static function validKey(int $offset = 0): string
    {
        $bytes = '';
        for ($i = 0; $i < 32; $i++) {
            $bytes .= chr(($i + $offset) % 256);
        }

        return bin2hex($bytes);
    }

    protected function setUp(): void
    {
        \WHMCS\Config\Setting::reset();
        Diagnostics::resetFingerprintKey();
        DiagnosticsKeyStore::setClaimOverrideForTests(static function (string $candidate): mixed {
            return \WHMCS\Config\Setting::$store[Diagnostics::KEY_SETTING] ??= $candidate;
        });
        ErrorLogSpy::start();
    }

    protected function tearDown(): void
    {
        ErrorLogSpy::stop();
        Diagnostics::resetFingerprintKey();
        DiagnosticsKeyStore::setClaimOverrideForTests(null);
        \WHMCS\Config\Setting::reset();
    }

    // ---------------------------------------------------------------
    // Formato e geração
    // ---------------------------------------------------------------

    public function test_generated_key_has_the_canonical_format(): void
    {
        $key = Diagnostics::generateKey();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $key);
        $this->assertTrue(Diagnostics::isValidKey($key));
    }

    public function test_generated_keys_are_distinct(): void
    {
        $this->assertNotSame(Diagnostics::generateKey(), Diagnostics::generateKey());
    }

    #[DataProvider('invalidKeyProvider')]
    public function test_invalid_keys_are_rejected(mixed $key): void
    {
        $this->assertFalse(Diagnostics::isValidKey($key));
    }

    public static function invalidKeyProvider(): array
    {
        return [
            'null'            => [null],
            'vazia'           => [''],
            'curta'           => [str_repeat('a', 32)],
            'longa'           => [str_repeat('a', 65)],
            'não-hex'         => [str_repeat('z', 64)],
            'maiúscula'       => [str_repeat('A', 64)],
            'byte repetido'   => [str_repeat('ab', 32)],
            'dois bytes'      => [str_repeat('abcd', 16)],
            'newline final'   => [str_repeat('a', 64) . "\n"],
            'não-string'      => [12345],
        ];
    }

    // ---------------------------------------------------------------
    // Omissão sem chave — o núcleo de D10
    // ---------------------------------------------------------------

    public function test_fingerprint_is_omitted_without_a_key(): void
    {
        Diagnostics::setFingerprintKey(null);

        $this->assertNull(Diagnostics::fingerprint('Client Not Found'));
    }

    public function test_fingerprint_is_omitted_when_the_stored_key_is_invalid(): void
    {
        \WHMCS\Config\Setting::$store[Diagnostics::KEY_SETTING] = 'nope';
        Diagnostics::resetFingerprintKey();

        $this->assertNull(Diagnostics::fingerprint('Client Not Found'));
    }

    public function test_fingerprint_is_omitted_when_config_read_fails(): void
    {
        \WHMCS\Config\Setting::$throwOnRead = true;
        Diagnostics::resetFingerprintKey();

        $this->assertNull(Diagnostics::fingerprint('Client Not Found'));
    }

    /** Sem chave, a linha de log simplesmente não traz o campo. */
    public function test_log_line_omits_the_fingerprint_field_without_a_key(): void
    {
        Diagnostics::setFingerprintKey(null);

        Diagnostics::report(Diagnostics::CATEGORY_API_EXCEPTION, 'GetClients', new \RuntimeException('boom'));

        $log = ErrorLogSpy::contents();
        $this->assertStringContainsString('category=downstream_api_exception', $log);
        $this->assertStringContainsString('exception=RuntimeException', $log);
        $this->assertStringNotContainsString('fingerprint=', $log);
    }

    /**
     * O fallback removido era previsível: quem soubesse path e PID reconstruía
     * o fingerprint. Provamos que nada derivado desses valores aparece.
     */
    public function test_no_predictable_fallback_is_used(): void
    {
        Diagnostics::setFingerprintKey(null);
        $withoutKey = Diagnostics::fingerprint('Client Not Found');

        $predictable = substr(hash('sha256', 'Client Not Found'), 0, 32);
        $pidDerived = substr(hash_hmac('sha256', 'Client Not Found', hash('sha256', __FILE__ . getmypid())), 0, 32);

        $this->assertNull($withoutKey);
        $this->assertNotSame($predictable, $withoutKey);
        $this->assertNotSame($pidDerived, $withoutKey);
    }

    // ---------------------------------------------------------------
    // Estabilidade com chave persistida
    // ---------------------------------------------------------------

    /**
     * Chave persistida ⇒ mesma causa produz o mesmo fingerprint mesmo quando o
     * cache de processo é descartado (equivalente a outro processo).
     */
    public function test_persisted_key_yields_a_stable_fingerprint_across_processes(): void
    {
        \WHMCS\Config\Setting::$store[Diagnostics::KEY_SETTING] = self::validKey();

        Diagnostics::resetFingerprintKey();
        $first = Diagnostics::fingerprint('the very same cause');

        Diagnostics::resetFingerprintKey(); // simula processo novo
        $second = Diagnostics::fingerprint('the very same cause');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\z/', $first);
    }

    public function test_same_key_and_cause_match_in_two_distinct_php_processes(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $key = self::validKey();
        $code = 'require ' . var_export($autoload, true) . '; '
            . '\\NtMcp\\Whmcs\\Diagnostics::setFingerprintKey(' . var_export($key, true) . '); '
            . 'echo \\NtMcp\\Whmcs\\Diagnostics::fingerprint("same cross-process cause");';

        $first = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
        $second = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\z/', (string) $first);
        $this->assertSame($first, $second);
    }

    public function test_distinct_keys_yield_distinct_fingerprints(): void
    {
        Diagnostics::setFingerprintKey(self::validKey());
        $one = Diagnostics::fingerprint('same message');

        Diagnostics::setFingerprintKey(self::validKey(32));
        $two = Diagnostics::fingerprint('same message');

        $this->assertNotSame($one, $two, 'instalações distintas não podem colidir');
    }

    /** Correlações continuam distintas por request, mesmo com chave fixa. */
    public function test_correlations_remain_per_request(): void
    {
        Diagnostics::setFingerprintKey(self::validKey());

        $a = Diagnostics::report(Diagnostics::CATEGORY_API_ERROR, 'GetClients');
        $b = Diagnostics::report(Diagnostics::CATEGORY_API_ERROR, 'GetClients');

        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}\z/', $a);
    }

    // ---------------------------------------------------------------
    // Provisionamento (ativação/upgrade)
    // ---------------------------------------------------------------

    public function test_provisioning_creates_a_key_when_absent(): void
    {
        if (!defined('WHMCS')) {
            define('WHMCS', true);
        }
        require_once dirname(__DIR__, 2) . '/nt_mcp.php';

        $this->assertArrayNotHasKey(Diagnostics::KEY_SETTING, \WHMCS\Config\Setting::$store);

        nt_mcp_provision_diagnostics_key();

        $stored = \WHMCS\Config\Setting::$store[Diagnostics::KEY_SETTING] ?? null;
        $this->assertTrue(Diagnostics::isValidKey($stored));
    }

    /** Chave válida existente NUNCA é rotacionada em silêncio. */
    public function test_provisioning_preserves_an_existing_valid_key(): void
    {
        require_once dirname(__DIR__, 2) . '/nt_mcp.php';

        $existing = self::validKey();
        \WHMCS\Config\Setting::$store[Diagnostics::KEY_SETTING] = $existing;

        nt_mcp_provision_diagnostics_key();

        $this->assertSame($existing, \WHMCS\Config\Setting::$store[Diagnostics::KEY_SETTING]);
    }

    public function test_provisioning_preserves_an_invalid_key_and_runtime_omits_fingerprint(): void
    {
        require_once dirname(__DIR__, 2) . '/nt_mcp.php';

        \WHMCS\Config\Setting::$store[Diagnostics::KEY_SETTING] = 'garbage';

        nt_mcp_provision_diagnostics_key();

        $this->assertSame('garbage', \WHMCS\Config\Setting::$store[Diagnostics::KEY_SETTING]);
        Diagnostics::resetFingerprintKey();
        $this->assertNull(Diagnostics::fingerprint('Client Not Found'));
    }

    public function test_rng_failure_leaves_the_key_absent(): void
    {
        require_once dirname(__DIR__, 2) . '/nt_mcp.php';

        nt_mcp_provision_diagnostics_key(static function (): string {
            throw new \RuntimeException('rng failed');
        });

        $this->assertArrayNotHasKey(Diagnostics::KEY_SETTING, \WHMCS\Config\Setting::$store);
    }

    public function test_atomic_store_failure_fails_closed_without_exposing_the_candidate(): void
    {
        require_once dirname(__DIR__, 2) . '/nt_mcp.php';
        $candidate = self::validKey();
        DiagnosticsKeyStore::setClaimOverrideForTests(static function (): never {
            throw new \RuntimeException('database unavailable');
        });

        $this->assertNull(nt_mcp_provision_diagnostics_key(static fn(): string => $candidate));
        $this->assertNull(Diagnostics::fingerprint('cause'));
        $this->assertStringNotContainsString($candidate, ErrorLogSpy::contents());
    }

    public function test_two_real_processes_converge_on_one_winner(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the process concurrency probe');
        }

        require_once dirname(__DIR__, 2) . '/nt_mcp.php';
        $stateFile = tempnam(sys_get_temp_dir(), 'nt_mcp_key_state_');
        $this->assertNotFalse($stateFile);
        file_put_contents($stateFile, '');
        $outputs = [$stateFile . '.one', $stateFile . '.two'];
        $candidates = [self::validKey(), self::validKey(32)];
        $children = [];

        foreach ($candidates as $index => $candidate) {
            $pid = pcntl_fork();
            $this->assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                DiagnosticsKeyStore::setClaimOverrideForTests(static function (string $proposed) use ($stateFile): ?string {
                    $handle = fopen($stateFile, 'c+');
                    if ($handle === false || !flock($handle, LOCK_EX)) {
                        return null;
                    }
                    rewind($handle);
                    $winner = trim((string) stream_get_contents($handle));
                    if ($winner === '') {
                        usleep(20000); // amplia a janela de disputa entre processos
                        $winner = $proposed;
                        ftruncate($handle, 0);
                        rewind($handle);
                        fwrite($handle, $winner);
                        fflush($handle);
                    }
                    flock($handle, LOCK_UN);
                    fclose($handle);

                    return $winner;
                });
                $winner = nt_mcp_provision_diagnostics_key(static fn(): string => $candidate);
                file_put_contents($outputs[$index], (string) $winner);
                exit($winner === null ? 1 : 0);
            }
            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $first = (string) file_get_contents($outputs[0]);
        $second = (string) file_get_contents($outputs[1]);
        $this->assertTrue(Diagnostics::isValidKey($first));
        $this->assertSame($first, $second);
        $this->assertContains($first, $candidates);

        foreach ($outputs as $output) {
            @unlink($output);
        }
        @unlink($stateFile);
    }

    public function test_production_store_uses_atomic_insert_then_rereads_the_winner(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Whmcs/DiagnosticsKeyStore.php');

        $this->assertStringContainsString("->insertOrIgnore([", $source);
        $this->assertStringContainsString("->where('setting', Diagnostics::KEY_SETTING)", $source);
        $this->assertStringNotContainsString('Setting::setValue', $source);
    }

    public function test_activation_never_exposes_the_diagnostics_key(): void
    {
        require_once dirname(__DIR__, 2) . '/nt_mcp.php';

        $result = nt_mcp_activate();
        $key = \WHMCS\Config\Setting::$store[Diagnostics::KEY_SETTING] ?? null;

        $this->assertSame('success', $result['status'] ?? null);
        $this->assertTrue(Diagnostics::isValidKey($key));
        $this->assertStringNotContainsString((string) $key, json_encode($result));
        $this->assertStringNotContainsString((string) $key, ErrorLogSpy::contents());
    }

    public function test_deactivation_preserves_the_diagnostics_key(): void
    {
        require_once dirname(__DIR__, 2) . '/nt_mcp.php';
        $key = self::validKey();
        \WHMCS\Config\Setting::$store[Diagnostics::KEY_SETTING] = $key;

        nt_mcp_deactivate();

        $this->assertSame($key, \WHMCS\Config\Setting::$store[Diagnostics::KEY_SETTING]);
    }

    /** A chave nunca é ecoada no log. */
    public function test_key_is_never_written_to_the_log(): void
    {
        $key = self::validKey();
        Diagnostics::setFingerprintKey($key);

        Diagnostics::report(Diagnostics::CATEGORY_API_ERROR, 'GetClients', new \RuntimeException('boom'));

        $this->assertStringNotContainsString($key, ErrorLogSpy::contents());
    }
}
