<?php

declare(strict_types=1);

namespace NtMcp\Tests\Mcp;

use NtMcp\Mcp\SessionLock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reteste do item "Session busy (503)" da issue #27, como teste permanente.
 *
 * O SessionLockTest cobre contenção DENTRO de um processo (dois handles do
 * mesmo PID disputando flock). O bug de produção, porém, era entre PROCESSOS
 * PHP-FPM: (i) dois workers com o mesmo `Mcp-Session-Id` e (ii) o falso 503
 * na PRIMEIRA request de cada sessão, quando `fopen('c')` criava o arquivo com
 * 0644 (umask 022 do Plesk) e a verificação pós-chmod lia o stat CACHEADO.
 * Estes cenários só se reproduzem com flock entre PIDs reais e com umask
 * hostil — é o que este arquivo faz, no mesmo espírito de subprocesso do
 * McpEndpointHttpTest.
 */
final class SessionLockConcurrencyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nt-lock-conc-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        array_map(static fn($f) => @unlink($f), glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    /**
     * Sobe um PHP filho que adquire o lock da sessão e o segura até receber
     * qualquer byte no stdin (rendezvous determinístico, sem sleeps mágicos).
     *
     * @return array{proc: resource, stdin: resource, stdout: resource}
     */
    private function spawnHolder(string $sessionId): array
    {
        $autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
        $this->assertIsString($autoload);

        $code = <<<'PHP'
            require $argv[1];
            $lock = new NtMcp\Mcp\SessionLock($argv[2]);
            if (!$lock->acquire($argv[3])) {
                fwrite(STDOUT, "FAIL:" . $lock->lastFailure());
                exit(1);
            }
            fwrite(STDOUT, "HELD");
            fflush(STDOUT);
            fgets(STDIN); // segura o lock até o pai mandar soltar
            $lock->release();
            fwrite(STDOUT, "RELEASED");
            PHP;

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', '-r', $code, '--', $autoload, $this->dir, $sessionId],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);

        // Espera o filho confirmar posse antes de qualquer asserção do pai.
        $banner = fread($pipes[1], 4);
        $this->assertSame('HELD', $banner, 'filho não conseguiu adquirir o lock');

        return ['proc' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1]];
    }

    #[Test]
    public function lock_held_by_another_real_process_times_out_and_frees_after_release(): void
    {
        $id = 'e2e-conc-' . bin2hex(random_bytes(8));
        $holder = $this->spawnHolder($id);

        try {
            $local = new SessionLock($this->dir);

            // Enquanto o outro PID segura: contenção real, nunca falha estrutural.
            $this->assertFalse($local->acquire($id, 200), 'flock entre processos não está excluindo');
            $this->assertSame('timeout', $local->lastFailure(), 'contenção legítima não pode logar open_failed');

            // Solta no filho e o pai deve conseguir na sequência.
            fwrite($holder['stdin'], "go\n");
            fflush($holder['stdin']);
            $this->assertSame('RELEASED', fread($holder['stdout'], 8));

            $this->assertTrue($local->acquire($id, SessionLock::ACQUIRE_TIMEOUT_MS), 'lock não foi liberado entre processos');
            $local->release();
        } finally {
            @fclose($holder['stdin']);
            @fclose($holder['stdout']);
            proc_close($holder['proc']);
        }
    }

    #[Test]
    public function sessions_of_distinct_ids_do_not_contend_across_processes(): void
    {
        // Regressão do modelo de 64 faixas: sessão parada num PID não pode
        // derrubar sessão DIFERENTE noutro PID com 503.
        $holder = $this->spawnHolder('e2e-conc-a-' . bin2hex(random_bytes(8)));

        try {
            $other = new SessionLock($this->dir);
            $this->assertTrue(
                $other->acquire('e2e-conc-b-' . bin2hex(random_bytes(8)), 200),
                'sessão distinta foi serializada por lock alheio'
            );
            $other->release();
        } finally {
            fwrite($holder['stdin'], "go\n");
            @fclose($holder['stdin']);
            @fclose($holder['stdout']);
            proc_close($holder['proc']);
        }
    }

    #[Test]
    public function first_acquire_of_a_brand_new_session_succeeds_under_plesk_umask(): void
    {
        // O falso 503: umask 022 faz fopen('c') criar o arquivo 0644; sem o
        // clearstatcache() entre chmod e fileperms, a PRIMEIRA request de cada
        // sessão morria com "Session busy" sem contenção nenhuma.
        $previous = umask(022);

        try {
            $lock = new SessionLock($this->dir);
            $id = 'e2e-fresh-' . bin2hex(random_bytes(8));

            $this->assertTrue($lock->acquire($id, 200), 'primeira request de sessão nova levou 503 espúrio');
            $this->assertNull($lock->lastFailure());

            clearstatcache(true, $this->dir . '/' . SessionLock::fileFor($id));
            $this->assertSame(
                0600,
                fileperms($this->dir . '/' . SessionLock::fileFor($id)) & 0777,
                'arquivo de lock novo precisa terminar 0600 mesmo sob umask 022'
            );
            $lock->release();
        } finally {
            umask($previous);
        }
    }
}
