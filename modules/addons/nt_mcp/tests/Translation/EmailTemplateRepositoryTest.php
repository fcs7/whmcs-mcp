<?php

declare(strict_types=1);

namespace NtMcp\Tests\Translation;

use NtMcp\Translation\EmailTemplateRepository;
use NtMcp\Translation\TranslationBackup;
use NtMcp\Translation\TranslationSchemaGuard;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmailTemplateRepositoryTest extends TestCase
{
    private const COLUMNS = [
        'id', 'type', 'name', 'subject', 'message', 'language', 'fromname', 'fromemail',
        'attachments', 'copyto', 'blind_copy_to', 'plaintext', 'disabled', 'custom',
    ];

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
            'tblemailtemplates' => self::COLUMNS,
        ]));
    }

    private function unavailableGuard(): TranslationSchemaGuard
    {
        return new TranslationSchemaGuard(new FakeCrmSchemaProbe([]));
    }

    private function repo(?TranslationSchemaGuard $guard = null): EmailTemplateRepository
    {
        return new EmailTemplateRepository($guard ?? $this->healthyGuard());
    }

    private function common(array $overrides): array
    {
        return $overrides + [
            'fromname' => 'Suporte',
            'fromemail' => 'suporte@ntweb.com.br',
            'attachments' => '',
            'copyto' => '',
            'blind_copy_to' => '',
            'plaintext' => '0',
            'disabled' => '0',
            'custom' => '0',
        ];
    }

    private function seedRows(): void
    {
        FakeCapsule::withRows('tblemailtemplates', [
            $this->common([
                'id' => 1, 'type' => 'general', 'name' => 'Welcome',
                'subject' => 'Bem-vindo {$firstname}', 'message' => '<p>Bem-vindo {$firstname}</p>',
                'language' => '',
            ]),
            $this->common([
                'id' => 2, 'type' => 'admin', 'name' => 'AdminOnly',
                'subject' => 'Admin', 'message' => 'Admin body', 'language' => '',
            ]),
            $this->common([
                'id' => 3, 'type' => 'general', 'name' => 'Welcome',
                'subject' => 'Welcome {$firstname}', 'message' => '<p>Welcome {$firstname}</p>',
                'language' => 'english',
            ]),
            $this->common([
                'id' => 4, 'type' => 'general', 'name' => 'Invoice',
                'subject' => 'Fatura', 'message' => 'Sua fatura chegou', 'language' => '',
            ]),
        ]);
    }

    // -----------------------------------------------------------
    // Schema guard
    // -----------------------------------------------------------

    #[Test]
    public function schema_unavailable_is_reported_by_every_method(): void
    {
        $repo = $this->repo($this->unavailableGuard());

        $this->assertSame('translation_unavailable', $repo->listMasters(null, false, 25, 0, 'english')['error_code']);
        $this->assertSame('translation_unavailable', $repo->getPairs([1], 'english')['error_code']);
        $this->assertSame('translation_unavailable', $repo->statusSummary()['error_code']);
        $this->assertSame(
            'translation_unavailable',
            $repo->applyBatch([['id' => 1, 'subject' => 'x', 'message' => 'y', 'expected_hash' => 'absent']], new TranslationBackup(sys_get_temp_dir() . '/nt_mcp_unused'), true, 'english')['error_code']
        );
    }

    // -----------------------------------------------------------
    // target_language invalido
    // -----------------------------------------------------------

    #[Test]
    public function invalid_target_language_is_rejected_without_querying_the_database(): void
    {
        $repo = $this->repo();

        $listResult = $repo->listMasters(null, false, 25, 0, 'french');
        $this->assertSame('error', $listResult['result']);
        $this->assertSame('invalid_target_language', $listResult['error_code']);

        $getResult = $repo->getPairs([1], 'french');
        $this->assertSame('invalid_target_language', $getResult['error_code']);

        $applyResult = $repo->applyBatch(
            [['id' => 1, 'subject' => 'x', 'message' => 'y', 'expected_hash' => 'absent']],
            $this->backup(),
            true,
            'french'
        );
        $this->assertSame('invalid_target_language', $applyResult['error_code']);

        $this->assertSame([], FakeCapsule::$calls, 'nenhuma consulta deve acontecer com target_language invalido');
    }

    #[Test]
    public function portuguese_br_is_accepted_as_target_language(): void
    {
        $this->seedRows();

        $result = $this->repo()->listMasters(null, false, 25, 0, 'portuguese-br');

        $this->assertSame('success', $result['result']);
        $this->assertSame('portuguese-br', $result['target_language']);
    }

    // -----------------------------------------------------------
    // listMasters
    // -----------------------------------------------------------

    #[Test]
    public function list_masters_excludes_admin_and_english_rows(): void
    {
        $this->seedRows();

        $result = $this->repo()->listMasters(null, false, 25, 0, 'english');

        $ids = array_column($result['items'], 'id');
        $this->assertContains(1, $ids);
        $this->assertContains(4, $ids);
        $this->assertNotContains(2, $ids, 'type=admin não pode aparecer');
        $this->assertNotContains(3, $ids, 'linha em inglês não é master');
    }

    #[Test]
    public function list_masters_reports_has_target_correctly(): void
    {
        $this->seedRows();

        $result = $this->repo()->listMasters(null, false, 25, 0, 'english');
        $byId = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertTrue($byId[1]['has_target']);
        $this->assertSame(['english'], $byId[1]['variants']);
        $this->assertFalse($byId[4]['has_target']);
        $this->assertSame([], $byId[4]['variants']);
    }

    #[Test]
    public function list_masters_only_missing_filters_out_translated(): void
    {
        $this->seedRows();

        $result = $this->repo()->listMasters(null, true, 25, 0, 'english');

        $ids = array_column($result['items'], 'id');
        $this->assertSame([4], $ids);
    }

    #[Test]
    public function list_masters_variants_lists_multiple_sibling_languages(): void
    {
        FakeCapsule::withRows('tblemailtemplates', [
            $this->common(['id' => 1, 'type' => 'general', 'name' => 'Welcome', 'subject' => 'Bem-vindo', 'message' => 'Corpo', 'language' => '']),
            $this->common(['id' => 2, 'type' => 'general', 'name' => 'Welcome', 'subject' => 'Welcome', 'message' => 'Body', 'language' => 'english']),
            $this->common(['id' => 3, 'type' => 'general', 'name' => 'Welcome', 'subject' => 'Bem-vindo', 'message' => 'Corpo', 'language' => 'portuguese-br']),
        ]);

        $result = $this->repo()->listMasters(null, false, 25, 0, 'english');

        $this->assertSame(['english', 'portuguese-br'], $result['items'][0]['variants']);
    }

    // -----------------------------------------------------------
    // getPairs
    // -----------------------------------------------------------

    #[Test]
    public function get_pairs_reports_absent_hash_when_target_missing(): void
    {
        $this->seedRows();

        $result = $this->repo()->getPairs([4], 'english');

        $this->assertSame('success', $result['result']);
        $this->assertSame('english', $result['target_language']);
        $this->assertNull($result['pairs'][0]['target']);
        $this->assertSame('absent', $result['pairs'][0]['target_hash']);
    }

    #[Test]
    public function get_pairs_returns_target_content_and_hash_when_present(): void
    {
        $this->seedRows();

        $result = $this->repo()->getPairs([1], 'english');
        $pair = $result['pairs'][0];

        $this->assertSame('Welcome {$firstname}', $pair['target']['subject']);
        $expectedHash = hash('sha256', 'Welcome {$firstname}' . "\0" . '<p>Welcome {$firstname}</p>');
        $this->assertSame($expectedHash, $pair['target_hash']);
    }

    #[Test]
    public function get_pairs_flags_not_found_admin_and_non_source(): void
    {
        $this->seedRows();

        $result = $this->repo()->getPairs([999, 2, 3], 'english');

        $this->assertSame('not_found', $result['pairs'][0]['error']);
        $this->assertSame('not_translatable', $result['pairs'][1]['error']);
        $this->assertSame('not_translatable', $result['pairs'][2]['error']);
    }

    #[Test]
    public function get_pairs_rejects_more_than_ten_ids(): void
    {
        $this->seedRows();

        $result = $this->repo()->getPairs(range(1, 11), 'english');

        $this->assertSame('error', $result['result']);
        $this->assertSame('invalid_ids', $result['error_code']);
    }

    // -----------------------------------------------------------
    // applyBatch
    // -----------------------------------------------------------

    private function backup(): TranslationBackup
    {
        return new TranslationBackup(sys_get_temp_dir() . '/nt_mcp_repo_test_' . uniqid('', true));
    }

    #[Test]
    public function apply_batch_insert_copies_master_columns(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            [['id' => 4, 'subject' => 'Your invoice', 'message' => 'Your invoice arrived', 'expected_hash' => 'absent']],
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
        $this->assertSame('general', $insert['values']['type']);
        $this->assertSame('Invoice', $insert['values']['name']);
        $this->assertSame('Suporte', $insert['values']['fromname']);
        $this->assertSame('english', $insert['values']['language']);
        $this->assertSame('Your invoice', $insert['values']['subject']);
        $this->assertSame('Your invoice arrived', $insert['values']['message']);
    }

    #[Test]
    public function apply_batch_update_touches_only_subject_and_message(): void
    {
        $this->seedRows();
        $repo = $this->repo();
        $hash = hash('sha256', 'Welcome {$firstname}' . "\0" . '<p>Welcome {$firstname}</p>');

        $result = $repo->applyBatch(
            [['id' => 1, 'subject' => 'Welcome {$firstname}!', 'message' => '<p>Welcome {$firstname}!</p>', 'expected_hash' => $hash]],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('success', $result['result']);
        $this->assertSame('update', $result['items'][0]['action']);

        $update = null;
        foreach (FakeCapsule::$mutations as $mutation) {
            if ($mutation['verb'] === 'UPDATE') {
                $update = $mutation;
            }
        }
        $this->assertNotNull($update);
        $this->assertSame(['subject', 'message'], array_keys($update['values']));
    }

    #[Test]
    public function apply_batch_rejects_hash_conflict_without_writing(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            [['id' => 1, 'subject' => 'x', 'message' => 'y', 'expected_hash' => 'wrong-hash']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('error', $result['result']);
        $this->assertSame('hash_conflict', $result['error_code']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_batch_rejects_master_not_found_admin_or_non_source(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        foreach ([999, 2, 3] as $id) {
            FakeCapsule::$mutations = [];
            $result = $repo->applyBatch(
                [['id' => $id, 'subject' => 'x', 'message' => 'y', 'expected_hash' => 'absent']],
                $this->backup(),
                false,
                'english'
            );
            $this->assertSame('master_not_found', $result['error_code'], "id {$id} deveria ser recusado");
            $this->assertSame([], FakeCapsule::$mutations);
        }
    }

    #[Test]
    public function apply_batch_rejects_content_validation_failure_without_writing(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            [['id' => 4, 'subject' => 'Fatura EN', 'message' => '<div>Sua fatura chegou</div>', 'expected_hash' => 'absent']],
            $this->backup(),
            false,
            'english'
        );

        $this->assertSame('error', $result['result']);
        $this->assertSame('validation_failed', $result['error_code']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_batch_dry_run_never_writes(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        $result = $repo->applyBatch(
            [['id' => 4, 'subject' => 'Your invoice', 'message' => 'Your invoice arrived', 'expected_hash' => 'absent']],
            $this->backup(),
            true,
            'english'
        );

        $this->assertSame('success', $result['result']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame('insert', $result['items'][0]['action']);
        $this->assertArrayHasKey('message_excerpt', $result['items'][0]);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_batch_rejects_more_than_ten_items(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        $items = [];
        for ($i = 0; $i < 11; $i++) {
            $items[] = ['id' => 4, 'subject' => 'x', 'message' => 'y', 'expected_hash' => 'absent'];
        }

        $result = $repo->applyBatch($items, $this->backup(), false, 'english');

        $this->assertSame('error', $result['result']);
        $this->assertSame('invalid_items', $result['error_code']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_batch_one_invalid_item_rolls_back_the_whole_batch(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        // Item VÁLIDO primeiro (grava uma mutação de verdade), item inválido
        // por último: só é possível provar "rollback reverte de fato" com o
        // item válido processado ANTES do erro — agora que FakeCapsule
        // restaura um snapshot real de `$mutations` em `rollBack()`.
        $result = $repo->applyBatch(
            [
                ['id' => 4, 'subject' => 'Your invoice', 'message' => 'Your invoice arrived', 'expected_hash' => 'absent'],
                ['id' => 1, 'subject' => 'x', 'message' => 'y', 'expected_hash' => 'wrong-hash'],
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

        // Diretório bloqueado por um arquivo no lugar: TranslationBackup::append() lança.
        $blockedDir = sys_get_temp_dir() . '/nt_mcp_backup_block_' . uniqid('', true);
        mkdir($blockedDir, 0700, true);
        file_put_contents($blockedDir . '/translation-backups', 'not a directory');
        $brokenBackup = new TranslationBackup($blockedDir);

        $this->expectException(\RuntimeException::class);
        try {
            $repo->applyBatch(
                [['id' => 4, 'subject' => 'Your invoice', 'message' => 'Your invoice arrived', 'expected_hash' => 'absent']],
                $brokenBackup,
                false,
                'english'
            );
        } finally {
            $this->assertSame([], FakeCapsule::$mutations);
        }
    }

    #[Test]
    public function apply_batch_backup_failure_on_second_item_rolls_back_everything(): void
    {
        FakeCapsule::withRows('tblemailtemplates', [
            $this->common(['id' => 10, 'type' => 'general', 'name' => 'Alpha', 'subject' => 'Assunto A', 'message' => 'Corpo A', 'language' => '']),
            $this->common(['id' => 20, 'type' => 'general', 'name' => 'Beta', 'subject' => 'Assunto B', 'message' => 'Corpo B', 'language' => '']),
            // Sibling EN de "Beta" com bytes UTF-8 inválidos no conteúdo ANTERIOR
            // — isso entra em `previous` no backup, e faz `json_encode()` do
            // TranslationBackup devolver false (sem JSON_INVALID_UTF8_SUBSTITUTE).
            $this->common(['id' => 21, 'type' => 'general', 'name' => 'Beta', 'subject' => "Bad \xB1\x31", 'message' => 'old', 'language' => 'english']),
        ]);
        $repo = $this->repo();
        $badHash = hash('sha256', "Bad \xB1\x31" . "\0" . 'old');

        $this->expectException(\RuntimeException::class);
        try {
            $repo->applyBatch(
                [
                    ['id' => 10, 'subject' => 'Subject A EN', 'message' => 'Body A EN', 'expected_hash' => 'absent'],
                    ['id' => 20, 'subject' => 'Subject B EN', 'message' => 'Body B EN', 'expected_hash' => $badHash],
                ],
                $this->backup(),
                false,
                'english'
            );
        } finally {
            $this->assertSame(
                [],
                FakeCapsule::$mutations,
                'a escrita do primeiro item nao pode sobreviver a falha de backup no segundo'
            );
        }
    }

    // -----------------------------------------------------------
    // Collection (Illuminate\Support\Collection) no lugar de array
    // -----------------------------------------------------------

    #[Test]
    public function variants_lookup_works_when_get_returns_a_traversable_collection(): void
    {
        $this->seedRows();
        FakeCapsule::$collectionTables = ['tblemailtemplates'];

        // No WHMCS real, `->get()` devolve `Illuminate\Support\Collection`, não
        // um array — `siblingVariantsByName()` faz `foreach` (não `array_map()`)
        // sobre esse retorno, o que funciona igual em array e Collection.
        $result = $this->repo()->listMasters(null, false, 25, 0, 'english');

        $byId = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertTrue($byId[1]['has_target']);
        $this->assertFalse($byId[4]['has_target']);
    }
}
