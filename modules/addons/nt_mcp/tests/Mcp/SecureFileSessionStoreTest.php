<?php

declare(strict_types=1);

namespace NtMcp\Tests\Mcp;

use NtMcp\Mcp\SecureFileSessionStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SecureFileSessionStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nt-sess-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        array_map(static fn($f) => @unlink($f), glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    #[Test]
    public function directory_is_forced_to_0700_even_if_created_loose(): void
    {
        mkdir($this->dir, 0775, true);
        new SecureFileSessionStore($this->dir, 60);
        clearstatcache();
        $this->assertSame(0700, fileperms($this->dir) & 0777);
    }

    #[Test]
    public function session_files_are_written_with_0600(): void
    {
        $store = new SecureFileSessionStore($this->dir, 60);
        $id = Uuid::v4();
        $this->assertTrue($store->write($id, '{"a":1}'));
        clearstatcache();
        $this->assertSame(0600, fileperms($this->dir . '/' . $id->toRfc4122()) & 0777);
        $this->assertSame('{"a":1}', $store->read($id));
    }

    #[Test]
    public function protect_throws_instead_of_returning_when_chmod_fails(): void
    {
        $store = new class($this->dir, 60) extends SecureFileSessionStore {
            public function exposeProtect(string $path): void { $this->protect($path); }
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be protected');
        $store->exposeProtect($this->dir . '/does-not-exist');
    }

    #[Test]
    public function write_throws_instead_of_returning_false_when_the_underlying_write_fails(): void
    {
        $store = new SecureFileSessionStore($this->dir, 60);
        $id = Uuid::v4();

        // Diretório sem permissão de escrita para o dono: file_put_contents()
        // do FileSessionStore falha, parent::write() devolve false — o SDK
        // ignora esse bool (Session::save() descarta o retorno), então isto
        // TEM que virar exceção, nunca um `false` engolido silenciosamente.
        chmod($this->dir, 0500);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Session write failed');
            $store->write($id, '{"a":1}');
        } finally {
            chmod($this->dir, 0700);
        }
    }
}
