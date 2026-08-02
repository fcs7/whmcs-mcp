<?php

declare(strict_types=1);

namespace NtMcp\Tests\Crm;

use NtMcp\Crm\CrmCatalog;
use NtMcp\Crm\CrmErrorCode;
use NtMcp\Crm\CrmException;
use NtMcp\Crm\CrmSchema;
use NtMcp\Crm\CrmSchemaGuard;
use NtMcp\Crm\MgCrmRepository;
use NtMcp\Tests\Support\FakeAdminIdentityResolver;
use NtMcp\Tests\Support\FakeCrmQueryPort;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O contrato das QUATRO leituras de CRM-2, exercitado na fronteira.
 *
 * O foco é o que o ticket exige provar e que um teste de "caminho feliz" não
 * pega: paginação nas bordas, `count`/`has_more` sob o MESMO filtro dos itens,
 * soft-delete invisível, catálogo inativo distinguível de vazio, kanban que
 * publica catálogos mesmo sem recurso, custom fields em batch limitado, e a
 * diferença entre recurso ausente, schema quebrado e falha de driver.
 */
class CrmReadContractTest extends TestCase
{
    private FakeCrmQueryPort $port;

    protected function setUp(): void
    {
        $this->port = new FakeCrmQueryPort();
    }

    private function repository(?FakeCrmSchemaProbe $probe = null): MgCrmRepository
    {
        return new MgCrmRepository(
            new CrmSchemaGuard($probe ?? FakeCrmSchemaProbe::healthy()),
            $this->port,
            FakeAdminIdentityResolver::resolvingTo(7),
        );
    }

    private function capture(callable $operation): CrmException
    {
        try {
            $operation();
        } catch (CrmException $e) {
            return $e;
        }

        $this->fail('esperava uma CrmException');
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /** @param array<int, array<string, mixed>> $rows */
    private function seedResources(array $rows): void
    {
        $this->port->seed(CrmSchema::TABLE_RESOURCES, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private static function resourceRow(int $id, int $typeId = 1, int $statusId = 1, ?string $deletedAt = null): array
    {
        return [
            'id' => $id,
            'type_id' => $typeId,
            'status_id' => $statusId,
            'name' => 'Nome ' . $id,
            'lastname' => 'Sobrenome',
            'email' => 'r' . $id . '@example.test',
            'phone' => '+55 11 90000-0000',
            'country' => 'BR',
            'short_description' => 'curta',
            'description' => 'longa',
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-02 10:00:00',
            'deleted_at' => $deletedAt,
        ];
    }

    /** Catálogos ativos mínimos para os quatro enums. */
    private function seedCatalogs(): void
    {
        $this->port->seed(CrmSchema::TABLE_RESOURCE_TYPES, [
            ['id' => 1, 'name' => 'Lead', 'active' => 1, 'deleted_at' => null],
            ['id' => 2, 'name' => 'Cliente', 'active' => 1, 'deleted_at' => null],
            ['id' => 3, 'name' => 'Arquivado', 'active' => 0, 'deleted_at' => null],
        ]);
        $this->port->seed(CrmSchema::TABLE_RESOURCE_STATUSES, [
            ['id' => 10, 'name' => 'Aberto', 'active' => 1, 'deleted_at' => null],
            ['id' => 11, 'name' => 'Ganho', 'active' => 1, 'deleted_at' => null],
            ['id' => 12, 'name' => 'Perdido', 'active' => 0, 'deleted_at' => null],
            ['id' => 13, 'name' => 'Removido', 'active' => 1, 'deleted_at' => '2026-01-01 00:00:00'],
        ]);
        $this->port->seed(CrmSchema::TABLE_FOLLOWUP_TYPES, [
            ['id' => 20, 'name' => 'Ligacao', 'active' => 1, 'deleted_at' => null],
        ]);
        $this->port->seed(CrmSchema::TABLE_FOLLOWUP_STATUSES, [
            ['id' => 30, 'name' => 'Pendente', 'active' => 1, 'deleted_at' => null],
        ]);
    }

    // ---------------------------------------------------------------
    // listResources — paginação e filtros
    // ---------------------------------------------------------------

    public function test_empty_page_reports_zero_and_no_more(): void
    {
        $this->seedResources([]);

        $page = $this->repository()->listResources(null, null, 25, 0);

        $this->assertSame([], $page['items']);
        $this->assertSame(0, $page['count']);
        $this->assertSame(25, $page['limit']);
        $this->assertSame(0, $page['offset']);
        $this->assertFalse($page['has_more']);
    }

    public function test_full_page_reports_the_total_and_more_to_come(): void
    {
        $this->seedResources(array_map(
            static fn(int $id): array => self::resourceRow($id),
            range(1, 7)
        ));

        $page = $this->repository()->listResources(null, null, 3, 0);

        $this->assertCount(3, $page['items']);
        $this->assertSame(7, $page['count'], 'count é o total sob o filtro, não o tamanho da página');
        $this->assertTrue($page['has_more']);
    }

    public function test_last_page_has_no_more(): void
    {
        $this->seedResources(array_map(
            static fn(int $id): array => self::resourceRow($id),
            range(1, 7)
        ));

        $page = $this->repository()->listResources(null, null, 3, 6);

        $this->assertCount(1, $page['items']);
        $this->assertSame(7, $page['count']);
        $this->assertFalse($page['has_more']);
    }

    /** Offset além do fim é página vazia, não erro nem repetição da primeira. */
    public function test_offset_past_the_end_is_an_empty_page_with_the_real_total(): void
    {
        $this->seedResources(array_map(
            static fn(int $id): array => self::resourceRow($id),
            range(1, 4)
        ));

        $page = $this->repository()->listResources(null, null, 10, 50);

        $this->assertSame([], $page['items']);
        $this->assertSame(4, $page['count']);
        $this->assertFalse($page['has_more']);
    }

    public function test_ordering_is_deterministic_by_descending_id(): void
    {
        $this->seedResources([
            self::resourceRow(2), self::resourceRow(10), self::resourceRow(1),
        ]);

        $page = $this->repository()->listResources(null, null, 25, 0);

        $this->assertSame([10, 2, 1], array_column($page['items'], 'resource_id'));
    }

    /** O soft-delete some dos itens E do total — senão `has_more` mente. */
    public function test_soft_deleted_resources_leave_both_items_and_count(): void
    {
        $this->seedResources([
            self::resourceRow(1),
            self::resourceRow(2, deletedAt: '2026-06-01 00:00:00'),
            self::resourceRow(3, deletedAt: '2026-06-02 00:00:00'),
        ]);

        $page = $this->repository()->listResources(null, null, 25, 0);

        $this->assertSame([1], array_column($page['items'], 'resource_id'));
        $this->assertSame(1, $page['count']);
    }

    public function test_type_and_status_filters_narrow_items_and_count_together(): void
    {
        $this->seedCatalogs();
        $this->seedResources([
            self::resourceRow(1, typeId: 1, statusId: 10),
            self::resourceRow(2, typeId: 1, statusId: 11),
            self::resourceRow(3, typeId: 2, statusId: 10),
        ]);

        $page = $this->repository()->listResources(1, 10, 25, 0);

        $this->assertSame([1], array_column($page['items'], 'resource_id'));
        $this->assertSame(1, $page['count']);
    }

    /**
     * Filtro por catálogo INATIVO não pode virar lista vazia: o chamador
     * concluiria "não há recursos" e corrigiria o lugar errado.
     */
    #[DataProvider('invalidCatalogFilterProvider')]
    public function test_inactive_or_unknown_catalog_filter_is_refused(?int $typeId, ?int $statusId): void
    {
        $this->seedCatalogs();
        $this->seedResources([self::resourceRow(1)]);

        $this->assertSame(
            CrmErrorCode::CatalogInvalid,
            $this->capture(fn() => $this->repository()->listResources($typeId, $statusId, 25, 0))->errorCode
        );
    }

    /** @return array<string, array{0:int|null, 1:int|null}> */
    public static function invalidCatalogFilterProvider(): array
    {
        return [
            'tipo inativo' => [3, null],
            'tipo inexistente' => [99, null],
            'status inativo' => [null, 12],
            'status soft-deleted' => [null, 13],
        ];
    }

    public function test_limit_is_clamped_to_the_shared_ceiling(): void
    {
        $this->seedResources([self::resourceRow(1)]);

        $page = $this->repository()->listResources(null, null, 5000, 0);

        $this->assertSame(CrmSchema::MAX_LIMIT, $page['limit']);
    }

    /** Falha de schema não pode virar página vazia. */
    public function test_missing_resource_table_fails_closed_instead_of_empty(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropTable(CrmSchema::TABLE_RESOURCES);

        $this->assertSame(
            CrmErrorCode::Unavailable,
            $this->capture(fn() => $this->repository($probe)->listResources(null, null, 25, 0))->errorCode
        );
    }

    public function test_missing_resource_column_is_a_schema_mismatch(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropColumn(CrmSchema::TABLE_RESOURCES, 'country');

        $this->assertSame(
            CrmErrorCode::SchemaMismatch,
            $this->capture(fn() => $this->repository($probe)->listResources(null, null, 25, 0))->errorCode
        );
    }

    // ---------------------------------------------------------------
    // getResource — recurso e custom fields
    // ---------------------------------------------------------------

    public function test_resource_projection_is_closed_and_uses_the_public_identity(): void
    {
        $this->seedResources([self::resourceRow(42)]);
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, []);

        $result = $this->repository()->getResource(42);

        $this->assertSame(
            [
                'resource_id', 'type_id', 'status_id', 'name', 'lastname', 'email',
                'phone', 'country', 'short_description', 'description',
                'created_at', 'updated_at',
            ],
            array_keys($result['resource'])
        );
        $this->assertSame(42, $result['resource']['resource_id']);
        $this->assertArrayNotHasKey('id', $result['resource']);
        $this->assertArrayNotHasKey('admin_id', $result['resource']);
        $this->assertArrayNotHasKey('deleted_at', $result['resource']);
    }

    public function test_missing_resource_is_not_found(): void
    {
        $this->seedResources([self::resourceRow(42)]);

        $this->assertSame(
            CrmErrorCode::ResourceNotFound,
            $this->capture(fn() => $this->repository()->getResource(43))->errorCode
        );
    }

    public function test_soft_deleted_resource_is_not_found(): void
    {
        $this->seedResources([self::resourceRow(42, deletedAt: '2026-06-01 00:00:00')]);

        $this->assertSame(
            CrmErrorCode::ResourceNotFound,
            $this->capture(fn() => $this->repository()->getResource(42))->errorCode
        );
    }

    /**
     * Recurso ausente e schema indisponível são desfechos DIFERENTES, e a
     * distinção precisa sobreviver a um driver fora do ar.
     */
    public function test_resource_missing_schema_broken_and_downstream_are_distinguishable(): void
    {
        $this->seedResources([self::resourceRow(42)]);
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, []);

        $this->assertSame(
            CrmErrorCode::ResourceNotFound,
            $this->capture(fn() => $this->repository()->getResource(999))->errorCode
        );

        $this->assertSame(
            CrmErrorCode::Unavailable,
            $this->capture(fn() => $this->repository(
                FakeCrmSchemaProbe::healthy()->dropTable(CrmSchema::TABLE_RESOURCES)
            )->getResource(42))->errorCode
        );

        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->capture(fn() => $this->repository(
                FakeCrmSchemaProbe::healthy()->failWith('c0ffee01')
            )->getResource(42))->errorCode
        );

        $this->port->failWith(new \RuntimeException('SELECT * FROM crm_resources -- 11144477735'));
        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->capture(fn() => $this->repository()->getResource(42))->errorCode
        );
    }

    public function test_custom_fields_are_normalized_and_ordered(): void
    {
        $this->seedResources([self::resourceRow(42)]);
        $this->port->seed(CrmSchema::TABLE_FIELDS, [
            ['id' => 5, 'name' => 'Company Name', 'deleted_at' => null],
            ['id' => 6, 'name' => 'Budget', 'deleted_at' => null],
        ]);
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, [
            ['id' => 100, 'field_id' => '5', 'resource_id' => 42, 'value' => 'NT Web'],
            ['id' => 101, 'field_id' => 6, 'resource_id' => 42, 'value' => 1200],
            ['id' => 102, 'field_id' => 5, 'resource_id' => 43, 'value' => 'Outro recurso'],
        ]);

        $fields = $this->repository()->getResource(42)['custom_fields'];

        $this->assertSame(
            [
                ['field_id' => 6, 'name' => 'Budget', 'value' => '1200'],
                ['field_id' => 5, 'name' => 'Company Name', 'value' => 'NT Web'],
            ],
            $fields,
            'normalizado para {field_id,name,value}, ordenado por nome, e só do recurso pedido'
        );
    }

    /**
     * Valor vivo apontando para definição soft-deleted/ausente é INTEGRIDADE
     * QUEBRADA, não um campo a descartar em silêncio.
     *
     * O descarte anterior era indistinguível de "o recurso não tem esse campo":
     * a leitura apresentava um contato incompleto como completo.
     */
    #[DataProvider('orphanDefinitionProvider')]
    public function test_orphan_field_definition_breaks_integrity(?string $deletedAt): void
    {
        $this->seedResources([self::resourceRow(42)]);
        $this->port->seed(CrmSchema::TABLE_FIELDS, array_values(array_filter([
            ['id' => 5, 'name' => 'Visivel', 'deleted_at' => null],
            $deletedAt === null ? null : ['id' => 7, 'name' => 'Removido', 'deleted_at' => $deletedAt],
        ])));
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, [
            ['id' => 100, 'field_id' => 5, 'resource_id' => 42, 'value' => 'ok'],
            ['id' => 101, 'field_id' => 7, 'resource_id' => 42, 'value' => 'segredo'],
        ]);

        $failure = $this->capture(fn() => $this->repository()->getResource(42));

        $this->assertSame(CrmErrorCode::Downstream, $failure->errorCode);
        $this->assertStringNotContainsString('segredo', $failure->getMessage());
    }

    /** @return array<string, array{0:string|null}> */
    public static function orphanDefinitionProvider(): array
    {
        return [
            'definição soft-deleted' => ['2026-01-01 00:00:00'],
            'definição ausente' => [null],
        ];
    }

    /**
     * Definição além da PRIMEIRA página do catálogo global continua resolvida:
     * o lote é montado com os `field_id` do recurso, não varrendo `crm_fields`.
     */
    public function test_definition_beyond_the_first_page_is_still_resolved(): void
    {
        $this->seedResources([self::resourceRow(42)]);
        $this->port->seed(CrmSchema::TABLE_FIELDS, array_map(
            static fn(int $id): array => [
                'id' => $id,
                'name' => sprintf('F%04d', $id),
                'deleted_at' => null,
            ],
            range(1, 250)
        ));
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, [
            ['id' => 100, 'field_id' => 231, 'resource_id' => 42, 'value' => 'longe'],
        ]);

        $this->assertSame(
            [['field_id' => 231, 'name' => 'F0231', 'value' => 'longe']],
            $this->repository()->getResource(42)['custom_fields']
        );
    }

    /** Todos os values do recurso, mesmo acima de uma página. */
    public function test_custom_field_values_beyond_one_chunk_are_complete(): void
    {
        $this->seedResources([self::resourceRow(42)]);
        $this->port->seed(CrmSchema::TABLE_FIELDS, array_map(
            static fn(int $id): array => [
                'id' => $id,
                'name' => sprintf('F%04d', $id),
                'deleted_at' => null,
            ],
            range(1, 130)
        ));
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, array_map(
            static fn(int $id): array => [
                'id' => $id,
                'field_id' => $id,
                'resource_id' => 42,
                'value' => 'v' . $id,
            ],
            range(1, 130)
        ));

        $fields = $this->repository()->getResource(42)['custom_fields'];

        $this->assertCount(130, $fields);
        $this->assertSame(130, $fields[129]['field_id'], 'o 130º campo não pode sumir');
    }

    /** Vários values do mesmo campo são preservados, em ordem determinística. */
    public function test_multiple_values_of_the_same_field_are_preserved(): void
    {
        $this->seedResources([self::resourceRow(42)]);
        $this->port->seed(CrmSchema::TABLE_FIELDS, [
            ['id' => 5, 'name' => 'Tag', 'deleted_at' => null],
            ['id' => 6, 'name' => 'Budget', 'deleted_at' => null],
        ]);
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, [
            ['id' => 103, 'field_id' => 5, 'resource_id' => 42, 'value' => 'gamma'],
            ['id' => 101, 'field_id' => 5, 'resource_id' => 42, 'value' => 'alpha'],
            ['id' => 102, 'field_id' => 5, 'resource_id' => 42, 'value' => 'alpha'],
            ['id' => 100, 'field_id' => 6, 'resource_id' => 42, 'value' => '1200'],
        ]);

        $this->assertSame(
            [
                ['field_id' => 6, 'name' => 'Budget', 'value' => '1200'],
                ['field_id' => 5, 'name' => 'Tag', 'value' => 'alpha'],
                ['field_id' => 5, 'name' => 'Tag', 'value' => 'alpha'],
                ['field_id' => 5, 'name' => 'Tag', 'value' => 'gamma'],
            ],
            $this->repository()->getResource(42)['custom_fields'],
            'duplicatas preservadas; ordem por nome, field_id e id do valor'
        );
    }

    /**
     * Batch, não N+1: o número de consultas de custom field é FIXO,
     * independentemente de quantos valores o recurso tem.
     */
    public function test_custom_fields_are_read_in_a_bounded_batch(): void
    {
        $this->seedResources([self::resourceRow(42)]);
        $this->port->seed(CrmSchema::TABLE_FIELDS, array_map(
            static fn(int $id): array => ['id' => $id, 'name' => 'F' . $id, 'deleted_at' => null],
            range(1, 40)
        ));
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, array_map(
            static fn(int $id): array => ['id' => $id, 'field_id' => $id, 'resource_id' => 42, 'value' => 'v' . $id],
            range(1, 40)
        ));

        $result = $this->repository()->getResource(42);

        $this->assertCount(40, $result['custom_fields']);
        $this->assertCount(
            3,
            $this->port->selects,
            'recurso + valores + definições = 3 consultas, para qualquer quantidade de campos'
        );

        foreach ($this->port->selects as $select) {
            $this->assertLessThanOrEqual(CrmSchema::MAX_LIMIT, $select->limit);
        }
    }

    /** Recurso sem valores não paga a segunda consulta. */
    public function test_resource_without_custom_field_values_skips_the_definition_query(): void
    {
        $this->seedResources([self::resourceRow(42)]);
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, []);

        $this->assertSame([], $this->repository()->getResource(42)['custom_fields']);

        // A varredura conta antes de paginar: com total zero, nenhuma página é
        // buscada e a resolução de definições nem começa.
        $this->assertSame([CrmSchema::TABLE_RESOURCES], $this->port->selectedTables());
        $this->assertSame(
            [CrmSchema::TABLE_FIELD_VALUES],
            array_map(static fn($count): string => $count->table, $this->port->counts)
        );
    }

    /**
     * Capacidade de custom field não comprovada falha FECHADO — não devolve
     * `custom_fields: []`, que seria indistinguível de "não tem campos".
     */
    #[DataProvider('brokenCustomFieldSchemaProvider')]
    public function test_unprovable_custom_field_schema_fails_closed(
        string $table,
        ?string $column,
        CrmErrorCode $expected,
    ): void {
        $this->seedResources([self::resourceRow(42)]);
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, [
            ['id' => 100, 'field_id' => 5, 'resource_id' => 42, 'value' => 'x'],
        ]);

        $probe = FakeCrmSchemaProbe::healthy();
        $probe = $column === null ? $probe->dropTable($table) : $probe->dropColumn($table, $column);

        $this->assertSame(
            $expected,
            $this->capture(fn() => $this->repository($probe)->getResource(42))->errorCode
        );
    }

    /** @return array<string, array{0:string, 1:string|null, 2:CrmErrorCode}> */
    public static function brokenCustomFieldSchemaProvider(): array
    {
        return [
            'tabela de definição ausente' => [CrmSchema::TABLE_FIELDS, null, CrmErrorCode::Unavailable],
            'tabela de valores ausente' => [CrmSchema::TABLE_FIELD_VALUES, null, CrmErrorCode::Unavailable],
            'coluna de valor ausente' => [CrmSchema::TABLE_FIELD_VALUES, 'value', CrmErrorCode::SchemaMismatch],
        ];
    }

    // ---------------------------------------------------------------
    // listFollowups
    // ---------------------------------------------------------------

    /** @param array<int, array<string, mixed>> $rows */
    private function seedFollowups(array $rows): void
    {
        $this->port->seed(CrmSchema::TABLE_FOLLOWUPS, $rows);
    }

    /** @return array<string, mixed> */
    private static function followupRow(
        int $id,
        int $resourceId = 42,
        int $typeId = 20,
        int $statusId = 30,
        string $date = '2026-07-01 09:00:00',
        ?string $deletedAt = null,
    ): array {
        return [
            'id' => $id,
            'resource_id' => $resourceId,
            'type_id' => $typeId,
            'status_id' => $statusId,
            'admin_id' => 9,
            'description' => 'contato ' . $id,
            'date' => $date,
            'created_at' => '2026-06-30 08:00:00',
            'updated_at' => '2026-06-30 08:00:00',
            'deleted_at' => $deletedAt,
        ];
    }

    public function test_followups_resolve_labels_and_hide_the_internal_admin(): void
    {
        $this->seedCatalogs();
        $this->seedResources([self::resourceRow(42)]);
        $this->seedFollowups([self::followupRow(1)]);

        $page = $this->repository()->listFollowups(42, null, null, 25, 0);

        $this->assertSame(
            [
                'followup_id', 'resource_id', 'type_id', 'type_name',
                'status_id', 'status_name', 'description', 'date',
                'created_at', 'updated_at',
            ],
            array_keys($page['items'][0])
        );
        $this->assertSame('Ligacao', $page['items'][0]['type_name']);
        $this->assertSame('Pendente', $page['items'][0]['status_name']);
        $this->assertArrayNotHasKey('admin_id', $page['items'][0]);
    }

    /**
     * D13 — tipo/status EXISTENTE, não soft-deleted, porém `active = 0`
     * continua resolvendo o nome histórico.
     *
     * O follow-up antigo aponta legitimamente para um tipo que saiu de
     * circulação; perder o rótulo apagaria informação histórica válida.
     */
    public function test_inactive_catalog_still_resolves_the_historical_label(): void
    {
        $this->seedCatalogs();
        $this->port->seed(CrmSchema::TABLE_FOLLOWUP_TYPES, [
            ['id' => 20, 'name' => 'Ligacao', 'active' => 1, 'deleted_at' => null],
            ['id' => 21, 'name' => 'Telegrama', 'active' => 0, 'deleted_at' => null],
        ]);
        $this->seedResources([self::resourceRow(42)]);
        $this->seedFollowups([self::followupRow(1, typeId: 21)]);

        $item = $this->repository()->listFollowups(42, null, null, 25, 0)['items'][0];

        $this->assertSame(21, $item['type_id']);
        $this->assertSame('Telegrama', $item['type_name'], 'nome histórico preservado');
    }

    /** ...mas o catálogo PUBLICADO continua só com os ativos. */
    public function test_inactive_catalog_entry_stays_out_of_the_published_catalog(): void
    {
        $this->seedCatalogs();
        $this->port->seed(CrmSchema::TABLE_FOLLOWUP_TYPES, [
            ['id' => 20, 'name' => 'Ligacao', 'active' => 1, 'deleted_at' => null],
            ['id' => 21, 'name' => 'Telegrama', 'active' => 0, 'deleted_at' => null],
        ]);
        $this->seedResources([]);

        $this->assertSame(
            [['id' => 20, 'name' => 'Ligacao']],
            $this->repository()->getKanban(null, 25)['catalogs']['followup_types']
        );
    }

    /**
     * D13 — referência soft-deleted, ausente ou com nome inutilizável é
     * integridade quebrada. O `*_name: null` ambíguo não existe mais.
     *
     * @param array<int, array<string, mixed>> $types
     */
    #[DataProvider('brokenLabelReferenceProvider')]
    public function test_broken_label_reference_fails_as_integrity(array $types, int $typeId): void
    {
        $this->seedCatalogs();
        $this->port->seed(CrmSchema::TABLE_FOLLOWUP_TYPES, $types);
        $this->seedResources([self::resourceRow(42)]);
        $this->seedFollowups([self::followupRow(1, typeId: $typeId)]);

        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->capture(fn() => $this->repository()->listFollowups(42, null, null, 25, 0))->errorCode
        );
    }

    /** @return array<string, array{0:array<int, array<string, mixed>>, 1:int}> */
    public static function brokenLabelReferenceProvider(): array
    {
        return [
            'referência soft-deleted' => [
                [['id' => 22, 'name' => 'Apagado', 'active' => 1, 'deleted_at' => '2026-01-01 00:00:00']],
                22,
            ],
            'referência ausente' => [
                [['id' => 20, 'name' => 'Ligacao', 'active' => 1, 'deleted_at' => null]],
                77,
            ],
            'nome vazio' => [
                [['id' => 23, 'name' => '', 'active' => 1, 'deleted_at' => null]],
                23,
            ],
        ];
    }

    /** Os labels são resolvidos em LOTE pelos ids da página, sem N+1. */
    public function test_labels_are_resolved_in_one_batch_per_catalog(): void
    {
        $this->seedCatalogs();
        $this->port->seed(CrmSchema::TABLE_FOLLOWUP_TYPES, array_map(
            static fn(int $id): array => [
                'id' => $id,
                'name' => 'T' . $id,
                'active' => 1,
                'deleted_at' => null,
            ],
            range(20, 60)
        ));
        $this->seedResources([self::resourceRow(42)]);
        $this->seedFollowups(array_map(
            static fn(int $id): array => self::followupRow(
                $id,
                typeId: 19 + $id,
                date: sprintf('2026-07-%02d 09:00:00', $id)
            ),
            range(1, 25)
        ));

        $this->repository()->listFollowups(42, null, null, 25, 0);

        $labelSelects = array_filter(
            $this->port->selects,
            static fn($select): bool => $select->inConditions !== []
        );

        $this->assertCount(2, $labelSelects, 'um lote por catálogo, não um por follow-up');
    }

    public function test_followups_of_an_unknown_resource_are_not_found(): void
    {
        $this->seedCatalogs();
        $this->seedResources([]);
        $this->seedFollowups([self::followupRow(1)]);

        $this->assertSame(
            CrmErrorCode::ResourceNotFound,
            $this->capture(fn() => $this->repository()->listFollowups(42, null, null, 25, 0))->errorCode
        );
    }

    public function test_followups_paginate_and_exclude_soft_deleted(): void
    {
        $this->seedCatalogs();
        $this->seedResources([self::resourceRow(42)]);
        $this->seedFollowups([
            self::followupRow(1, date: '2026-07-01 09:00:00'),
            self::followupRow(2, date: '2026-07-02 09:00:00'),
            self::followupRow(3, date: '2026-07-03 09:00:00', deletedAt: '2026-07-04 00:00:00'),
            self::followupRow(4, resourceId: 99),
        ]);

        $page = $this->repository()->listFollowups(42, null, null, 1, 0);

        $this->assertSame([1], array_column($page['items'], 'followup_id'));
        $this->assertSame(2, $page['count'], 'só os do recurso, sem o soft-deleted');
        $this->assertTrue($page['has_more']);
    }

    /** Ordenação por data crescente com desempate por id. */
    public function test_followups_are_ordered_by_date_then_id(): void
    {
        $this->seedCatalogs();
        $this->seedResources([self::resourceRow(42)]);
        $this->seedFollowups([
            self::followupRow(3, date: '2026-07-05 09:00:00'),
            self::followupRow(1, date: '2026-07-01 09:00:00'),
            self::followupRow(2, date: '2026-07-01 09:00:00'),
        ]);

        $page = $this->repository()->listFollowups(42, null, null, 25, 0);

        $this->assertSame([1, 2, 3], array_column($page['items'], 'followup_id'));
    }

    public function test_followup_filters_are_validated_against_the_active_catalog(): void
    {
        $this->seedCatalogs();
        $this->seedResources([self::resourceRow(42)]);
        $this->seedFollowups([self::followupRow(1)]);

        $this->assertSame(1, $this->repository()->listFollowups(42, 20, 30, 25, 0)['count']);

        $this->assertSame(
            CrmErrorCode::CatalogInvalid,
            $this->capture(fn() => $this->repository()->listFollowups(42, 999, null, 25, 0))->errorCode
        );
    }

    /**
     * Catálogo de label ausente falha fechado: uma lista com todos os labels
     * nulos pareceria dado íntegro do mgCRM2.
     */
    public function test_missing_label_catalog_fails_closed(): void
    {
        $this->seedCatalogs();
        $this->seedResources([self::resourceRow(42)]);
        $this->seedFollowups([self::followupRow(1)]);

        $probe = FakeCrmSchemaProbe::healthy()->dropTable(CrmSchema::TABLE_FOLLOWUP_STATUSES);

        $this->assertSame(
            CrmErrorCode::Unavailable,
            $this->capture(fn() => $this->repository($probe)->listFollowups(42, null, null, 25, 0))->errorCode
        );
    }

    /**
     * A leitura NÃO exige `crm_followups.admin_id`: ela não projeta nem filtra
     * essa coluna, e um drift nela não pode derrubar a listagem pública.
     */
    public function test_reading_followups_does_not_require_the_author_column(): void
    {
        $this->seedCatalogs();
        $this->seedResources([self::resourceRow(42)]);
        $this->seedFollowups([self::followupRow(1)]);

        $probe = FakeCrmSchemaProbe::healthy()->dropColumn(CrmSchema::TABLE_FOLLOWUPS, 'admin_id');

        $page = $this->repository($probe)->listFollowups(42, null, null, 25, 0);

        $this->assertSame(1, $page['count']);
    }

    // ---------------------------------------------------------------
    // getKanban
    // ---------------------------------------------------------------

    /** Catálogos são a razão de ser da tool: saem mesmo sem nenhum recurso. */
    public function test_kanban_publishes_catalogs_even_without_resources(): void
    {
        $this->seedCatalogs();
        $this->seedResources([]);

        $kanban = $this->repository()->getKanban(null, 25);

        $this->assertSame(
            ['resource_types', 'resource_statuses', 'followup_types', 'followup_statuses'],
            array_keys($kanban['catalogs'])
        );
        $this->assertSame(
            [['id' => 2, 'name' => 'Cliente'], ['id' => 1, 'name' => 'Lead']],
            $kanban['catalogs']['resource_types'],
            'só os ativos, ordenados por nome'
        );
        $this->assertSame(
            [['id' => 10, 'name' => 'Aberto'], ['id' => 11, 'name' => 'Ganho']],
            $kanban['catalogs']['resource_statuses'],
            'inativo e soft-deleted ficam de fora'
        );

        $this->assertCount(2, $kanban['lanes']);
        foreach ($kanban['lanes'] as $lane) {
            $this->assertSame(0, $lane['total']);
            $this->assertSame([], $lane['items']);
            $this->assertFalse($lane['has_more']);
        }
    }

    public function test_kanban_lanes_carry_exact_totals_with_limited_items(): void
    {
        $this->seedCatalogs();
        $this->seedResources(array_merge(
            array_map(static fn(int $id): array => self::resourceRow($id, statusId: 10), range(1, 5)),
            [self::resourceRow(20, statusId: 11)],
            [self::resourceRow(21, statusId: 10, deletedAt: '2026-06-01 00:00:00')],
        ));

        $kanban = $this->repository()->getKanban(null, 2);

        $aberto = $kanban['lanes'][0];
        $this->assertSame(10, $aberto['status_id']);
        $this->assertSame('Aberto', $aberto['status_name']);
        $this->assertSame(5, $aberto['total'], 'total exato, sem o soft-deleted');
        $this->assertCount(2, $aberto['items'], 'itens limitados por limit_per_status');
        $this->assertTrue($aberto['has_more']);

        $ganho = $kanban['lanes'][1];
        $this->assertSame(1, $ganho['total']);
        $this->assertCount(1, $ganho['items']);
        $this->assertFalse($ganho['has_more']);
    }

    public function test_kanban_type_filter_applies_to_every_lane(): void
    {
        $this->seedCatalogs();
        $this->seedResources([
            self::resourceRow(1, typeId: 1, statusId: 10),
            self::resourceRow(2, typeId: 2, statusId: 10),
            self::resourceRow(3, typeId: 2, statusId: 11),
        ]);

        $kanban = $this->repository()->getKanban(2, 25);

        $this->assertSame(2, $kanban['type_id']);
        $this->assertSame(1, $kanban['lanes'][0]['total']);
        $this->assertSame([2], array_column($kanban['lanes'][0]['items'], 'resource_id'));
        $this->assertSame(1, $kanban['lanes'][1]['total']);
    }

    public function test_kanban_rejects_an_inactive_type_filter(): void
    {
        $this->seedCatalogs();
        $this->seedResources([self::resourceRow(1)]);

        $this->assertSame(
            CrmErrorCode::CatalogInvalid,
            $this->capture(fn() => $this->repository()->getKanban(3, 25))->errorCode
        );
    }

    public function test_kanban_clamps_limit_per_status(): void
    {
        $this->seedCatalogs();
        $this->seedResources([]);

        $this->assertSame(
            CrmSchema::MAX_LIMIT_PER_STATUS,
            $this->repository()->getKanban(null, 999)['limit_per_status']
        );
    }

    /**
     * TODO status ativo vira raia. O desenho anterior parava em 25 e anunciava
     * a omissão num campo — o recurso do 30º status simplesmente sumia.
     */
    public function test_every_active_status_gets_a_lane(): void
    {
        $this->seedCatalogs();
        $this->port->seed(CrmSchema::TABLE_RESOURCE_STATUSES, array_map(
            static fn(int $id): array => [
                'id' => $id,
                'name' => sprintf('S%03d', $id),
                'active' => 1,
                'deleted_at' => null,
            ],
            range(1, 30)
        ));
        $this->seedResources([self::resourceRow(7, statusId: 30)]);

        $kanban = $this->repository()->getKanban(null, 25);

        $this->assertCount(30, $kanban['lanes']);
        $this->assertArrayNotHasKey('lanes_truncated', $kanban);

        $lane30 = $kanban['lanes'][29];
        $this->assertSame(30, $lane30['status_id']);
        $this->assertSame(1, $lane30['total'], 'o recurso do 30º status não pode desaparecer');
        $this->assertSame([7], array_column($lane30['items'], 'resource_id'));
    }

    /** Raia vazia não paga a consulta de itens — o custo segue os statuses com dado. */
    public function test_empty_lanes_do_not_query_items(): void
    {
        $this->seedCatalogs();
        $this->port->seed(CrmSchema::TABLE_RESOURCE_STATUSES, array_map(
            static fn(int $id): array => [
                'id' => $id,
                'name' => sprintf('S%03d', $id),
                'active' => 1,
                'deleted_at' => null,
            ],
            range(1, 30)
        ));
        $this->seedResources([self::resourceRow(7, statusId: 30)]);

        $this->repository()->getKanban(null, 25);

        $laneSelects = array_filter(
            $this->port->selects,
            static fn($select): bool => $select->table === CrmSchema::TABLE_RESOURCES
        );

        $this->assertCount(1, $laneSelects, 'só a raia com recurso consulta itens');
    }

    /** Catálogo completo acima de uma página — o 101º id continua publicável. */
    public function test_catalogs_are_complete_beyond_one_chunk(): void
    {
        $this->seedCatalogs();
        $this->port->seed(CrmSchema::TABLE_RESOURCE_TYPES, array_map(
            static fn(int $id): array => [
                'id' => $id,
                'name' => sprintf('T%04d', $id),
                'active' => 1,
                'deleted_at' => null,
            ],
            range(1, 101)
        ));
        $this->seedResources([]);

        $types = $this->repository()->getKanban(null, 25)['catalogs']['resource_types'];

        $this->assertCount(101, $types);
        $this->assertSame(101, $types[100]['id'], 'o 101º id não pode ser cortado');
    }

    /** Teto de segurança FALHA FECHADO; nunca devolve sucesso parcial. */
    public function test_catalog_above_the_safety_ceiling_fails_closed(): void
    {
        $this->seedCatalogs();
        $this->port->seed(CrmSchema::TABLE_RESOURCE_TYPES, array_map(
            static fn(int $id): array => [
                'id' => $id,
                'name' => sprintf('T%06d', $id),
                'active' => 1,
                'deleted_at' => null,
            ],
            range(1, CrmSchema::MAX_CATALOG_TOTAL + 1)
        ));
        $this->seedResources([]);

        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->capture(fn() => $this->repository()->getKanban(null, 25))->errorCode
        );
    }

    /** Idem para os valores de custom field. */
    public function test_custom_field_values_above_the_safety_ceiling_fail_closed(): void
    {
        $this->seedResources([self::resourceRow(42)]);
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, array_map(
            static fn(int $id): array => [
                'id' => $id,
                'field_id' => 1,
                'resource_id' => 42,
                'value' => 'v',
            ],
            range(1, CrmSchema::MAX_CUSTOM_FIELD_VALUES + 1)
        ));

        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->capture(fn() => $this->repository()->getResource(42))->errorCode
        );
    }

    // ---------------------------------------------------------------
    // Fidelidade do dublê
    // ---------------------------------------------------------------

    /**
     * BIGINT adjacente acima de `2^53` ordena corretamente.
     *
     * O dublê convertia os dois lados para `float`, e `float` não distingue
     * `9007199254740992` de `9007199254740993`: a ordenação descendente saía
     * invertida NO FAKE, enquanto o MySQL ordena BIGINT sem perda. Uma
     * regressão de paginação passaria aqui e quebraria em produção.
     */
    public function test_fake_orders_bigint_ids_above_two_to_the_fifty_three(): void
    {
        $this->seedResources([
            array_merge(self::resourceRow(1), ['id' => '9007199254740992']),
            array_merge(self::resourceRow(2), ['id' => '9007199254740993']),
            array_merge(self::resourceRow(3), ['id' => '9007199254740991']),
        ]);

        $page = $this->repository()->listResources(null, null, 25, 0);

        $this->assertSame(
            [9007199254740993, 9007199254740992, 9007199254740991],
            array_column($page['items'], 'resource_id'),
            'ordem descendente exata, sem perda de precisão'
        );
    }

    /** Ordem numérica, não lexicográfica, para ids de comprimentos diferentes. */
    public function test_fake_orders_numerically_not_lexicographically(): void
    {
        $this->seedResources([
            self::resourceRow(9),
            self::resourceRow(10),
            self::resourceRow(100),
        ]);

        $this->assertSame(
            [100, 10, 9],
            array_column($this->repository()->listResources(null, null, 25, 0)['items'], 'resource_id')
        );
    }

    // ---------------------------------------------------------------
    // Higiene das falhas
    // ---------------------------------------------------------------

    /**
     * Nenhuma mensagem pública das leituras transporta SQL, nome de tabela,
     * driver, path, PII ou segredo — nem quando a causa contém tudo isso.
     */
    public function test_read_failures_never_leak_sql_table_names_or_pii(): void
    {
        $this->seedCatalogs();
        $this->seedResources([self::resourceRow(42)]);
        $this->seedFollowups([self::followupRow(1)]);

        $poison = new \RuntimeException(
            "SQLSTATE[42S02]: SELECT * FROM crm_resources WHERE email='ana@example.test' "
            . '/var/www/whmcs/init.php cpf=11144477735 senha=hunter2'
        );

        $messages = [];

        $messages[] = $this->capture(fn() => $this->repository()->listResources(999, null, 25, 0))->getMessage();
        $messages[] = $this->capture(fn() => $this->repository()->getResource(999))->getMessage();
        $messages[] = $this->capture(fn() => $this->repository(
            FakeCrmSchemaProbe::healthy()->dropTable(CrmSchema::TABLE_RESOURCES)
        )->listResources(null, null, 25, 0))->getMessage();

        $this->port->failWith($poison);
        $messages[] = $this->capture(fn() => $this->repository()->getResource(42))->getMessage();

        foreach ($messages as $message) {
            foreach ([
                'SELECT', 'FROM', 'SQLSTATE', 'crm_resources', 'crm_fields',
                'mod_mgcrm_', 'ana@example.test', '11144477735', 'hunter2',
                '/var/www', 'PDO', 'RuntimeException',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $message, "vazou: {$forbidden}");
            }
        }
    }

    /**
     * Uma leitura consulta APENAS as tabelas do seu recorte. Em particular,
     * nada de `tbladmins` — nenhuma leitura resolve autoria.
     */
    public function test_reads_never_touch_the_admin_identity_table(): void
    {
        $this->seedCatalogs();
        $this->seedResources([self::resourceRow(42)]);
        $this->seedFollowups([self::followupRow(1)]);
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, []);

        $repository = $this->repository();
        $repository->listResources(null, null, 25, 0);
        $repository->getResource(42);
        $repository->listFollowups(42, null, null, 25, 0);
        $repository->getKanban(null, 25);

        $this->assertNotContains(CrmSchema::TABLE_ADMINS, $this->port->selectedTables());
        $this->assertNotEmpty($this->port->counts, 'as leituras paginadas contam pelo seam fechado');
    }

    /**
     * Todo SELECT paginado nasce do mesmo filtro de um COUNT — é isso que faz
     * `count`/`has_more` descreverem o mesmo recorte dos `items`.
     *
     * A direção é select→count de propósito: um COUNT sem select é legítimo
     * (raia vazia pula a consulta de itens, varredura de total zero não pagina),
     * mas um select paginado SEM count seria um total inventado.
     */
    public function test_every_paginated_select_shares_its_filter_with_a_count(): void
    {
        $this->seedCatalogs();
        $this->seedResources([self::resourceRow(1, statusId: 10)]);
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, []);

        $repository = $this->repository();
        $repository->listResources(null, null, 25, 0);
        $repository->getResource(1);
        $repository->getKanban(null, 25);

        foreach ($this->port->selects as $select) {
            // Selects de resolução por id (labels/definições) não paginam: eles
            // pedem um conjunto conhecido de ids, não uma fatia de um total.
            if ($select->inConditions !== []) {
                continue;
            }

            // A busca de recurso único por id também não é paginada.
            if ($select->limit === 1) {
                continue;
            }

            $matched = false;
            foreach ($this->port->counts as $count) {
                if ($select->table === $count->table
                    && $select->conditions === $count->conditions
                    && $select->nullColumns === $count->nullColumns
                ) {
                    $matched = true;
                    break;
                }
            }

            $this->assertTrue($matched, 'select paginado sem contagem correspondente em ' . $select->table);
        }
    }
}
