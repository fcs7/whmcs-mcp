<?php

declare(strict_types=1);

namespace NtMcp\Tests\Translation;

use NtMcp\Translation\DynamicTranslationMap;
use NtMcp\Translation\DynamicTranslationRepository;
use NtMcp\Translation\TranslationBackup;
use NtMcp\Translation\TranslationSchemaGuard;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DynamicTranslationRepositoryTest extends TestCase
{
    private const DYNAMIC_COLUMNS = ['id', 'related_type', 'related_id', 'language', 'translation', 'input_type'];

    private const PRODUCT_COLUMNS = ['id', 'gid', 'name', 'description', 'hidden', 'retired'];

    private const PRODUCT_GROUP_COLUMNS = ['id', 'name', 'headline', 'tagline', 'hidden'];

    protected function setUp(): void
    {
        FakeCapsule::reset();
    }

    protected function tearDown(): void
    {
        FakeCapsule::reset();
    }

    private function healthyProbe(array $extraColumns = []): FakeCrmSchemaProbe
    {
        return new FakeCrmSchemaProbe([
            'tbldynamic_translations' => array_merge(self::DYNAMIC_COLUMNS, $extraColumns),
            'tblproducts' => self::PRODUCT_COLUMNS,
            'tblproductgroups' => self::PRODUCT_GROUP_COLUMNS,
        ]);
    }

    private function repo(?FakeCrmSchemaProbe $probe = null): DynamicTranslationRepository
    {
        $probe ??= $this->healthyProbe();

        return new DynamicTranslationRepository(new TranslationSchemaGuard($probe), null, $probe);
    }

    private function backup(): TranslationBackup
    {
        return new TranslationBackup(sys_get_temp_dir() . '/nt_mcp_dynamic_backup_' . uniqid('', true));
    }

    private function seedProducts(): void
    {
        FakeCapsule::withRows('tblproducts', [
            ['id' => 1, 'gid' => 5, 'name' => 'Fibra 500', 'description' => '<p>Descricao 500</p>', 'hidden' => '0', 'retired' => '0'],
            ['id' => 2, 'gid' => 5, 'name' => 'Fibra 1000', 'description' => '<p>Descricao 1000</p>', 'hidden' => '0', 'retired' => '0'],
            ['id' => 3, 'gid' => 7, 'name' => 'Voz Movel', 'description' => '', 'hidden' => '1', 'retired' => '0'],
        ]);
    }

    private function seedProductGroups(): void
    {
        FakeCapsule::withRows('tblproductgroups', [
            ['id' => 5, 'name' => 'Internet', 'headline' => 'Internet rapida', 'tagline' => 'Sempre conectado', 'hidden' => '0'],
            ['id' => 7, 'name' => 'Movel', 'headline' => 'Planos moveis', 'tagline' => 'Fale sem limites', 'hidden' => '0'],
        ]);
    }

    // -----------------------------------------------------------
    // Schema guard / invalid kind / invalid target
    // -----------------------------------------------------------

    #[Test]
    public function schema_unavailable_is_reported_as_error(): void
    {
        $repo = $this->repo(new FakeCrmSchemaProbe([]));

        $result = $repo->listEntities(DynamicTranslationMap::KIND_PRODUCT, 0, true, 25, 0, 'english');

        $this->assertSame('error', $result['result']);
        $this->assertSame('translation_unavailable', $result['error_code']);
    }

    #[Test]
    public function invalid_kind_is_rejected_before_any_query(): void
    {
        $repo = $this->repo();

        $result = $repo->listEntities('kb_article', 0, true, 25, 0, 'english');

        $this->assertSame('invalid_kind', $result['error_code']);
        $this->assertSame([], FakeCapsule::$calls);
    }

    #[Test]
    public function invalid_target_language_is_rejected_before_any_query(): void
    {
        $repo = $this->repo();

        $result = $repo->listEntities(DynamicTranslationMap::KIND_PRODUCT, 0, true, 25, 0, 'french');

        $this->assertSame('invalid_target_language', $result['error_code']);
        $this->assertSame([], FakeCapsule::$calls);
    }

    // -----------------------------------------------------------
    // listEntities
    // -----------------------------------------------------------

    #[Test]
    public function list_only_missing_returns_products_without_a_target_yet(): void
    {
        $this->seedProducts();
        FakeCapsule::withRows('tbldynamic_translations', [
            // id=1: name traduzido, description (nao vazia na fonte) ainda nao -> continua "missing".
            ['id' => 1, 'related_type' => 'product.{id}.name', 'related_id' => 1, 'language' => 'english', 'translation' => 'Fiber 500', 'input_type' => 'text'],
            // id=3: name traduzido e description vazia na fonte (nao conta) -> NAO e "missing".
            ['id' => 2, 'related_type' => 'product.{id}.name', 'related_id' => 3, 'language' => 'english', 'translation' => 'Voice Mobile', 'input_type' => 'text'],
        ]);
        $repo = $this->repo();

        $result = $repo->listEntities(DynamicTranslationMap::KIND_PRODUCT, 0, true, 25, 0, 'english');

        $ids = array_column($result['items'], 'id');
        $this->assertContains(1, $ids);
        // id=2 nao tem nenhuma traducao e name nao esta vazio -> continua "missing".
        $this->assertContains(2, $ids);
        $this->assertNotContains(3, $ids);
    }

    #[Test]
    public function list_reports_input_type_and_related_type_are_never_leaked_but_hash_is_correct(): void
    {
        $this->seedProducts();
        FakeCapsule::withRows('tbldynamic_translations', [
            ['id' => 1, 'related_type' => 'product.{id}.name', 'related_id' => 1, 'language' => 'english', 'translation' => 'Fiber 500', 'input_type' => 'text'],
        ]);
        $repo = $this->repo();

        $result = $repo->listEntities(DynamicTranslationMap::KIND_PRODUCT, 0, false, 25, 0, 'english');

        $byId = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertTrue($byId[1]['fields']['name']['has_target']);
        $this->assertSame(hash('sha256', 'Fiber 500'), $byId[1]['fields']['name']['target_hash']);
        $this->assertSame('absent', $byId[1]['fields']['description']['target_hash']);
    }

    #[Test]
    public function list_filters_products_by_gid(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $result = $repo->listEntities(DynamicTranslationMap::KIND_PRODUCT, 7, false, 25, 0, 'english');

        $this->assertSame([3], array_column($result['items'], 'id'));
    }

    #[Test]
    public function list_truncates_description_but_not_name(): void
    {
        FakeCapsule::withRows('tblproducts', [
            ['id' => 1, 'gid' => 1, 'name' => str_repeat('n', 300), 'description' => str_repeat('d', 300), 'hidden' => '0', 'retired' => '0'],
        ]);
        $repo = $this->repo();

        $result = $repo->listEntities(DynamicTranslationMap::KIND_PRODUCT, 0, false, 25, 0, 'english');

        $item = $result['items'][0];
        $this->assertSame(300, mb_strlen($item['fields']['name']['source']));
        $this->assertSame(200, mb_strlen($item['fields']['description']['source']));
    }

    #[Test]
    public function product_group_list_ignores_gid_and_returns_full_text(): void
    {
        $this->seedProductGroups();
        $repo = $this->repo();

        $result = $repo->listEntities(DynamicTranslationMap::KIND_PRODUCT_GROUP, null, false, 50, 0, 'english');

        $this->assertCount(2, $result['items']);
        $this->assertSame('Internet rapida', $result['items'][0]['fields']['headline']['source']);
    }

    #[Test]
    public function list_returns_a_collection_when_get_is_traversable_not_array(): void
    {
        $this->seedProducts();
        FakeCapsule::$collectionTables = ['tblproducts', 'tbldynamic_translations'];
        $repo = $this->repo();

        $result = $repo->listEntities(DynamicTranslationMap::KIND_PRODUCT, 0, false, 25, 0, 'english');

        $this->assertSame('success', $result['result']);
        $this->assertCount(3, $result['items']);
    }

    // -----------------------------------------------------------
    // getEntities
    // -----------------------------------------------------------

    #[Test]
    public function get_reports_absent_hash_when_no_translation_exists(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $result = $repo->getEntities(DynamicTranslationMap::KIND_PRODUCT, [1], 'english');

        $this->assertSame('absent', $result['items'][0]['fields']['name']['target_hash']);
        $this->assertNull($result['items'][0]['fields']['name']['target']);
    }

    #[Test]
    public function get_reports_not_found_for_unknown_id_without_interrupting_the_batch(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $result = $repo->getEntities(DynamicTranslationMap::KIND_PRODUCT, [1, 999], 'english');

        $byId = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }
        $this->assertSame('not_found', $byId[999]['error']);
        $this->assertArrayNotHasKey('error', $byId[1]);
    }

    #[Test]
    public function get_returns_full_source_text_without_truncation(): void
    {
        FakeCapsule::withRows('tblproducts', [
            ['id' => 1, 'gid' => 1, 'name' => 'X', 'description' => str_repeat('d', 300), 'hidden' => '0', 'retired' => '0'],
        ]);
        $repo = $this->repo();

        $result = $repo->getEntities(DynamicTranslationMap::KIND_PRODUCT, [1], 'english');

        $this->assertSame(300, mb_strlen($result['items'][0]['fields']['description']['source']));
    }

    // -----------------------------------------------------------
    // applyBatch — dry-run e validações
    // -----------------------------------------------------------

    #[Test]
    public function apply_batch_dry_run_previews_insert_without_writing(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            DynamicTranslationMap::KIND_PRODUCT,
            [['id' => 1, 'field' => 'name', 'text' => 'Fiber 500', 'expected_hash' => 'absent']],
            $this->backup(),
            true,
            'english'
        );

        $this->assertSame('success', $result['result']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame('insert', $result['items'][0]['action']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_batch_inserts_related_type_as_the_literal_with_id_placeholder_and_the_numeric_related_id(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $repo->applyBatch(
            DynamicTranslationMap::KIND_PRODUCT,
            [['id' => 1, 'field' => 'name', 'text' => 'Fiber 500', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        $insert = FakeCapsule::$mutations[0];
        $this->assertSame('INSERT', $insert['verb']);
        $this->assertSame('product.{id}.name', $insert['values']['related_type']);
        $this->assertSame(1, $insert['values']['related_id']);
        $this->assertSame('text', $insert['values']['input_type']);
        $this->assertSame('english', $insert['values']['language']);
    }

    #[Test]
    public function apply_batch_update_touches_only_translation_column(): void
    {
        $this->seedProducts();
        FakeCapsule::withRows('tbldynamic_translations', [
            ['id' => 99, 'related_type' => 'product.{id}.name', 'related_id' => 1, 'language' => 'english', 'translation' => 'Old', 'input_type' => 'text'],
        ]);
        $repo = $this->repo();

        $result = $repo->applyBatch(
            DynamicTranslationMap::KIND_PRODUCT,
            [['id' => 1, 'field' => 'name', 'text' => 'Fiber 500', 'expected_hash' => hash('sha256', 'Old')]],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('success', $result['result']);
        $update = FakeCapsule::$mutations[0];
        $this->assertSame('UPDATE', $update['verb']);
        $this->assertSame(['translation'], array_keys($update['values']));
    }

    #[Test]
    public function apply_batch_rejects_hash_conflict_without_writing(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            DynamicTranslationMap::KIND_PRODUCT,
            [['id' => 1, 'field' => 'name', 'text' => 'Fiber 500', 'expected_hash' => 'wrong']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('hash_conflict', $result['error_code']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_batch_rejects_unknown_source_id(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            DynamicTranslationMap::KIND_PRODUCT,
            [['id' => 999, 'field' => 'name', 'text' => 'x', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('source_not_found', $result['error_code']);
    }

    #[Test]
    public function apply_batch_rejects_invalid_field_for_the_kind(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            DynamicTranslationMap::KIND_PRODUCT,
            [['id' => 1, 'field' => 'headline', 'text' => 'x', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('invalid_field', $result['error_code']);
    }

    #[Test]
    public function apply_batch_rejects_empty_source_text(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        // id=3 tem description vazia na fonte.
        $result = $repo->applyBatch(
            DynamicTranslationMap::KIND_PRODUCT,
            [['id' => 3, 'field' => 'description', 'text' => 'x', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('source_empty', $result['error_code']);
    }

    #[Test]
    public function apply_batch_rejects_duplicate_id_and_field_in_the_same_batch(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            DynamicTranslationMap::KIND_PRODUCT,
            [
                ['id' => 1, 'field' => 'name', 'text' => 'Fiber 500', 'expected_hash' => 'absent'],
                ['id' => 1, 'field' => 'name', 'text' => 'Fiber 500 v2', 'expected_hash' => 'absent'],
            ],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('duplicate_item', $result['error_code']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_batch_rolls_back_the_whole_batch_when_the_last_item_is_invalid(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            DynamicTranslationMap::KIND_PRODUCT,
            [
                ['id' => 1, 'field' => 'name', 'text' => 'Fiber 500', 'expected_hash' => 'absent'],
                ['id' => 2, 'field' => 'name', 'text' => 'x', 'expected_hash' => 'wrong-hash'],
            ],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('error', $result['result']);
        $this->assertSame([], FakeCapsule::$mutations, 'rollback deve reverter tambem o item valido anterior');
    }

    #[Test]
    public function apply_batch_backup_failure_rolls_back_and_writes_nothing(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $blockedDir = sys_get_temp_dir() . '/nt_mcp_dynamic_backup_block_' . uniqid('', true);
        mkdir($blockedDir, 0700, true);
        file_put_contents($blockedDir . '/translation-backups', 'not a directory');
        $brokenBackup = new TranslationBackup($blockedDir);

        $this->expectException(\RuntimeException::class);
        try {
            $repo->applyBatch(
                DynamicTranslationMap::KIND_PRODUCT,
                [['id' => 1, 'field' => 'name', 'text' => 'Fiber 500', 'expected_hash' => 'absent']],
                $brokenBackup,
                false,
                'english'
            );
        } finally {
            $this->assertSame([], FakeCapsule::$mutations);
        }
    }

    #[Test]
    public function apply_batch_never_writes_to_the_source_table(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $repo->applyBatch(
            DynamicTranslationMap::KIND_PRODUCT,
            [['id' => 1, 'field' => 'name', 'text' => 'Fiber 500', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        foreach (FakeCapsule::$mutations as $mutation) {
            $this->assertNotSame('tblproducts', $mutation['table']);
            $this->assertNotSame('tblproductgroups', $mutation['table']);
        }
    }

    #[Test]
    public function apply_batch_fills_timestamps_only_when_the_columns_exist(): void
    {
        $this->seedProducts();
        $repo = $this->repo($this->healthyProbe(['created_at', 'updated_at']));

        $repo->applyBatch(
            DynamicTranslationMap::KIND_PRODUCT,
            [['id' => 1, 'field' => 'name', 'text' => 'Fiber 500', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        $insert = FakeCapsule::$mutations[0];
        $this->assertArrayHasKey('created_at', $insert['values']);
        $this->assertArrayHasKey('updated_at', $insert['values']);
    }

    #[Test]
    public function apply_batch_never_requires_timestamp_columns(): void
    {
        $this->seedProducts();
        $repo = $this->repo(); // sem created_at/updated_at no probe

        $result = $repo->applyBatch(
            DynamicTranslationMap::KIND_PRODUCT,
            [['id' => 1, 'field' => 'name', 'text' => 'Fiber 500', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('success', $result['result']);
        $insert = FakeCapsule::$mutations[0];
        $this->assertArrayNotHasKey('created_at', $insert['values']);
        $this->assertArrayNotHasKey('updated_at', $insert['values']);
    }

    #[Test]
    public function apply_batch_rejects_more_than_twenty_items(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $items = array_fill(0, 21, ['id' => 1, 'field' => 'name', 'text' => 'x', 'expected_hash' => 'absent']);
        $result = $repo->applyBatch(DynamicTranslationMap::KIND_PRODUCT, $items, $this->backup(), false, 'english');

        $this->assertSame('invalid_items', $result['error_code']);
    }

    #[Test]
    public function apply_batch_rejects_invalid_target_language_for_the_batch(): void
    {
        $this->seedProducts();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            DynamicTranslationMap::KIND_PRODUCT,
            [['id' => 1, 'field' => 'name', 'text' => 'x', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'portuguese-br'
        );

        $this->assertSame('invalid_target_language', $result['error_code']);
    }
}
