<?php

declare(strict_types=1);

namespace NtMcp\Tests\Mcp;

use NtMcp\Mcp\RuntimeDirs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuntimeDirsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nt-dirs-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (RuntimeDirs::SUBDIRS as $sub) {
            @rmdir($this->dir . '/' . $sub);
        }
        @chmod($this->dir, 0700);
        @rmdir($this->dir);
        @unlink($this->dir);
    }

    #[Test]
    public function provision_creates_every_runtime_directory_with_0700(): void
    {
        $this->assertNull(RuntimeDirs::provision($this->dir));
        clearstatcache();
        $this->assertSame(0700, fileperms($this->dir) & 0777);
        foreach (RuntimeDirs::SUBDIRS as $sub) {
            $this->assertDirectoryExists($this->dir . '/' . $sub);
            $this->assertSame(0700, fileperms($this->dir . '/' . $sub) & 0777, $sub);
        }
    }

    #[Test]
    public function provision_hardens_pre_existing_loose_directories(): void
    {
        mkdir($this->dir, 0755, true);
        mkdir($this->dir . '/sessions', 0775);
        mkdir($this->dir . '/cache', 0777);

        $this->assertNull(RuntimeDirs::provision($this->dir));
        clearstatcache();
        $this->assertSame(0700, fileperms($this->dir . '/sessions') & 0777);
        $this->assertSame(0700, fileperms($this->dir . '/cache') & 0777);
        $this->assertSame(0700, fileperms($this->dir . '/session-locks') & 0777);
    }

    #[Test]
    public function provision_fails_when_a_target_cannot_be_a_directory(): void
    {
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/sessions', 'file in the way');

        $error = RuntimeDirs::provision($this->dir);
        $this->assertNotNull($error);
        $this->assertStringContainsString('sessions', $error);
        @unlink($this->dir . '/sessions');
    }
}
