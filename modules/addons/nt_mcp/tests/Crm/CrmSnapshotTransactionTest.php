<?php

declare(strict_types=1);

namespace NtMcp\Tests\Crm;

use NtMcp\Crm\CrmErrorCode;
use NtMcp\Crm\CrmCount;
use NtMcp\Crm\CrmException;
use NtMcp\Crm\CrmSchema;
use NtMcp\Crm\CrmSchemaGuard;
use NtMcp\Crm\CrmSelect;
use NtMcp\Crm\MgCrmRepository;
use NtMcp\Tests\Support\FakeAdminIdentityResolver;
use NtMcp\Tests\Support\FakeCrmQueryPort;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Reproduções da garantia D15 no seam MVCC em memória. */
class CrmSnapshotTransactionTest extends TestCase
{
    private function repository(FakeCrmQueryPort $port): MgCrmRepository
    {
        return new MgCrmRepository(
            new CrmSchemaGuard(FakeCrmSchemaProbe::healthy()),
            $port,
            FakeAdminIdentityResolver::resolvingTo(7),
        );
    }

    private static function seedKanban(FakeCrmQueryPort $port): void
    {
        $port->seed(CrmSchema::TABLE_RESOURCE_TYPES, []);
        $port->seed(CrmSchema::TABLE_FOLLOWUP_TYPES, []);
        $port->seed(CrmSchema::TABLE_FOLLOWUP_STATUSES, []);
        $port->seed(CrmSchema::TABLE_RESOURCES, []);
    }

    /** Troca compensatória entre chunks fica fora da resposta inteira. */
    public function test_compensating_membership_change_cannot_tear_a_catalog_snapshot(): void
    {
        $port = new FakeCrmQueryPort();
        self::seedKanban($port);
        $port->seed(CrmSchema::TABLE_RESOURCE_STATUSES, array_map(
            static fn(int $id): array => [
                'id' => $id,
                'name' => "Status {$id}",
                'active' => 1,
                'deleted_at' => null,
            ],
            [...range(1, 99), 101, 200]
        ));

        $port->mutateAfterNextSnapshotQuery(function () use ($port): void {
            $port->seed(CrmSchema::TABLE_RESOURCE_STATUSES, array_map(
                static fn(int $id): array => [
                    'id' => $id,
                    'name' => "Status {$id}",
                    'active' => ($id === 50 ? 0 : 1),
                    'deleted_at' => null,
                ],
                [...range(1, 101), 200]
            ));
        });

        $catalog = $this->repository($port)->getKanban(null, 25)['catalogs']['resource_statuses'];
        $ids = array_column($catalog, 'id');

        $this->assertCount(101, $ids);
        $this->assertContains(50, $ids, 'a visão antiga inteira permanece coesa');
        $this->assertNotContains(100, $ids, 'a entrada nova não atravessa o snapshot');
    }

    /** Atualização de conteúdo entre COUNT e SELECT não mistura uma página. */
    public function test_content_update_between_queries_returns_one_coherent_resource_version(): void
    {
        $port = new FakeCrmQueryPort();
        $port->seed(CrmSchema::TABLE_RESOURCES, [self::resource(1, 'Antes')]);
        $port->mutateAfterNextSnapshotQuery(fn() => $port->seed(
            CrmSchema::TABLE_RESOURCES,
            [self::resource(1, 'Depois')]
        ));

        $result = $this->repository($port)->listResources(null, null, 25, 0);

        $this->assertSame('Antes', $result['items'][0]['name']);
        $this->assertSame(1, $result['count']);
    }

    /** `throughId` precisa ser o último item realmente lido, não só um teto contado. */
    public function test_scan_rejects_a_lie_that_never_reaches_the_reported_highest_id(): void
    {
        $port = new FakeCrmQueryPort();
        self::seedKanban($port);
        $port->seed(CrmSchema::TABLE_RESOURCE_STATUSES, array_map(
            static fn(int $id): array => ['id' => $id, 'name' => "S{$id}", 'active' => 1, 'deleted_at' => null],
            range(1, 100)
        ));
        $port->reportHighestIdFor(CrmSchema::TABLE_RESOURCE_STATUSES, 150);

        $this->expectException(CrmException::class);
        try {
            $this->repository($port)->getKanban(null, 25);
        } catch (CrmException $e) {
            $this->assertSame(CrmErrorCode::Downstream, $e->errorCode);
            throw $e;
        }
    }

    /** Begin, body, commit, rollback e transação ambiente nunca deixam o seam preso. */
    public function test_snapshot_lifecycle_fails_closed_and_remains_reusable(): void
    {
        foreach (['begin', 'commit'] as $phase) {
            $port = new FakeCrmQueryPort();
            $port->seed(CrmSchema::TABLE_RESOURCES, [self::resource(1, 'A')]);
            $port->failSnapshot($phase, new \RuntimeException("{$phase} secret"));

            $this->assertSame(
                CrmErrorCode::Downstream,
                $this->capture(fn() => $this->repository($port)->listResources(null, null, 25, 0))->errorCode
            );
            $port->failSnapshot($phase, null);
            $this->assertSame('ok', $port->withinReadSnapshot(static fn(): string => 'ok'));
        }

        $rollback = new FakeCrmQueryPort();
        $rollback->seed(CrmSchema::TABLE_RESOURCES, []);
        $rollback->failSnapshot('rollback', new \RuntimeException('rollback secret'));
        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->capture(fn() => $this->repository($rollback)->getResource(1))->errorCode
        );

        $ambient = new FakeCrmQueryPort();
        $ambient->simulateAmbientTransaction();
        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->capture(fn() => $this->repository($ambient)->listResources(null, null, 25, 0))->errorCode
        );
    }

    /** A validação Unicode é a mesma para catálogo ativo, D13 e custom field. */
    public function test_visual_unicode_whitespace_is_rejected_and_valid_edges_are_normalized_everywhere(): void
    {
        $blank = "\u{00A0}\u{2003}\u{3000}";

        $this->assertInvisibleNameRejectedEverywhere($blank);

        $normalized = new FakeCrmQueryPort();
        self::seedKanban($normalized);
        $normalized->seed(CrmSchema::TABLE_RESOURCE_TYPES, [
            ['id' => 1, 'name' => "\u{00A0} Lead \u{3000}", 'active' => 1, 'deleted_at' => null],
        ]);
        $normalized->seed(CrmSchema::TABLE_RESOURCE_STATUSES, []);
        $this->assertSame(
            'Lead',
            $this->repository($normalized)->getKanban(null, 25)['catalogs']['resource_types'][0]['name']
        );
    }

    /** ZWNJ/ZWJ/WORD JOINER/SOFT HYPHEN isolados também não são conteúdo visível. */
    #[DataProvider('invisibleFormatCharacterProvider')]
    public function test_invisible_format_characters_fail_in_all_name_routes(string $name): void
    {
        $this->assertInvisibleNameRejectedEverywhere($name);
    }

    /** @return array<string, array{0:string}> */
    public static function invisibleFormatCharacterProvider(): array
    {
        return [
            'ZWNJ' => ["\u{200C}"],
            'ZWJ' => ["\u{200D}"],
            'WORD JOINER' => ["\u{2060}"],
            'SOFT HYPHEN' => ["\u{00AD}"],
        ];
    }

    /** Caracteres de formato internos em nome visível são preservados, não apagados. */
    public function test_visible_name_with_internal_format_character_is_preserved(): void
    {
        $port = new FakeCrmQueryPort();
        self::seedKanban($port);
        $port->seed(CrmSchema::TABLE_RESOURCE_TYPES, [
            ['id' => 1, 'name' => "Lead\u{200C}North", 'active' => 1, 'deleted_at' => null],
        ]);
        $port->seed(CrmSchema::TABLE_RESOURCE_STATUSES, []);

        $this->assertSame(
            "Lead\u{200C}North",
            $this->repository($port)->getKanban(null, 25)['catalogs']['resource_types'][0]['name']
        );
    }

    private function assertInvisibleNameRejectedEverywhere(string $blank): void
    {

        $catalog = new FakeCrmQueryPort();
        self::seedKanban($catalog);
        $catalog->seed(CrmSchema::TABLE_RESOURCE_TYPES, [
            ['id' => 1, 'name' => $blank, 'active' => 1, 'deleted_at' => null],
        ]);
        $catalog->seed(CrmSchema::TABLE_RESOURCE_STATUSES, []);
        $this->assertSame(CrmErrorCode::Downstream, $this->capture(
            fn() => $this->repository($catalog)->getKanban(null, 25)
        )->errorCode);

        $historical = new FakeCrmQueryPort();
        $historical->seed(CrmSchema::TABLE_RESOURCES, [self::resource(1, 'R')]);
        $historical->seed(CrmSchema::TABLE_FOLLOWUPS, [[
            'id' => 1, 'resource_id' => 1, 'type_id' => 1, 'status_id' => 1,
            'description' => 'x', 'date' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            'deleted_at' => null,
        ]]);
        $historical->seed(CrmSchema::TABLE_FOLLOWUP_TYPES, [
            ['id' => 1, 'name' => $blank, 'active' => 0, 'deleted_at' => null],
        ]);
        $historical->seed(CrmSchema::TABLE_FOLLOWUP_STATUSES, [
            ['id' => 1, 'name' => 'Histórico', 'active' => 0, 'deleted_at' => null],
        ]);
        $this->assertSame(CrmErrorCode::Downstream, $this->capture(
            fn() => $this->repository($historical)->listFollowups(1, null, null, 25, 0)
        )->errorCode);

        $fields = new FakeCrmQueryPort();
        $fields->seed(CrmSchema::TABLE_RESOURCES, [self::resource(1, 'R')]);
        $fields->seed(CrmSchema::TABLE_FIELD_VALUES, [['id' => 1, 'resource_id' => 1, 'field_id' => 1, 'value' => 'v']]);
        $fields->seed(CrmSchema::TABLE_FIELDS, [
            ['id' => 1, 'name' => $blank, 'deleted_at' => null],
        ]);
        $this->assertSame(CrmErrorCode::Downstream, $this->capture(
            fn() => $this->repository($fields)->getResource(1)
        )->errorCode);

    }

    /** O sort final de custom fields também é lexical para VARCHAR numérico. */
    public function test_custom_field_public_sort_keeps_varchar_numeric_names_textual(): void
    {
        $port = new FakeCrmQueryPort();
        $port->seed(CrmSchema::TABLE_RESOURCES, [self::resource(1, 'R')]);
        $port->seed(CrmSchema::TABLE_FIELD_VALUES, [
            ['id' => 1, 'resource_id' => 1, 'field_id' => 1, 'value' => 'a'],
            ['id' => 2, 'resource_id' => 1, 'field_id' => 2, 'value' => 'b'],
            ['id' => 3, 'resource_id' => 1, 'field_id' => 3, 'value' => 'c'],
        ]);
        $port->seed(CrmSchema::TABLE_FIELDS, [
            ['id' => 1, 'name' => '9', 'deleted_at' => null],
            ['id' => 2, 'name' => '10', 'deleted_at' => null],
            ['id' => 3, 'name' => '100', 'deleted_at' => null],
        ]);

        $fields = $this->repository($port)->getResource(1)['custom_fields'];

        $this->assertSame(['10', '100', '9'], array_column($fields, 'name'));
    }

    /** O fake aplica exatamente o mesmo `IN` no select e no count derivado. */
    public function test_fake_count_matches_select_with_in_conditions(): void
    {
        $port = new FakeCrmQueryPort();
        $port->seed(CrmSchema::TABLE_FIELDS, [
            ['id' => 1, 'name' => 'A', 'deleted_at' => null],
            ['id' => 2, 'name' => 'B', 'deleted_at' => null],
            ['id' => 3, 'name' => 'C', 'deleted_at' => '2026-01-01 00:00:00'],
        ]);
        $select = new CrmSelect(
            table: CrmSchema::TABLE_FIELDS,
            columns: CrmSchema::catalogProjection(),
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            limit: 25,
            inConditions: [CrmSchema::COLUMN_ID => [1, 3]],
        );

        $this->assertCount(1, $port->selectRows($select));
        $this->assertSame(1, $port->countRows(CrmCount::matching($select)));
    }

    private function capture(callable $operation): CrmException
    {
        try {
            $operation();
        } catch (CrmException $e) {
            return $e;
        }

        $this->fail('esperava CrmException');
    }

    /** @return array<string, mixed> */
    private static function resource(int $id, string $name): array
    {
        return [
            'id' => $id, 'type_id' => 1, 'status_id' => 1, 'name' => $name,
            'lastname' => null, 'email' => null, 'phone' => null, 'country' => null,
            'short_description' => null, 'description' => null,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            'deleted_at' => null,
        ];
    }
}
