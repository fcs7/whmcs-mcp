<?php

declare(strict_types=1);

namespace NtMcp\Tests\Translation;

use NtMcp\Translation\KnowledgebaseRepository;
use NtMcp\Translation\TranslationBackup;
use NtMcp\Translation\TranslationSchemaGuard;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KnowledgebaseRepositoryTest extends TestCase
{
    private const ARTICLE_COLUMNS = ['id', 'title', 'article', 'views', 'votes', 'useful', 'private', 'order', 'parentid', 'language'];

    private const CAT_COLUMNS = ['id', 'parentid', 'name', 'description', 'hidden', 'catid', 'language'];

    protected function setUp(): void
    {
        FakeCapsule::reset();
    }

    protected function tearDown(): void
    {
        FakeCapsule::reset();
    }

    private function guardWith(array $tables): TranslationSchemaGuard
    {
        return new TranslationSchemaGuard(new FakeCrmSchemaProbe($tables));
    }

    private function healthyGuard(): TranslationSchemaGuard
    {
        return $this->guardWith([
            'tblknowledgebase' => self::ARTICLE_COLUMNS,
            'tblknowledgebasecats' => self::CAT_COLUMNS,
        ]);
    }

    private function repo(?TranslationSchemaGuard $guard = null): KnowledgebaseRepository
    {
        return new KnowledgebaseRepository($guard ?? $this->healthyGuard());
    }

    private function backup(): TranslationBackup
    {
        return new TranslationBackup(sys_get_temp_dir() . '/nt_mcp_kb_test_' . uniqid('', true));
    }

    private function seedArticles(): void
    {
        FakeCapsule::withRows('tblknowledgebase', [
            ['id' => 1, 'title' => 'Como configurar', 'article' => '<p>Passo a passo</p>', 'views' => 100, 'votes' => 10, 'useful' => 8, 'private' => '0', 'order' => 5, 'parentid' => 0, 'language' => ''],
            ['id' => 2, 'title' => 'How to configure', 'article' => '<p>Step by step</p>', 'views' => 0, 'votes' => 0, 'useful' => 0, 'private' => '0', 'order' => 5, 'parentid' => 1, 'language' => 'english'],
            ['id' => 3, 'title' => 'Reset de senha', 'article' => 'Instrucoes', 'views' => 20, 'votes' => 1, 'useful' => 1, 'private' => '1', 'order' => 2, 'parentid' => 0, 'language' => ''],
        ]);
    }

    private function seedCategories(): void
    {
        FakeCapsule::withRows('tblknowledgebasecats', [
            ['id' => 1, 'parentid' => 0, 'name' => 'Geral', 'description' => 'Categoria geral', 'hidden' => '0', 'catid' => 0, 'language' => ''],
            ['id' => 2, 'parentid' => 0, 'name' => 'General', 'description' => 'General category', 'hidden' => '0', 'catid' => 1, 'language' => 'english'],
            ['id' => 3, 'parentid' => 1, 'name' => 'Faturamento', 'description' => 'Categoria de faturamento', 'hidden' => '1', 'catid' => 0, 'language' => ''],
        ]);
    }

    // -----------------------------------------------------------
    // Schema guard (isolado por capacidade/tabela)
    // -----------------------------------------------------------

    #[Test]
    public function article_schema_unavailable_is_reported_by_every_method(): void
    {
        $repo = $this->repo($this->guardWith(['tblknowledgebasecats' => self::CAT_COLUMNS]));

        $this->assertSame('translation_unavailable', $repo->listArticles(false, 25, 0, 'english')['error_code']);
        $this->assertSame('translation_unavailable', $repo->getArticles([1], 'english')['error_code']);
        $this->assertSame(
            'translation_unavailable',
            $repo->applyArticleBatch([['id' => 1, 'title' => 'x', 'article' => 'y', 'expected_hash' => 'absent']], $this->backup(), true, 'english')['error_code']
        );
    }

    #[Test]
    public function category_schema_unavailable_does_not_affect_article_capability(): void
    {
        $repo = $this->repo($this->guardWith(['tblknowledgebase' => self::ARTICLE_COLUMNS]));

        $this->assertSame('translation_unavailable', $repo->listCategories(false, 25, 0, 'english')['error_code']);
        $this->assertSame(
            'translation_unavailable',
            $repo->applyCategoryBatch([['id' => 1, 'name' => 'x', 'description' => 'y', 'expected_hash' => 'absent']], $this->backup(), true, 'english')['error_code']
        );

        $this->seedArticles();
        $listResult = $repo->listArticles(false, 25, 0, 'english');
        $this->assertSame('success', $listResult['result']);
    }

    #[Test]
    public function invalid_target_language_is_rejected_without_querying_the_database(): void
    {
        $repo = $this->repo();

        $this->assertSame('invalid_target_language', $repo->listArticles(false, 25, 0, 'french')['error_code']);
        $this->assertSame('invalid_target_language', $repo->getArticles([1], 'french')['error_code']);
        $this->assertSame('invalid_target_language', $repo->listCategories(false, 25, 0, 'french')['error_code']);
        $this->assertSame(
            'invalid_target_language',
            $repo->applyArticleBatch([['id' => 1, 'title' => 'x', 'article' => 'y', 'expected_hash' => 'absent']], $this->backup(), true, 'french')['error_code']
        );
        $this->assertSame(
            'invalid_target_language',
            $repo->applyCategoryBatch([['id' => 1, 'name' => 'x', 'description' => 'y', 'expected_hash' => 'absent']], $this->backup(), true, 'french')['error_code']
        );
        $this->assertSame([], FakeCapsule::$calls);
    }

    // -----------------------------------------------------------
    // Artigos
    // -----------------------------------------------------------

    #[Test]
    public function list_articles_only_includes_originals(): void
    {
        $this->seedArticles();

        $result = $this->repo()->listArticles(false, 25, 0, 'english');

        $this->assertSame([1, 3], array_column($result['items'], 'id'));
    }

    #[Test]
    public function list_articles_reports_excerpt_private_and_has_target(): void
    {
        $this->seedArticles();

        $result = $this->repo()->listArticles(false, 25, 0, 'english');
        $byId = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertTrue($byId[1]['has_target']);
        $this->assertFalse($byId[3]['has_target']);
        $this->assertSame('1', $byId[3]['private']);
        $this->assertSame('<p>Passo a passo</p>', $byId[1]['excerpt']);

        $missing = $this->repo()->listArticles(true, 25, 0, 'english');
        $this->assertSame([3], array_column($missing['items'], 'id'));
    }

    #[Test]
    public function get_articles_reports_absent_and_present_hash(): void
    {
        $this->seedArticles();

        $absent = $this->repo()->getArticles([3], 'english');
        $this->assertNull($absent['items'][0]['target']);
        $this->assertSame('absent', $absent['items'][0]['target_hash']);

        $present = $this->repo()->getArticles([1], 'english');
        $item = $present['items'][0];
        $this->assertSame('How to configure', $item['target']['title']);
        $expectedHash = hash('sha256', 'How to configure' . "\0" . '<p>Step by step</p>');
        $this->assertSame($expectedHash, $item['target_hash']);
    }

    #[Test]
    public function get_articles_flags_not_found_and_non_source(): void
    {
        $this->seedArticles();

        $result = $this->repo()->getArticles([999, 2], 'english');

        $this->assertSame('not_found', $result['items'][0]['error']);
        $this->assertSame('not_translatable', $result['items'][1]['error']);
    }

    #[Test]
    public function apply_article_batch_insert_copies_private_and_order_and_zeroes_counters(): void
    {
        $this->seedArticles();
        $repo = $this->repo();

        $result = $repo->applyArticleBatch(
            [['id' => 3, 'title' => 'Password reset', 'article' => 'Instructions', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

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
        $this->assertSame('1', $insert['values']['private']);
        $this->assertSame(2, $insert['values']['order']);
        $this->assertSame(0, $insert['values']['views']);
        $this->assertSame(0, $insert['values']['votes']);
        $this->assertSame(0, $insert['values']['useful']);
    }

    #[Test]
    public function apply_article_batch_update_touches_only_title_and_article(): void
    {
        $this->seedArticles();
        $repo = $this->repo();
        $hash = hash('sha256', 'How to configure' . "\0" . '<p>Step by step</p>');

        $result = $repo->applyArticleBatch(
            [['id' => 1, 'title' => 'How to configure!', 'article' => '<p>Step by step!</p>', 'expected_hash' => $hash]],
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
        $this->assertSame(['title', 'article'], array_keys($update['values']));
    }

    #[Test]
    public function apply_article_batch_rejects_hash_conflict_without_writing(): void
    {
        $this->seedArticles();
        $repo = $this->repo();

        $result = $repo->applyArticleBatch(
            [['id' => 1, 'title' => 'x', 'article' => 'y', 'expected_hash' => 'wrong']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('hash_conflict', $result['error_code']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_article_batch_dry_run_never_writes(): void
    {
        $this->seedArticles();
        $repo = $this->repo();

        $result = $repo->applyArticleBatch(
            [['id' => 3, 'title' => 'Password reset', 'article' => 'Instructions', 'expected_hash' => 'absent']],
            $this->backup(),
            true,
            'english'
        );

        $this->assertTrue($result['dry_run']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_article_batch_rolls_back_whole_batch_when_last_item_invalid(): void
    {
        $this->seedArticles();
        $repo = $this->repo();

        $result = $repo->applyArticleBatch(
            [
                ['id' => 3, 'title' => 'Password reset', 'article' => 'Instructions', 'expected_hash' => 'absent'],
                ['id' => 1, 'title' => 'x', 'article' => 'y', 'expected_hash' => 'wrong'],
            ],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('error', $result['result']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_article_batch_backup_failure_rolls_back_and_writes_nothing(): void
    {
        $this->seedArticles();
        $repo = $this->repo();

        $blockedDir = sys_get_temp_dir() . '/nt_mcp_kb_article_backup_block_' . uniqid('', true);
        mkdir($blockedDir, 0700, true);
        file_put_contents($blockedDir . '/translation-backups', 'not a directory');
        $brokenBackup = new TranslationBackup($blockedDir);

        $this->expectException(\RuntimeException::class);
        try {
            $repo->applyArticleBatch(
                [['id' => 3, 'title' => 'Password reset', 'article' => 'Instructions', 'expected_hash' => 'absent']],
                $brokenBackup,
                false,
                'english'
            );
        } finally {
            $this->assertSame([], FakeCapsule::$mutations);
        }
    }

    // -----------------------------------------------------------
    // Categorias
    // -----------------------------------------------------------

    #[Test]
    public function list_categories_only_includes_originals(): void
    {
        $this->seedCategories();

        $result = $this->repo()->listCategories(false, 25, 0, 'english');

        $this->assertSame([1, 3], array_column($result['items'], 'id'));
    }

    #[Test]
    public function list_categories_reports_full_text_target_and_hash(): void
    {
        $this->seedCategories();

        $result = $this->repo()->listCategories(false, 25, 0, 'english');
        $byId = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertTrue($byId[1]['has_target']);
        $this->assertSame('General', $byId[1]['target']['name']);
        $expectedHash = hash('sha256', 'General' . "\0" . 'General category');
        $this->assertSame($expectedHash, $byId[1]['target_hash']);

        $this->assertFalse($byId[3]['has_target']);
        $this->assertNull($byId[3]['target']);
        $this->assertSame('absent', $byId[3]['target_hash']);

        $missing = $this->repo()->listCategories(true, 25, 0, 'english');
        $this->assertSame([3], array_column($missing['items'], 'id'));
    }

    #[Test]
    public function apply_category_batch_insert_uses_catid_and_copies_parentid_and_hidden(): void
    {
        $this->seedCategories();
        $repo = $this->repo();

        $result = $repo->applyCategoryBatch(
            [['id' => 3, 'name' => 'Billing', 'description' => 'Billing category', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('insert', $result['items'][0]['action']);

        $insert = null;
        foreach (FakeCapsule::$mutations as $mutation) {
            if ($mutation['verb'] === 'INSERT') {
                $insert = $mutation;
            }
        }
        $this->assertNotNull($insert);
        $this->assertSame(3, $insert['values']['catid']);
        $this->assertSame('english', $insert['values']['language']);
        $this->assertSame(1, $insert['values']['parentid']);
        $this->assertSame('1', $insert['values']['hidden']);
        $this->assertSame('Billing', $insert['values']['name']);
        $this->assertSame('Billing category', $insert['values']['description']);
    }

    #[Test]
    public function apply_category_batch_update_touches_only_name_and_description(): void
    {
        $this->seedCategories();
        $repo = $this->repo();
        $hash = hash('sha256', 'General' . "\0" . 'General category');

        $result = $repo->applyCategoryBatch(
            [['id' => 1, 'name' => 'General!', 'description' => 'General category!', 'expected_hash' => $hash]],
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
        $this->assertSame(['name', 'description'], array_keys($update['values']));
    }

    #[Test]
    public function apply_category_batch_rejects_hash_conflict_without_writing(): void
    {
        $this->seedCategories();
        $repo = $this->repo();

        $result = $repo->applyCategoryBatch(
            [['id' => 1, 'name' => 'x', 'description' => 'y', 'expected_hash' => 'wrong']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('hash_conflict', $result['error_code']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_category_batch_dry_run_never_writes(): void
    {
        $this->seedCategories();
        $repo = $this->repo();

        $result = $repo->applyCategoryBatch(
            [['id' => 3, 'name' => 'Billing', 'description' => 'Billing category', 'expected_hash' => 'absent']],
            $this->backup(),
            true,
            'english'
        );

        $this->assertTrue($result['dry_run']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_category_batch_backup_failure_rolls_back_and_writes_nothing(): void
    {
        $this->seedCategories();
        $repo = $this->repo();

        $blockedDir = sys_get_temp_dir() . '/nt_mcp_kb_category_backup_block_' . uniqid('', true);
        mkdir($blockedDir, 0700, true);
        file_put_contents($blockedDir . '/translation-backups', 'not a directory');
        $brokenBackup = new TranslationBackup($blockedDir);

        $this->expectException(\RuntimeException::class);
        try {
            $repo->applyCategoryBatch(
                [['id' => 3, 'name' => 'Billing', 'description' => 'Billing category', 'expected_hash' => 'absent']],
                $brokenBackup,
                false,
                'english'
            );
        } finally {
            $this->assertSame([], FakeCapsule::$mutations);
        }
    }

    #[Test]
    public function apply_category_batch_allows_empty_description_when_source_is_also_empty(): void
    {
        FakeCapsule::withRows('tblknowledgebasecats', [
            ['id' => 1, 'parentid' => 0, 'name' => 'a', 'description' => '', 'hidden' => '0', 'catid' => 0, 'language' => ''],
        ]);
        $repo = $this->repo();

        $dryRun = $repo->applyCategoryBatch(
            [['id' => 1, 'name' => 'a', 'description' => '', 'expected_hash' => 'absent']],
            $this->backup(),
            true,
            'english'
        );
        $this->assertSame('success', $dryRun['result']);
        $this->assertSame('insert', $dryRun['items'][0]['action']);

        $result = $repo->applyCategoryBatch(
            [['id' => 1, 'name' => 'a', 'description' => '', 'expected_hash' => 'absent']],
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
        $this->assertSame('', $insert['values']['description']);
    }

    #[Test]
    public function apply_category_batch_still_rejects_empty_description_when_source_is_not_empty(): void
    {
        $this->seedCategories();
        $repo = $this->repo();

        $result = $repo->applyCategoryBatch(
            [['id' => 3, 'name' => 'Billing', 'description' => '', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('validation_failed', $result['error_code']);
        $this->assertSame('empty_message', $result['errors'][0]['code']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    // -----------------------------------------------------------
    // InnoDB guard (write path only)
    // -----------------------------------------------------------

    #[Test]
    public function apply_article_batch_fails_closed_when_table_is_not_innodb(): void
    {
        $this->seedArticles();
        FakeCapsule::withTableEngine('tblknowledgebase', 'MyISAM');
        $repo = $this->repo();

        $result = $repo->applyArticleBatch(
            [['id' => 3, 'title' => 'Password reset', 'article' => 'Instructions', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('unsupported_engine', $result['error_code']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_category_batch_fails_closed_when_table_is_not_innodb(): void
    {
        $this->seedCategories();
        FakeCapsule::withTableEngine('tblknowledgebasecats', 'MyISAM');
        $repo = $this->repo();

        $result = $repo->applyCategoryBatch(
            [['id' => 3, 'name' => 'Billing', 'description' => 'Billing category', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('unsupported_engine', $result['error_code']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    // -----------------------------------------------------------
    // Dry-run nunca segura lock de linha
    // -----------------------------------------------------------

    #[Test]
    public function apply_article_batch_dry_run_never_calls_lock_for_update(): void
    {
        $this->seedArticles();
        $repo = $this->repo();

        $repo->applyArticleBatch(
            [['id' => 3, 'title' => 'Password reset', 'article' => 'Instructions', 'expected_hash' => 'absent']],
            $this->backup(),
            true,
            'english'
        );

        $this->assertNotContains('lockForUpdate()', FakeCapsule::$calls);
    }

    #[Test]
    public function apply_article_batch_confirm_calls_lock_for_update(): void
    {
        $this->seedArticles();
        $repo = $this->repo();

        $repo->applyArticleBatch(
            [['id' => 3, 'title' => 'Password reset', 'article' => 'Instructions', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertContains('lockForUpdate()', FakeCapsule::$calls);
    }

    #[Test]
    public function apply_category_batch_dry_run_never_calls_lock_for_update(): void
    {
        $this->seedCategories();
        $repo = $this->repo();

        $repo->applyCategoryBatch(
            [['id' => 3, 'name' => 'Billing', 'description' => 'Billing category', 'expected_hash' => 'absent']],
            $this->backup(),
            true,
            'english'
        );

        $this->assertNotContains('lockForUpdate()', FakeCapsule::$calls);
    }

    // -----------------------------------------------------------
    // Deterministic duplicates: menor id vence, list/get e apply concordam
    // -----------------------------------------------------------

    #[Test]
    public function category_list_picks_the_lowest_id_target_when_duplicates_exist(): void
    {
        FakeCapsule::withRows('tblknowledgebasecats', [
            ['id' => 1, 'parentid' => 0, 'name' => 'Geral', 'description' => 'Categoria geral', 'hidden' => '0', 'catid' => 0, 'language' => ''],
            ['id' => 20, 'parentid' => 0, 'name' => 'General (newer)', 'description' => 'x', 'hidden' => '0', 'catid' => 1, 'language' => 'english'],
            ['id' => 10, 'parentid' => 0, 'name' => 'General (older)', 'description' => 'y', 'hidden' => '0', 'catid' => 1, 'language' => 'english'],
        ]);
        $repo = $this->repo();

        $result = $repo->listCategories(false, 25, 0, 'english');
        $byId = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }
        $this->assertSame('General (older)', $byId[1]['target']['name']);
    }

    #[Test]
    public function get_articles_picks_the_lowest_id_target_when_duplicates_exist(): void
    {
        FakeCapsule::withRows('tblknowledgebase', [
            ['id' => 1, 'title' => 'Como configurar', 'article' => '<p>Passo a passo</p>', 'views' => 100, 'votes' => 10, 'useful' => 8, 'private' => '0', 'order' => 5, 'parentid' => 0, 'language' => ''],
            ['id' => 30, 'title' => 'How to configure (newer)', 'article' => 'x', 'views' => 0, 'votes' => 0, 'useful' => 0, 'private' => '0', 'order' => 5, 'parentid' => 1, 'language' => 'english'],
            ['id' => 15, 'title' => 'How to configure (older)', 'article' => 'y', 'views' => 0, 'votes' => 0, 'useful' => 0, 'private' => '0', 'order' => 5, 'parentid' => 1, 'language' => 'english'],
        ]);
        $repo = $this->repo();

        $result = $repo->getArticles([1], 'english');

        $this->assertSame('How to configure (older)', $result['items'][0]['target']['title']);
    }

    // -----------------------------------------------------------
    // Paginacao em SQL para listArticles
    // -----------------------------------------------------------

    #[Test]
    public function list_articles_paginates_and_reports_total_and_has_more(): void
    {
        FakeCapsule::withRows('tblknowledgebase', [
            ['id' => 1, 'title' => 'A', 'article' => 'a', 'views' => 0, 'votes' => 0, 'useful' => 0, 'private' => '0', 'order' => 1, 'parentid' => 0, 'language' => ''],
            ['id' => 2, 'title' => 'B', 'article' => 'b', 'views' => 0, 'votes' => 0, 'useful' => 0, 'private' => '0', 'order' => 1, 'parentid' => 0, 'language' => ''],
            ['id' => 3, 'title' => 'C', 'article' => 'c', 'views' => 0, 'votes' => 0, 'useful' => 0, 'private' => '0', 'order' => 1, 'parentid' => 0, 'language' => ''],
        ]);
        $repo = $this->repo();

        $page1 = $repo->listArticles(false, 2, 0, 'english');
        $this->assertSame([1, 2], array_column($page1['items'], 'id'));
        $this->assertSame(3, $page1['total']);
        $this->assertTrue($page1['has_more']);

        $page2 = $repo->listArticles(false, 2, 2, 'english');
        $this->assertSame([3], array_column($page2['items'], 'id'));
        $this->assertSame(3, $page2['total']);
        $this->assertFalse($page2['has_more']);
    }

    // -----------------------------------------------------------
    // Collection (Illuminate\Support\Collection)
    // -----------------------------------------------------------

    #[Test]
    public function collection_mode_works_for_articles_and_categories(): void
    {
        $this->seedArticles();
        $this->seedCategories();
        FakeCapsule::$collectionTables = ['tblknowledgebase', 'tblknowledgebasecats'];

        $articles = $this->repo()->listArticles(false, 25, 0, 'english');
        $this->assertSame([1, 3], array_column($articles['items'], 'id'));

        $categories = $this->repo()->listCategories(false, 25, 0, 'english');
        $this->assertSame([1, 3], array_column($categories['items'], 'id'));
    }
}
