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

        $this->assertSame('translation_unavailable', $repo->listMasters(null, false, 25, 0)['error_code']);
        $this->assertSame('translation_unavailable', $repo->getPairs([1])['error_code']);
        $this->assertSame('translation_unavailable', $repo->statusSummary()['error_code']);
        $this->assertSame(
            'translation_unavailable',
            $repo->applyBatch([['id' => 1, 'subject' => 'x', 'message' => 'y', 'expected_hash' => 'absent']], new TranslationBackup(sys_get_temp_dir() . '/nt_mcp_unused'), true)['error_code']
        );
    }

    // -----------------------------------------------------------
    // listMasters
    // -----------------------------------------------------------

    #[Test]
    public function list_masters_excludes_admin_and_english_rows(): void
    {
        $this->seedRows();

        $result = $this->repo()->listMasters(null, false, 25, 0);

        $ids = array_column($result['items'], 'id');
        $this->assertContains(1, $ids);
        $this->assertContains(4, $ids);
        $this->assertNotContains(2, $ids, 'type=admin não pode aparecer');
        $this->assertNotContains(3, $ids, 'linha em inglês não é master');
    }

    #[Test]
    public function list_masters_reports_has_en_correctly(): void
    {
        $this->seedRows();

        $result = $this->repo()->listMasters(null, false, 25, 0);
        $byId = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertTrue($byId[1]['has_en']);
        $this->assertFalse($byId[4]['has_en']);
    }

    #[Test]
    public function list_masters_only_missing_filters_out_translated(): void
    {
        $this->seedRows();

        $result = $this->repo()->listMasters(null, true, 25, 0);

        $ids = array_column($result['items'], 'id');
        $this->assertSame([4], $ids);
    }

    // -----------------------------------------------------------
    // getPairs
    // -----------------------------------------------------------

    #[Test]
    public function get_pairs_reports_absent_hash_when_english_missing(): void
    {
        $this->seedRows();

        $result = $this->repo()->getPairs([4]);

        $this->assertSame('success', $result['result']);
        $this->assertNull($result['pairs'][0]['en']);
        $this->assertSame('absent', $result['pairs'][0]['en_hash']);
    }

    #[Test]
    public function get_pairs_returns_english_content_and_hash_when_present(): void
    {
        $this->seedRows();

        $result = $this->repo()->getPairs([1]);
        $pair = $result['pairs'][0];

        $this->assertSame('Welcome {$firstname}', $pair['en']['subject']);
        $expectedHash = hash('sha256', 'Welcome {$firstname}' . "\0" . '<p>Welcome {$firstname}</p>');
        $this->assertSame($expectedHash, $pair['en_hash']);
    }

    #[Test]
    public function get_pairs_flags_not_found_admin_and_non_source(): void
    {
        $this->seedRows();

        $result = $this->repo()->getPairs([999, 2, 3]);

        $this->assertSame('not_found', $result['pairs'][0]['error']);
        $this->assertSame('not_translatable', $result['pairs'][1]['error']);
        $this->assertSame('not_translatable', $result['pairs'][2]['error']);
    }

    #[Test]
    public function get_pairs_rejects_more_than_ten_ids(): void
    {
        $this->seedRows();

        $result = $this->repo()->getPairs(range(1, 11));

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
            false
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
            false
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
            false
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
                false
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
            false
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
            true
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

        $result = $repo->applyBatch($items, $this->backup(), false);

        $this->assertSame('error', $result['result']);
        $this->assertSame('invalid_items', $result['error_code']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function apply_batch_one_invalid_item_rolls_back_the_whole_batch(): void
    {
        $this->seedRows();
        $repo = $this->repo();

        // Item inválido PRIMEIRO: o fake registra mutações no momento em que a
        // query roda (não simula rollback físico), então a única forma de
        // provar "nada sobrevive" é garantir que a falha ocorre antes de
        // qualquer escrita ser sequer tentada.
        $result = $repo->applyBatch(
            [
                ['id' => 1, 'subject' => 'x', 'message' => 'y', 'expected_hash' => 'wrong-hash'],
                ['id' => 4, 'subject' => 'Your invoice', 'message' => 'Your invoice arrived', 'expected_hash' => 'absent'],
            ],
            $this->backup(),
            false
        );

        $this->assertSame('error', $result['result']);
        $this->assertSame([], FakeCapsule::$mutations, 'nenhuma escrita do lote pode sobreviver a um item inválido');
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
                false
            );
        } finally {
            $this->assertSame([], FakeCapsule::$mutations);
        }
    }
}
