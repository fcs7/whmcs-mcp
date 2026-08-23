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

    /** Dois ids em faixas distintas — calculados, não chutados. */
    private static function distinctBucketIds(): array
    {
        $first = 'aaaaaaaa-0000-4000-8000-000000000001';
        $i = 2;
        do {
            $second = sprintf('aaaaaaaa-0000-4000-8000-%012d', $i++);
        } while (SessionLock::bucketFor($second) === SessionLock::bucketFor($first));

        return [$first, $second];
    }

    #[Test]
    public function bucket_is_deterministic_and_bounded(): void
    {
        $id = 'f1d2d2f9-24a8-4f1c-9f3e-0b1c2d3e4f50';
        $this->assertSame(SessionLock::bucketFor($id), SessionLock::bucketFor($id));
        $this->assertGreaterThanOrEqual(0, SessionLock::bucketFor($id));
        $this->assertLessThan(SessionLock::BUCKETS, SessionLock::bucketFor($id));
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
        [$first, $second] = self::distinctBucketIds();
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
        $this->assertMatchesRegularExpression('/^bucket-\d{2}\.lock$/', $files[0]);
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
}
