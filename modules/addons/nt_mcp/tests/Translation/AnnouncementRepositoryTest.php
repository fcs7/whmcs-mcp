<?php

declare(strict_types=1);

namespace NtMcp\Tests\Translation;

use NtMcp\Translation\AnnouncementRepository;
use NtMcp\Translation\TranslationBackup;
use NtMcp\Translation\TranslationSchemaGuard;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnnouncementRepositoryTest extends TestCase
{
    private const COLUMNS = ['id', 'date', 'title', 'announcement', 'published', 'parentid', 'language'];

    protected function setUp(): void
    {
        FakeCapsule::reset();
    }

    protected function tearDown(): void
    {
        FakeCapsule::reset();
    }

    private function healthyGuard(): TranslationSchemaGuard
    {
        return new TranslationSchemaGuard(new FakeCrmSchemaProbe([
            'tblannouncements' => self::COLUMNS,
        ]));
    }

    private function unavailableGuard(): TranslationSchemaGuard
    {
        return new TranslationSchemaGuard(new FakeCrmSchemaProbe([]));
    }

    private function repo(?TranslationSchemaGuard $guard = null): AnnouncementRepository
    {
        return new AnnouncementRepository($guard ?? $this->healthyGuard());
    }

    private function backup(): TranslationBackup
    {
        return new TranslationBackup(sys_get_temp_dir() . '/nt_mcp_announcement_test_' . uniqid('', true));
    }

    private function seedRows(): void
    {
        FakeCapsule::withRows('tblannouncements', [
            ['id' => 1, 'date' => '2026-01-01', 'title' => 'Manutencao', 'announcement' => '<p>Manutencao programada</p>', 'published' => '1', 'parentid' => 0, 'language' => ''],
            ['id' => 2, 'date' => '2026-01-02', 'title' => 'Maintenance', 'announcement' => '<p>Scheduled maintenance</p>', 'published' => '1', 'parentid' => 1, 'language' => 'english'],
            ['id' => 3, 'date' => '2026-02-01', 'title' => 'Novidade', 'announcement' => 'Nova funcionalidade', 'published' => '0', 'parentid' => 0, 'language' => ''],
        ]);
    }

    #[Test]
    public function schema_unavailable_is_reported_by_every_method(): void
    {
        $repo = $this->repo($this->unavailableGuard());

        $this->assertSame('translation_unavailable', $repo->listAnnouncements(false, 25, 0, 'english')['error_code']);
        $this->assertSame('translation_unavailable', $repo->getAnnouncements([1], 'english')['error_code']);
        $this->assertSame(
            'translation_unavailable',
            $repo->applyBatch([['id' => 1, 'title' => 'x', 'announcement' => 'y', 'expected_hash' => 'absent']], $this->backup(), true, 'english')['error_code']
        );
    }

    #[Test]
    public function invalid_target_language_is_rejected_without_querying_the_database(): void
    {
        $repo = $this->repo();

        $this->assertSame('invalid_target_language', $repo->listAnnouncements(false, 25, 0, 'french')['error_code']);
        $this->assertSame('invalid_target_language', $repo->getAnnouncements([1], 'french')['error_code']);
        $this->assertSame(
            'invalid_target_language',
            $repo->applyBatch([['id' => 1, 'title' => 'x', 'announcement' => 'y', 'expected_hash' => 'absent']], $this->backup(), true, 'french')['error_code']
        );
        $this->assertSame([], FakeCapsule::$calls);
    }

    #[Test]
    public function list_announcements_only_includes_originals(): void
    {
        $this->seedRows();

        $result = $this->repo()->listAnnouncements(false, 25, 0, 'english');

        $ids = array_column($result['items'], 'id');
        $this->assertSame([1, 3], $ids);
    }

    #[Test]
    public function list_announcements_reports_has_target_and_only_missing(): void
    {
        $this->seedRows();

        $all = $this->repo()->listAnnouncements(false, 25, 0, 'english');
        $byId = [];
        foreach ($all['items'] as $item) {
            $byId[$item['id']] = $item;
        }
        $this->assertTrue($byId[1]['has_target']);
        $this->assertFalse($byId[3]['has_target']);
        $this->assertSame('Manutencao', $byId[1]['title']);
        $this->assertSame('2026-01-01', $byId[1]['date']);
        $this->assertSame('1', $byId[1]['published']);

        $missing = $this->repo()->listAnnouncements(true, 25, 0, 'english');
        $this->assertSame([3], array_column($missing['items'], 'id'));
    }

    #[Test]
    public function get_announcements_reports_absent_hash_when_target_missing(): void
    {
        $this->seedRows();

        $result = $this->repo()->getAnnouncements([3], 'english');

        $this->assertNull($result['items'][0]['target']);
        $this->assertSame('absent', $result['items'][0]['target_hash']);
    }

    #[Test]
    public function get_announcements_returns_target_content_and_hash_when_present(): void
    {
        $this->seedRows();

        $result = $this->repo()->getAnnouncements([1], 'english');
        $item = $result['items'][0];

        $this->assertSame('Maintenance', $item['target']['title']);
        $expectedHash = hash('sha256', 'Maintenance' . "\0" . '<p>Scheduled maintenance</p>');
        $this->assertSame($expectedHash, $item['target_hash']);
    }

    #[Test]
    public function get_announcements_flags_not_found_and_non_source(): void
    {
        $this->seedRows();

        $result = $this->repo()->getAnnouncements([999, 2], 'english');

        $this->assertSame('not_found', $result['items'][0]['error']);
        $this->assertSame('not_translatable', $result['items'][1]['error']);
    }

    #[Test]
    public function apply_batch_insert_copies_date_and_published(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            [['id' => 3, 'title' => 'News', 'announcement' => 'New feature', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('success', $result['result']);
        $this->assertSame('insert', $result['items'][0]['action']);

        $insert = null;
        foreach (FakeCapsule::$mutations as $mutation) {
            if ($mutation['verb'] === 'INSERT') {
                $insert = $mutation;
            }
        }
        $this->assertNotNull($insert);
        $this->assertSame(3, $insert['values']['parentid']);
        $this->assertSame('english', $insert['values']['language']);
        $this->assertSame('2026-02-01', $insert['values']['date']);
        $this->assertSame('0', $insert['values']['published']);
        $this->assertSame('News', $insert['values']['title']);
        $this->assertSame('New feature', $insert['values']['announcement']);
    }

    #[Test]
    public function apply_batch_update_touches_only_title_and_announcement(): void
    {
        $this->seedRows();
        $repo = $this->repo();
        $hash = hash('sha256', 'Maintenance' . "\0" . '<p>Scheduled maintenance</p>');

        $result = $repo->applyBatch(
            [['id' => 1, 'title' => 'Maintenance!', 'announcement' => '<p>Scheduled maintenance!</p>', 'expected_hash' => $hash]],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('update', $result['items'][0]['action']);
        $update = null;
        foreach (FakeCapsule::$mutations as $mutation) {
            if ($mutation['verb'] === 'UPDATE') {
                $update = $mutation;
            }
        }
        $this->assertNotNull($update);
        $this->assertSame(['title', 'announcement'], array_keys($update['values']));
    }

    #[Test]
    public function apply_batch_rejects_hash_conflict_without_writing(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            [['id' => 1, 'title' => 'x', 'announcement' => 'y', 'expected_hash' => 'wrong']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('hash_conflict', $result['error_code']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_batch_never_writes_the_original_row(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        $repo->applyBatch(
            [['id' => 3, 'title' => 'News', 'announcement' => 'New feature', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        foreach (FakeCapsule::$mutations as $mutation) {
            if (($mutation['values']['id'] ?? null) === 3 || (($mutation['values']['parentid'] ?? null) === 0)) {
                $this->fail('a linha original nunca pode ser escrita');
            }
        }
        $this->assertTrue(true);
    }

    #[Test]
    public function apply_batch_dry_run_never_writes(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            [['id' => 3, 'title' => 'News', 'announcement' => 'New feature', 'expected_hash' => 'absent']],
            $this->backup(),
            true,
            'english'
        );

        $this->assertTrue($result['dry_run']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_batch_rolls_back_the_whole_batch_when_last_item_is_invalid(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            [
                ['id' => 3, 'title' => 'News', 'announcement' => 'New feature', 'expected_hash' => 'absent'],
                ['id' => 1, 'title' => 'x', 'announcement' => 'y', 'expected_hash' => 'wrong'],
            ],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('error', $result['result']);
        $this->assertSame([], FakeCapsule::$mutations, 'o rollback deve reverter tambem a escrita do item valido anterior');
    }

    #[Test]
    public function apply_batch_backup_failure_rolls_back_and_writes_nothing(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        $blockedDir = sys_get_temp_dir() . '/nt_mcp_announcement_backup_block_' . uniqid('', true);
        mkdir($blockedDir, 0700, true);
        file_put_contents($blockedDir . '/translation-backups', 'not a directory');
        $brokenBackup = new TranslationBackup($blockedDir);

        $this->expectException(\RuntimeException::class);
        try {
            $repo->applyBatch(
                [['id' => 3, 'title' => 'News', 'announcement' => 'New feature', 'expected_hash' => 'absent']],
                $brokenBackup,
                false,
                'english'
            );
        } finally {
            $this->assertSame([], FakeCapsule::$mutations);
        }
    }

    #[Test]
    public function collection_mode_works_for_listing_and_get(): void
    {
        $this->seedRows();
        FakeCapsule::$collectionTables = ['tblannouncements'];

        $result = $this->repo()->listAnnouncements(false, 25, 0, 'english');
        $this->assertSame([1, 3], array_column($result['items'], 'id'));

        $get = $this->repo()->getAnnouncements([1], 'english');
        $this->assertSame('Maintenance', $get['items'][0]['target']['title']);
    }
}
