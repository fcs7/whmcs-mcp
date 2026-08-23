<?php

declare(strict_types=1);

namespace NtMcp\Tests\Mcp;

use NtMcp\Mcp\SessionLock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nt-lock-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        array_map(static fn($f) => @unlink($f), glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    #[Test]
    public function file_name_is_deterministic_and_derived_from_a_hash(): void
    {
        $id = 'f1d2d2f9-24a8-4f1c-9f3e-0b1c2d3e4f50';
        $this->assertSame(SessionLock::fileFor($id), SessionLock::fileFor($id));
        $this->assertMatchesRegularExpression('/^sess-[0-9a-f]{64}\\.lock$/', SessionLock::fileFor($id));
        $this->assertNotSame(SessionLock::fileFor($id), SessionLock::fileFor($id . 'x'));
    }

    /**
     * Regressão do modelo antigo: com 64 faixas (`crc32 % 64`), duas sessões
     * DIFERENTES podiam cair na mesma faixa e se bloquear mutuamente — com o
     * lock segurado pelo request inteiro, um cliente parado derrubava outro sem
     * relação nenhuma com 503. Um arquivo por sessão não colide: qualquer par de
     * ids distintos precisa ser concorrente.
     */
    #[Test]
    public function no_pair_of_distinct_sessions_ever_shares_a_lock_file(): void
    {
        $names = [];
        for ($i = 0; $i < 200; $i++) {
            $names[] = SessionLock::fileFor(sprintf('aaaaaaaa-0000-4000-8000-%012d', $i));
        }
        $this->assertCount(200, array_unique($names));
    }

    #[Test]
    public function same_session_is_serialized_and_released(): void
    {
        $id = 'f1d2d2f9-24a8-4f1c-9f3e-0b1c2d3e4f50';
        $a = new SessionLock($this->dir);
        $b = new SessionLock($this->dir);

        $this->assertTrue($a->acquire($id));
        $this->assertFalse($b->acquire($id, 50), 'segundo request da mesma sessão deve esperar/falhar');

        $a->release();
        $this->assertTrue($b->acquire($id, 50));
        $b->release();
    }

    #[Test]
    public function different_sessions_stay_concurrent(): void
    {
        $first = 'aaaaaaaa-0000-4000-8000-000000000001';
        $second = 'aaaaaaaa-0000-4000-8000-000000000002';
        $a = new SessionLock($this->dir);
        $b = new SessionLock($this->dir);

        $this->assertTrue($a->acquire($first));
        $this->assertTrue($b->acquire($second, 50));
        $a->release();
        $b->release();
    }

    #[Test]
    public function header_value_never_becomes_a_file_name(): void
    {
        $lock = new SessionLock($this->dir);
        $this->assertTrue($lock->acquire('../../etc/passwd'));
        $lock->release();

        $files = array_map('basename', glob($this->dir . '/*'));
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^sess-[0-9a-f]{64}\\.lock$/', $files[0]);
    }

    #[Test]
    public function lock_files_and_directory_are_private(): void
    {
        $lock = new SessionLock($this->dir);
        $this->assertTrue($lock->acquire('abc'));
        $lock->release();

        clearstatcache();
        $this->assertSame(0700, fileperms($this->dir) & 0777);
        foreach (glob($this->dir . '/*') as $file) {
            $this->assertSame(0600, fileperms($file) & 0777);
        }
    }

    /**
     * Regressão (desenv, 2026-08-23): a PRIMEIRA request de cada sessão cria o
     * arquivo com 0644 (umask 022) e precisa corrigir o modo E prosseguir. A
     * verificação relia `fileperms()` sem `clearstatcache()`, recebia o valor
     * cacheado de ANTES do chmod e recusava o lock — o cliente MCP via 503
     * "Session busy; retry" toda vez que uma sessão nova caía num arquivo ainda
     * inexistente, sem nenhuma contenção real.
     */
    #[Test]
    public function acquires_and_repairs_a_lock_file_left_with_loose_permissions(): void
    {
        $id = 'c0ffee00-0000-4000-8000-000000000042';
        @mkdir($this->dir, 0700, true);
        $path = $this->dir . '/' . SessionLock::fileFor($id);
        file_put_contents($path, '');
        chmod($path, 0644);
        clearstatcache(true, $path);
        self::assertSame(0644, fileperms($path) & 0777, 'pré-condição: arquivo frouxo');

        $lock = new SessionLock($this->dir);
        self::assertTrue($lock->acquire($id), 'lock deve ser adquirido, não recusado');
        self::assertNull($lock->lastFailure());
        $lock->release();

        clearstatcache(true, $path);
        self::assertSame(0600, fileperms($path) & 0777, 'modo deve ter sido corrigido');
    }

    #[Test]
    public function unusable_directory_fails_closed(): void
    {
        $file = $this->dir;
        @mkdir(dirname($file), 0700, true);
        file_put_contents($file, 'not a dir');
        $lock = new SessionLock($file);
        $this->assertFalse($lock->acquire('abc', 10));
        @unlink($file);
    }

    #[Test]
    public function last_failure_distinguishes_timeout_from_structural_failure(): void
    {
        $id = 'f1d2d2f9-24a8-4f1c-9f3e-0b1c2d3e4f50';
        $holder = new SessionLock($this->dir);
        $this->assertTrue($holder->acquire($id));
        $this->assertNull($holder->lastFailure());

        $blocked = new SessionLock($this->dir);
        $this->assertFalse($blocked->acquire($id, 30));
        $this->assertSame('timeout', $blocked->lastFailure());
        $holder->release();

        $unusable = new SessionLock($this->dir . '-not-a-directory-parent/child');
        file_put_contents($this->dir . '-not-a-directory-parent', 'blocks mkdir');
        $this->assertFalse($unusable->acquire('abc', 10));
        $this->assertSame('open_failed', $unusable->lastFailure());
        @unlink($this->dir . '-not-a-directory-parent');
    }

    #[Test]
    public function last_failure_resets_to_null_on_a_later_successful_acquire(): void
    {
        $id = 'f1d2d2f9-24a8-4f1c-9f3e-0b1c2d3e4f50';
        $lock = new SessionLock($this->dir);
        $holder = new SessionLock($this->dir);
        $this->assertTrue($holder->acquire($id));
        $this->assertFalse($lock->acquire($id, 30));
        $this->assertSame('timeout', $lock->lastFailure());
        $holder->release();

        $this->assertTrue($lock->acquire($id, 200));
        $this->assertNull($lock->lastFailure());
        $lock->release();
    }

    /**
     * O GC só pode remover arquivo de sessão EXPIRADA e que ninguém esteja
     * segurando. Remover um arquivo em uso faria dois processos travarem inodes
     * diferentes — ou seja, nenhuma exclusão mútua.
     */
    #[Test]
    public function garbage_collection_removes_only_stale_and_unheld_files(): void
    {
        $stale = 'aaaaaaaa-0000-4000-8000-00000000dead';
        $fresh = 'aaaaaaaa-0000-4000-8000-00000000beef';
        $held  = 'aaaaaaaa-0000-4000-8000-00000000ca11';

        $lock = new SessionLock($this->dir);
        foreach ([$stale, $fresh, $held] as $id) {
            $this->assertTrue($lock->acquire($id));
            $lock->release();
        }

        $holder = new SessionLock($this->dir);
        $this->assertTrue($holder->acquire($held));

        // O envelhecimento vem DEPOIS do último `acquire()`. Antes ele vinha
        // no meio, e o GC oportunista (1/20) de um acquire seguinte podia
        // limpar o arquivo stale antes do GC forçado — teste falhava 1 em 20
        // execuções. Envelhecer no fim torna a corrida impossível.
        //
        // O `held` também é envelhecido: assim ele passa no teste de idade e só
        // o flock o protege — é essa proteção que este caso exercita.
        $stalePath = $this->dir . '/' . SessionLock::fileFor($stale);
        $heldPath = $this->dir . '/' . SessionLock::fileFor($held);
        touch($stalePath, time() - SessionLock::STALE_AFTER_SECONDS - 60);
        touch($heldPath, time() - SessionLock::STALE_AFTER_SECONDS - 60);

        $removed = (new SessionLock($this->dir))->collectGarbage(force: true);
        $holder->release();

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($stalePath);
        $this->assertFileExists($heldPath, 'arquivo em uso nunca é removido');
        $this->assertFileExists($this->dir . '/' . SessionLock::fileFor($fresh));
    }

    /** `acquire()` mantém a sessão viva para o GC — senão sessão longa se apaga sozinha. */
    #[Test]
    public function acquire_refreshes_mtime_so_active_sessions_survive_gc(): void
    {
        $id = 'aaaaaaaa-0000-4000-8000-0000000000f0';
        $lock = new SessionLock($this->dir);
        $this->assertTrue($lock->acquire($id));
        $lock->release();

        $path = $this->dir . '/' . SessionLock::fileFor($id);
        touch($path, time() - SessionLock::STALE_AFTER_SECONDS - 60);

        $this->assertTrue($lock->acquire($id));
        $lock->release();

        clearstatcache(true, $path);
        $this->assertGreaterThan(time() - 60, filemtime($path));
        $this->assertSame(0, (new SessionLock($this->dir))->collectGarbage(force: true));
    }
}
