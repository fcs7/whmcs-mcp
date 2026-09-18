<?php

declare(strict_types=1);

namespace NtMcp\Tests\Translation;

use NtMcp\Translation\TranslationBackup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranslationBackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nt_mcp_translation_backup_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $this->removeRecursive($this->dir);
        }
    }

    private function removeRecursive(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    #[Test]
    public function appends_jsonl_entry_with_expected_shape(): void
    {
        $backup = new TranslationBackup($this->dir);

        $backup->append(['ts' => '2026-01-01T00:00:00Z', 'master_id' => 42, 'action' => 'insert', 'target_language' => 'english', 'previous' => null]);

        $file = $this->dir . '/translation-backups/emailtemplates-english-' . gmdate('Ymd') . '.jsonl';
        $this->assertFileExists($file);

        $lines = array_filter(explode("\n", (string) file_get_contents($file)));
        $this->assertCount(1, $lines);

        $decoded = json_decode((string) reset($lines), true);
        $this->assertSame(42, $decoded['master_id']);
        $this->assertSame('insert', $decoded['action']);
        $this->assertSame('english', $decoded['target_language']);
        $this->assertNull($decoded['previous']);
    }

    #[Test]
    public function appends_multiple_entries_to_same_day_file(): void
    {
        $backup = new TranslationBackup($this->dir);

        $backup->append(['master_id' => 1, 'target_language' => 'english']);
        $backup->append(['master_id' => 2, 'target_language' => 'english']);

        $file = $this->dir . '/translation-backups/emailtemplates-english-' . gmdate('Ymd') . '.jsonl';
        $lines = array_filter(explode("\n", (string) file_get_contents($file)));
        $this->assertCount(2, $lines);
    }

    #[Test]
    public function filename_includes_the_target_language(): void
    {
        $backup = new TranslationBackup($this->dir);

        $backup->append(['master_id' => 1, 'target_language' => 'portuguese-br']);

        $file = $this->dir . '/translation-backups/emailtemplates-portuguese-br-' . gmdate('Ymd') . '.jsonl';
        $this->assertFileExists($file);
    }

    #[Test]
    public function throws_when_target_language_is_missing(): void
    {
        $backup = new TranslationBackup($this->dir);

        $this->expectException(\RuntimeException::class);
        $backup->append(['master_id' => 1]);
    }

    #[Test]
    public function throws_when_target_language_has_an_unsafe_shape(): void
    {
        $backup = new TranslationBackup($this->dir);

        $this->expectException(\RuntimeException::class);
        $backup->append(['master_id' => 1, 'target_language' => '../../etc']);
    }

    #[Test]
    public function directory_is_0700_and_file_is_0600(): void
    {
        $backup = new TranslationBackup($this->dir);
        $backup->append(['master_id' => 1, 'target_language' => 'english']);

        $subdir = $this->dir . '/translation-backups';
        $file = $subdir . '/emailtemplates-english-' . gmdate('Ymd') . '.jsonl';

        clearstatcache(true, $subdir);
        clearstatcache(true, $file);

        $this->assertSame(0700, fileperms($subdir) & 0777);
        $this->assertSame(0600, fileperms($file) & 0777);
    }

    #[Test]
    public function throws_when_directory_cannot_be_created(): void
    {
        // Um ARQUIVO no lugar onde o diretório deveria existir impede mkdir().
        mkdir($this->dir, 0700, true);
        $blocker = $this->dir . '/translation-backups';
        file_put_contents($blocker, 'not a directory');

        $backup = new TranslationBackup($this->dir);

        $this->expectException(\RuntimeException::class);
        $backup->append(['master_id' => 1, 'target_language' => 'english']);
    }
}
