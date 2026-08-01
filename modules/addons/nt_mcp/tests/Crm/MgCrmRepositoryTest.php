<?php

declare(strict_types=1);

namespace NtMcp\Tests\Crm;

use NtMcp\Crm\CrmCapability;
use NtMcp\Crm\CrmCatalog;
use NtMcp\Crm\CrmErrorCode;
use NtMcp\Crm\CrmException;
use NtMcp\Crm\CrmSchema;
use NtMcp\Crm\CrmSchemaGuard;
use NtMcp\Crm\CrmWriteGate;
use NtMcp\Crm\MgCrmRepository;
use NtMcp\Tests\Support\CrmSchemaFixture;
use NtMcp\Tests\Support\FakeAdminIdentityResolver;
use NtMcp\Tests\Support\FakeCrmQueryPort;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * As barreiras da fronteira, uma a uma, e a contagem de efeitos: nenhuma falha
 * deste ticket pode produzir mutação.
 */
class MgCrmRepositoryTest extends TestCase
{
    private FakeCrmQueryPort $port;

    protected function setUp(): void
    {
        $this->port = new FakeCrmQueryPort();
    }

    private function repository(
        ?FakeCrmSchemaProbe $probe = null,
        ?FakeAdminIdentityResolver $admins = null,
        bool $writable = true,
    ): MgCrmRepository {
        return new MgCrmRepository(
            new CrmSchemaGuard($probe ?? FakeCrmSchemaProbe::healthy()),
            $this->port,
            $admins ?? FakeAdminIdentityResolver::resolvingTo(7),
            new CrmWriteGate($writable),
        );
    }

    private function seedResource(int $id, ?string $deletedAt = null): void
    {
        $this->port->seed(CrmSchema::TABLE_RESOURCES, [
            ['id' => $id, 'name' => 'Ana', 'email' => 'ana@example.test', 'deleted_at' => $deletedAt],
        ]);
    }

    // ---------------------------------------------------------------
    // Recurso
    // ---------------------------------------------------------------

    public function test_existing_resource_is_accepted(): void
    {
        $this->seedResource(42);

        $this->assertSame(42, $this->repository()->requireResource(42));
    }

    public function test_unknown_resource_is_not_found(): void
    {
        $this->seedResource(42);

        $this->assertSame(
            CrmErrorCode::ResourceNotFound,
            $this->capture(fn() => $this->repository()->requireResource(43))->errorCode
        );
    }

    /** Soft-deleted é indistinguível de inexistente para a superfície. */
    public function test_soft_deleted_resource_is_not_found(): void
    {
        $this->seedResource(42, '2026-07-01 10:00:00');

        $this->assertSame(
            CrmErrorCode::ResourceNotFound,
            $this->capture(fn() => $this->repository()->requireResource(42))->errorCode
        );
    }

    /** A leitura precisa filtrar o soft-delete no SQL, não em PHP. */
    public function test_resource_lookup_filters_soft_delete_in_the_query(): void
    {
        $this->seedResource(42);
        $this->repository()->requireResource(42);

        $select = $this->port->selects[0];
        $this->assertSame(CrmSchema::TABLE_RESOURCES, $select->table);
        $this->assertSame([CrmSchema::COLUMN_DELETED_AT], $select->nullColumns);
        $this->assertSame(['id'], $select->columns, 'a existência não precisa de mais nada');
        $this->assertSame(1, $select->limit);
    }

    #[DataProvider('invalidIdProvider')]
    public function test_non_positive_resource_id_is_validation_and_never_queries(int $id): void
    {
        $this->assertSame(
            CrmErrorCode::Validation,
            $this->capture(fn() => $this->repository()->requireResource($id))->errorCode
        );
        $this->assertSame([], $this->port->selects, 'input inválido não chega ao banco');
    }

    /** @return array<string, array{0:int}> */
    public static function invalidIdProvider(): array
    {
        return ['zero' => [0], 'negativo' => [-1], 'mínimo' => [PHP_INT_MIN]];
    }

    /** Schema fechado impede a consulta antes de ela existir. */
    public function test_schema_failure_prevents_the_resource_query(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropTable(CrmSchema::TABLE_RESOURCES);

        $this->assertSame(
            CrmErrorCode::Unavailable,
            $this->capture(fn() => $this->repository($probe)->requireResource(42))->errorCode
        );
        $this->assertSame([], $this->port->selects);
    }

    // ---------------------------------------------------------------
    // Catálogos
    // ---------------------------------------------------------------

    #[DataProvider('catalogProvider')]
    public function test_active_catalog_entry_is_accepted(CrmCatalog $catalog): void
    {
        $this->port->seed($catalog->table(), [
            ['id' => 3, 'name' => 'Qualquer', 'active' => 1, 'deleted_at' => null],
        ]);

        $this->assertSame(3, $this->repository()->requireCatalogEntry($catalog, 3));
    }

    #[DataProvider('catalogProvider')]
    public function test_inactive_catalog_entry_is_invalid(CrmCatalog $catalog): void
    {
        $this->port->seed($catalog->table(), [
            ['id' => 3, 'name' => 'Qualquer', 'active' => 0, 'deleted_at' => null],
        ]);

        $this->assertSame(
            CrmErrorCode::CatalogInvalid,
            $this->capture(fn() => $this->repository()->requireCatalogEntry($catalog, 3))->errorCode
        );
    }

    #[DataProvider('catalogProvider')]
    public function test_soft_deleted_catalog_entry_is_invalid(CrmCatalog $catalog): void
    {
        $this->port->seed($catalog->table(), [
            ['id' => 3, 'name' => 'Qualquer', 'active' => 1, 'deleted_at' => '2026-01-01 00:00:00'],
        ]);

        $this->assertSame(
            CrmErrorCode::CatalogInvalid,
            $this->capture(fn() => $this->repository()->requireCatalogEntry($catalog, 3))->errorCode
        );
    }

    #[DataProvider('catalogProvider')]
    public function test_absent_catalog_entry_is_invalid(CrmCatalog $catalog): void
    {
        $this->port->seed($catalog->table(), []);

        $this->assertSame(
            CrmErrorCode::CatalogInvalid,
            $this->capture(fn() => $this->repository()->requireCatalogEntry($catalog, 3))->errorCode
        );
    }

    /** @return array<string, array{0:CrmCatalog}> */
    public static function catalogProvider(): array
    {
        $cases = [];
        foreach (CrmCatalog::cases() as $catalog) {
            $cases[$catalog->value] = [$catalog];
        }

        return $cases;
    }

    /**
     * Um id válido no catálogo ERRADO não passa: o catálogo é escolhido pelo
     * contrato, nunca inferido do número.
     */
    public function test_id_valid_in_another_catalog_is_rejected(): void
    {
        $this->port->seed(CrmCatalog::ResourceType->table(), [
            ['id' => 3, 'name' => 'Lead', 'active' => 1, 'deleted_at' => null],
        ]);
        $this->port->seed(CrmCatalog::ResourceStatus->table(), []);

        $this->assertSame(3, $this->repository()->requireCatalogEntry(CrmCatalog::ResourceType, 3));
        $this->assertSame(
            CrmErrorCode::CatalogInvalid,
            $this->capture(
                fn() => $this->repository()->requireCatalogEntry(CrmCatalog::ResourceStatus, 3)
            )->errorCode
        );
    }

    /** Sem a coluna `active`, "ativo" é "não soft-deleted" — e o filtro some. */
    public function test_without_active_column_only_soft_delete_filters(): void
    {
        $probe = new FakeCrmSchemaProbe(CrmSchemaFixture::withoutCatalogActiveColumn());
        $this->port->seed(CrmCatalog::ResourceType->table(), [
            ['id' => 3, 'name' => 'Lead', 'deleted_at' => null],
        ]);

        $this->assertSame(3, $this->repository($probe)->requireCatalogEntry(CrmCatalog::ResourceType, 3));
        $this->assertArrayNotHasKey('active', $this->port->selects[0]->conditions);
    }

    public function test_with_active_column_the_filter_is_applied_in_the_query(): void
    {
        $this->port->seed(CrmCatalog::ResourceType->table(), [
            ['id' => 3, 'name' => 'Lead', 'active' => 1, 'deleted_at' => null],
        ]);

        $this->repository()->requireCatalogEntry(CrmCatalog::ResourceType, 3);

        $this->assertSame(1, $this->port->selects[0]->conditions['active'] ?? null);
        $this->assertSame([CrmSchema::COLUMN_DELETED_AT], $this->port->selects[0]->nullColumns);
    }

    public function test_catalog_schema_failure_prevents_the_query(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropColumn(CrmSchema::TABLE_RESOURCE_TYPES, 'name');

        $this->assertSame(
            CrmErrorCode::SchemaMismatch,
            $this->capture(
                fn() => $this->repository($probe)->requireCatalogEntry(CrmCatalog::ResourceType, 3)
            )->errorCode
        );
        $this->assertSame([], $this->port->selects);
    }

    // ---------------------------------------------------------------
    // Identidade administrativa e ZERO efeito
    // ---------------------------------------------------------------

    public function test_prepare_write_resolves_the_oauth_admin(): void
    {
        $context = $this->repository()->prepareWrite('operador', CrmCapability::Resources);

        $this->assertSame(7, $context->adminId);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $context->timestamp);
        $this->assertSame([], $this->port->mutations, 'preparar não é escrever');
    }

    public function test_unresolved_admin_blocks_the_write_with_zero_effects(): void
    {
        $repository = $this->repository(admins: FakeAdminIdentityResolver::failing());

        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->capture(fn() => $repository->prepareWrite('fantasma', CrmCapability::Resources))->errorCode
        );
        $this->assertSame([], $this->port->mutations);
    }

    public function test_closed_schema_blocks_the_write_before_touching_identity(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropTable(CrmSchema::TABLE_RESOURCES);
        $admins = FakeAdminIdentityResolver::resolvingTo(7);

        $this->assertSame(
            CrmErrorCode::Unavailable,
            $this->capture(
                fn() => $this->repository($probe, $admins)->prepareWrite('operador', CrmCapability::Resources)
            )->errorCode
        );
        $this->assertSame([], $admins->calls, 'schema fechado nem chega a consultar admin');
        $this->assertSame([], $this->port->mutations);
    }

    public function test_closed_write_gate_blocks_before_touching_identity(): void
    {
        $admins = FakeAdminIdentityResolver::resolvingTo(7);

        $exception = $this->capture(
            fn() => $this->repository(admins: $admins, writable: false)
                ->prepareWrite('operador', CrmCapability::Resources)
        );

        $this->assertSame(CrmErrorCode::Validation, $exception->errorCode);
        $this->assertSame([], $admins->calls);
        $this->assertSame([], $this->port->mutations);
    }

    /** Falha do driver vira `downstream`, sem mensagem crua e sem efeito. */
    public function test_driver_failure_becomes_downstream_without_leaking(): void
    {
        $this->seedResource(42);
        $this->port->failWith(new \RuntimeException('SQLSTATE[28000] password=hunter2SuperSecret'));

        $exception = $this->capture(fn() => $this->repository()->requireResource(42));

        $this->assertSame(CrmErrorCode::Downstream, $exception->errorCode);
        $this->assertStringNotContainsString('hunter2SuperSecret', $exception->getMessage());
        $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
        $this->assertSame([], $this->port->mutations);
    }

    // ---------------------------------------------------------------
    // Limites e instante
    // ---------------------------------------------------------------

    public function test_limits_are_clamped_to_the_shared_bounds(): void
    {
        $repository = $this->repository();

        $this->assertSame(CrmSchema::MAX_LIMIT, $repository->clampLimit(10_000));
        $this->assertSame(CrmSchema::DEFAULT_LIMIT, $repository->clampLimit(0));
        $this->assertSame(CrmSchema::DEFAULT_LIMIT, $repository->clampLimit(-5));
        $this->assertSame(10, $repository->clampLimit(10));
        $this->assertSame(
            CrmSchema::MAX_LIMIT_PER_STATUS,
            $repository->clampLimit(500, CrmSchema::MAX_LIMIT_PER_STATUS)
        );
        $this->assertSame(0, $repository->clampOffset(-1));
        $this->assertSame(CrmSchema::MAX_OFFSET, $repository->clampOffset(PHP_INT_MAX));
    }

    public function test_instant_normalisation_is_exposed_by_the_boundary(): void
    {
        $this->assertSame(
            '2026-08-11 02:30:00',
            $this->repository()->normalizeInstant('2026-08-10T23:30:00-03:00', 'date')
        );

        $this->assertSame(
            CrmErrorCode::Validation,
            $this->capture(fn() => $this->repository()->normalizeInstant('2026-08-10', 'date'))->errorCode
        );
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
}
