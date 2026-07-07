<?php
// tests/ServerLockTest.php
namespace NtMcp\Tests;

use NtMcp\Server;
use PHPUnit\Framework\TestCase;

/**
 * Cobre o método puro Server::acquireLockWithTimeout() (FASE 1 — lock com
 * timeout). Garante que:
 *  - lock livre é adquirido imediatamente;
 *  - lock ocupado por outro handle faz o segundo esperar e retornar false
 *    dentro do timeout (nunca bloqueia indefinidamente → evita cascata 504).
 */
class ServerLockTest extends TestCase
{
    private string $lockFile;

    protected function setUp(): void
    {
        $this->lockFile = sys_get_temp_dir() . '/nt_mcp_lock_test_' . bin2hex(random_bytes(6)) . '.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->lockFile);
    }

    private function invoke($handle, float $timeout): bool
    {
        // PHP 8.1+ torna métodos privados invocáveis via reflection sem
        // setAccessible() (que virou no-op/deprecated em 8.5).
        $m = new \ReflectionMethod(Server::class, 'acquireLockWithTimeout');
        return (bool) $m->invoke(null, $handle, $timeout);
    }

    public function test_acquires_free_lock_immediately(): void
    {
        $h = fopen($this->lockFile, 'c');
        $this->assertNotFalse($h);

        $start = microtime(true);
        $ok = $this->invoke($h, 5.0);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($ok, 'lock livre deve ser adquirido');
        $this->assertLessThan(0.5, $elapsed, 'lock livre deve retornar quase imediatamente');

        flock($h, LOCK_UN);
        fclose($h);
    }

    public function test_times_out_when_lock_held_by_another_handle(): void
    {
        // Handle A segura o lock exclusivo.
        $a = fopen($this->lockFile, 'c');
        $this->assertNotFalse($a);
        $this->assertTrue(flock($a, LOCK_EX | LOCK_NB), 'handle A deve pegar o lock');

        // Handle B (fopen separado) tenta adquirir com timeout curto e deve
        // falhar por timeout — sem bloquear além do deadline.
        $b = fopen($this->lockFile, 'c');
        $this->assertNotFalse($b);

        $timeout = 0.5;
        $start = microtime(true);
        $ok = $this->invoke($b, $timeout);
        $elapsed = microtime(true) - $start;

        $this->assertFalse($ok, 'lock ocupado deve retornar false por timeout');
        $this->assertGreaterThanOrEqual($timeout - 0.05, $elapsed, 'deve ter esperado ~o timeout');
        $this->assertLessThan($timeout + 1.0, $elapsed, 'não deve bloquear muito além do timeout');

        flock($a, LOCK_UN);
        fclose($a);
        fclose($b);
    }

    public function test_acquires_after_lock_released(): void
    {
        $a = fopen($this->lockFile, 'c');
        flock($a, LOCK_EX | LOCK_NB);
        flock($a, LOCK_UN); // libera imediatamente
        fclose($a);

        $b = fopen($this->lockFile, 'c');
        $ok = $this->invoke($b, 5.0);
        $this->assertTrue($ok, 'lock liberado deve ser adquirível');

        flock($b, LOCK_UN);
        fclose($b);
    }
}
