<?php

declare(strict_types=1);

namespace NtMcp\Tests\Crm;

use NtMcp\Crm\CrmCount;
use NtMcp\Crm\CrmErrorCode;
use NtMcp\Crm\CrmException;
use NtMcp\Crm\CapsuleQueryPort;
use NtMcp\Crm\CapsuleSchemaProbe;
use NtMcp\Crm\CrmCapability;
use NtMcp\Crm\CrmSchema;
use NtMcp\Crm\CrmSchemaGuard;
use NtMcp\Crm\CrmSelect;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\CrmSchemaFixture;
use NtMcp\Tests\Support\FakeSchemaBuilder;
use PHPUnit\Framework\TestCase;

/**
 * O port de PRODUÇÃO, exercitado contra o dublê de Capsule.
 *
 * Todas as regressões de CRM-2/2.1 passavam pelo `FakeCrmQueryPort`, que
 * implementa o contrato mas não a TRADUÇÃO para o query builder. A revisão fria
 * mostrou o custo: `CrmSelect(inConditions: ...)` chamava `whereIn()`, que o
 * dublê de Capsule não tinha, e a suíte inteira ficava verde mesmo com o
 * caminho de produção quebrado. Este arquivo fecha essa lacuna.
 */
class CapsuleQueryPortTest extends TestCase
{
    protected function setUp(): void
    {
        FakeCapsule::reset();
    }

    protected function tearDown(): void
    {
        FakeCapsule::reset();
        FakeSchemaBuilder::reset();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function seedFields(array $rows): void
    {
        FakeCapsule::withRows(CrmSchema::TABLE_FIELDS, $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private static function fieldRows(): array
    {
        return [
            ['id' => 1, 'name' => 'Alpha', 'deleted_at' => null],
            ['id' => 2, 'name' => 'Beta', 'deleted_at' => null],
            ['id' => 3, 'name' => 'Gamma', 'deleted_at' => null],
            ['id' => 4, 'name' => 'Removido', 'deleted_at' => '2026-01-01 00:00:00'],
            ['id' => 5, 'name' => 'Epsilon', 'deleted_at' => null],
        ];
    }

    // ---------------------------------------------------------------
    // whereIn
    // ---------------------------------------------------------------

    /** `inConditions` vira `whereIn()` de verdade e filtra de verdade. */
    public function test_in_conditions_translate_to_where_in(): void
    {
        $this->seedFields(self::fieldRows());

        $rows = (new CapsuleQueryPort())->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_FIELDS,
            columns: CrmSchema::catalogProjection(),
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            order: CrmSchema::catalogOrder(),
            limit: CrmSchema::CHUNK_SIZE,
            inConditions: [CrmSchema::COLUMN_ID => [1, 3, 4]],
        ));

        $this->assertSame(
            [['id' => 1, 'name' => 'Alpha'], ['id' => 3, 'name' => 'Gamma']],
            array_map(static fn(array $row): array => $row, $rows),
            'o id 4 é soft-deleted e sai pelo whereNull, não pelo whereIn'
        );

        $this->assertContains('whereIn(id,3)', FakeCapsule::$calls);
        $this->assertContains('whereNull(deleted_at)', FakeCapsule::$calls);
    }

    /** Um lote no teto (100 ids) atravessa o builder sem perder ninguém. */
    public function test_a_full_batch_of_one_hundred_ids_translates(): void
    {
        $this->seedFields(array_map(
            static fn(int $id): array => [
                'id' => $id,
                'name' => sprintf('F%04d', $id),
                'deleted_at' => null,
            ],
            range(1, 150)
        ));

        $rows = (new CapsuleQueryPort())->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_FIELDS,
            columns: CrmSchema::catalogProjection(),
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            order: CrmSchema::catalogOrder(),
            limit: CrmSchema::CHUNK_SIZE,
            inConditions: [CrmSchema::COLUMN_ID => range(1, CrmSchema::MAX_IN_VALUES)],
        ));

        $this->assertCount(CrmSchema::MAX_IN_VALUES, $rows);
        $this->assertContains('whereIn(id,100)', FakeCapsule::$calls);
    }

    /** IDs repetidos no lote não duplicam linha no resultado. */
    public function test_duplicated_ids_in_the_batch_do_not_duplicate_rows(): void
    {
        $this->seedFields(self::fieldRows());

        $rows = (new CapsuleQueryPort())->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_FIELDS,
            columns: CrmSchema::catalogProjection(),
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            limit: CrmSchema::CHUNK_SIZE,
            inConditions: [CrmSchema::COLUMN_ID => [2, 2, 2]],
        ));

        $this->assertSame([['id' => 2, 'name' => 'Beta']], $rows);
    }

    // ---------------------------------------------------------------
    // Keyset
    // ---------------------------------------------------------------

    /** `afterId`/`throughId` viram comparações reais no builder. */
    public function test_keyset_bounds_translate_to_comparisons(): void
    {
        $this->seedFields(self::fieldRows());

        $rows = (new CapsuleQueryPort())->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_FIELDS,
            columns: CrmSchema::catalogProjection(),
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            order: [[CrmSchema::COLUMN_ID, 'asc']],
            limit: CrmSchema::CHUNK_SIZE,
            afterId: 1,
            throughId: 3,
        ));

        $this->assertSame([['id' => 2, 'name' => 'Beta'], ['id' => 3, 'name' => 'Gamma']], $rows);
        $this->assertContains('where(id,>)', FakeCapsule::$calls);
        $this->assertContains('where(id,<=)', FakeCapsule::$calls);
    }

    /** O teto de id também restringe a contagem. */
    public function test_count_honours_the_id_upper_bound(): void
    {
        $this->seedFields(self::fieldRows());

        $port = new CapsuleQueryPort();

        $this->assertSame(
            4,
            $port->countRows(new CrmCount(
                CrmSchema::TABLE_FIELDS,
                [],
                [CrmSchema::COLUMN_DELETED_AT],
            )),
            'quatro não soft-deleted no total'
        );

        $this->assertSame(
            3,
            $port->countRows(new CrmCount(
                CrmSchema::TABLE_FIELDS,
                [],
                [CrmSchema::COLUMN_DELETED_AT],
                3,
            )),
            'ids 1..3 sob o teto; o soft-deleted (4) já estava fora'
        );

        $this->assertSame(
            1,
            $port->countRows(new CrmCount(
                CrmSchema::TABLE_FIELDS,
                [],
                [CrmSchema::COLUMN_DELETED_AT],
                1,
            )),
            'o teto de id realmente restringe a contagem'
        );
    }

    /** `CrmCount::matching()` mantém `IN` no executor concreto, como no select. */
    public function test_count_and_select_match_when_the_filter_contains_in(): void
    {
        $this->seedFields(self::fieldRows());
        $port = new CapsuleQueryPort();
        $select = new CrmSelect(
            table: CrmSchema::TABLE_FIELDS,
            columns: CrmSchema::catalogProjection(),
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            order: CrmSchema::catalogOrder(),
            limit: CrmSchema::CHUNK_SIZE,
            inConditions: [CrmSchema::COLUMN_ID => [1, 3, 4]],
        );

        $this->assertCount(2, $port->selectRows($select));
        $this->assertSame(2, $port->countRows(CrmCount::matching($select)));
        $this->assertContains('whereIn(id,3)', FakeCapsule::$calls);
    }

    /** O port real pede isolation/read-only explícitos e encerra a mesma conexão. */
    public function test_read_snapshot_uses_an_explicit_read_only_repeatable_read_transaction(): void
    {
        $this->seedFields(self::fieldRows());
        $port = new CapsuleQueryPort();

        $rows = $port->withinReadSnapshot(fn(): array => $port->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_FIELDS,
            columns: CrmSchema::catalogProjection(),
            limit: 1,
        )));

        $this->assertCount(1, $rows);
        $this->assertSame(
            [
                'write:SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY',
                'write:begin',
                'write:commit',
            ],
            FakeCapsule::$snapshotCalls,
        );
    }

    /** O lifecycle Illuminate fixa Query Builder e Schema Builder no write PDO. */
    public function test_snapshot_uses_one_illuminate_connection_and_never_the_read_pdo(): void
    {
        $this->seedFields(self::fieldRows());
        FakeSchemaBuilder::install(CrmSchemaFixture::completeInstallation());
        $port = new CapsuleQueryPort();
        $probe = new CapsuleSchemaProbe($port);
        $guard = new CrmSchemaGuard($probe);

        $port->withinReadSnapshot(function () use ($port, $guard): void {
            $this->assertSame(1, FakeCapsule::connection()->transactionLevel());
            $guard->assert(CrmCapability::ResourceCore);
            $port->selectRows(new CrmSelect(CrmSchema::TABLE_FIELDS, ['id'], limit: 1));
            $port->countRows(new CrmCount(CrmSchema::TABLE_FIELDS));
        });

        $this->assertNotSame([], FakeCapsule::$pdoCalls);
        $this->assertSame([], array_values(array_filter(
            FakeCapsule::$pdoCalls,
            static fn(string $call): bool => str_starts_with($call, 'read:')
        )));
        $this->assertContains('write:schema', FakeCapsule::$pdoCalls);
        $this->assertContains('write:query crm_fields', FakeCapsule::$pdoCalls);
        $this->assertSame(0, FakeCapsule::connection()->transactionLevel());
        $this->assertFalse(FakeCapsule::connection()->getPdo()->inTransaction());
    }

    /** Os quatro probes da revisão: bool false e camadas ambiente incoerentes. */
    public function test_snapshot_rejects_boolean_failures_and_ambient_illuminate_state(): void
    {
        foreach (['set', 'commit'] as $phase) {
            FakeCapsule::reset();
            $this->seedFields(self::fieldRows());
            FakeCapsule::$snapshotFailures[$phase] = false;
            $this->assertSame(CrmErrorCode::Downstream, $this->capture(
                fn() => (new CapsuleQueryPort())->withinReadSnapshot(static fn(): string => 'accepted')
            )->errorCode);
        }

        FakeCapsule::reset();
        $this->seedFields(self::fieldRows());
        FakeCapsule::$snapshotFailures['rollback'] = false;
        $this->assertSame(CrmErrorCode::Downstream, $this->capture(
            fn() => (new CapsuleQueryPort())->withinReadSnapshot(static function (): never {
                throw new \RuntimeException('body');
            })
        )->errorCode);
        $this->assertTrue(FakeCapsule::connection()->getPdo()->inTransaction(), 'cleanup impossível fica explícito');

        foreach ([[true, 0], [false, 1], [true, 1]] as [$pdoOpen, $level]) {
            FakeCapsule::reset();
            $this->seedFields(self::fieldRows());
            FakeCapsule::$ambientTransaction = $pdoOpen;
            FakeCapsule::$ambientTransactionLevel = $level;
            $this->assertSame(CrmErrorCode::Downstream, $this->capture(
                fn() => (new CapsuleQueryPort())->withinReadSnapshot(static fn(): string => 'accepted')
            )->errorCode);
            $this->assertSame([], FakeCapsule::$snapshotCalls, 'estado ambiente não é adotado');
        }
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

    /** VARCHAR numérico é textual; identificador BIGINT não passa por float. */
    public function test_fake_capsule_uses_schema_aware_varchar_and_bigint_ordering(): void
    {
        $this->seedFields([
            ['id' => '9007199254740993', 'name' => '9', 'deleted_at' => null],
            ['id' => '9007199254740992', 'name' => '10', 'deleted_at' => null],
            ['id' => '9007199254740994', 'name' => '100', 'deleted_at' => null],
        ]);

        $port = new CapsuleQueryPort();
        $byName = $port->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_FIELDS,
            columns: CrmSchema::catalogProjection(),
            order: CrmSchema::catalogOrder(),
            limit: CrmSchema::CHUNK_SIZE,
        ));
        $byId = $port->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_FIELDS,
            columns: CrmSchema::catalogProjection(),
            order: [[CrmSchema::COLUMN_ID, 'asc']],
            limit: CrmSchema::CHUNK_SIZE,
        ));

        $this->assertSame(['10', '100', '9'], array_column($byName, 'name'));
        $this->assertSame(
            ['9007199254740992', '9007199254740993', '9007199254740994'],
            array_column($byId, 'id'),
        );
    }

    // ---------------------------------------------------------------
    // Projeção e higiene
    // ---------------------------------------------------------------

    /** A projeção explícita sobrevive à tradução — nada de `SELECT *`. */
    public function test_projection_is_explicit(): void
    {
        $this->seedFields(self::fieldRows());

        $rows = (new CapsuleQueryPort())->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_FIELDS,
            columns: [CrmSchema::COLUMN_ID],
            conditions: [CrmSchema::COLUMN_ID => 1],
            limit: 1,
        ));

        $this->assertSame([['id' => 1]], $rows);
        $this->assertContains('select(id)', FakeCapsule::$calls);
    }

    /** Falha do driver vira `downstream`, sem mensagem crua. */
    public function test_driver_failure_becomes_sanitized_downstream(): void
    {
        FakeCapsule::$enabled = true;
        FakeCapsule::$failure = new \RuntimeException(
            'SQLSTATE[HY000] password=hunter2 /srv/whmcs SELECT * FROM crm_fields'
        );

        try {
            (new CapsuleQueryPort())->selectRows(new CrmSelect(
                table: CrmSchema::TABLE_FIELDS,
                columns: CrmSchema::catalogProjection(),
                limit: 1,
            ));
            $this->fail('esperava CrmException');
        } catch (CrmException $e) {
            $this->assertSame(CrmErrorCode::Downstream, $e->errorCode);

            foreach (['SQLSTATE', 'hunter2', '/srv/whmcs', 'SELECT', 'crm_fields'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $e->getMessage());
            }
        }
    }

    /** Nenhuma tradução do port produz mutação. */
    public function test_the_port_never_mutates(): void
    {
        $this->seedFields(self::fieldRows());

        $port = new CapsuleQueryPort();
        $port->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_FIELDS,
            columns: CrmSchema::catalogProjection(),
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            limit: CrmSchema::CHUNK_SIZE,
            inConditions: [CrmSchema::COLUMN_ID => [1, 2]],
            afterId: 1,
            throughId: 5,
        ));
        $port->countRows(new CrmCount(CrmSchema::TABLE_FIELDS, [], [CrmSchema::COLUMN_DELETED_AT], 5));

        $this->assertSame([], FakeCapsule::$mutations);
    }
}
