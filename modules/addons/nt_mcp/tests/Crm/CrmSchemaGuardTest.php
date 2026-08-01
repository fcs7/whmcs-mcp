<?php

declare(strict_types=1);

namespace NtMcp\Tests\Crm;

use NtMcp\Crm\CrmCapability;
use NtMcp\Crm\CrmErrorCode;
use NtMcp\Crm\CrmException;
use NtMcp\Crm\CrmSchema;
use NtMcp\Crm\CrmSchemaGuard;
use NtMcp\Tests\Support\CrmSchemaFixture;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Todos os estados do schema guard, incluindo o que a revisão exige
 * explicitamente: TABELA PRESENTE COM COLUNA FALTANDO.
 */
class CrmSchemaGuardTest extends TestCase
{
    private function guard(FakeCrmSchemaProbe $probe): CrmSchemaGuard
    {
        return new CrmSchemaGuard($probe);
    }

    #[DataProvider('everyCapabilityProvider')]
    public function test_healthy_installation_satisfies_every_capability(CrmCapability $capability): void
    {
        $this->guard(FakeCrmSchemaProbe::healthy())->assert($capability);

        $this->assertTrue(true, 'nenhuma capacidade pode falhar numa instalação completa');
    }

    /** @return array<string, array{0:CrmCapability}> */
    public static function everyCapabilityProvider(): array
    {
        $cases = [];
        foreach (CrmCapability::cases() as $capability) {
            $cases[$capability->value] = [$capability];
        }

        return $cases;
    }

    /** Tabela ausente é `crm_unavailable` — o addon não está lá. */
    #[DataProvider('missingTableProvider')]
    public function test_missing_table_is_unavailable(CrmCapability $capability, string $table): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropTable($table);

        $exception = $this->capture(fn() => $this->guard($probe)->assert($capability));

        $this->assertSame(CrmErrorCode::Unavailable, $exception->errorCode);
    }

    /** @return array<string, array{0:CrmCapability,1:string}> */
    public static function missingTableProvider(): array
    {
        return [
            'resources' => [CrmCapability::Resources, CrmSchema::TABLE_RESOURCES],
            'followups' => [CrmCapability::Followups, CrmSchema::TABLE_FOLLOWUPS],
            'notes' => [CrmCapability::Notes, CrmSchema::TABLE_NOTES],
            'resource catalogs' => [CrmCapability::ResourceCatalogs, CrmSchema::TABLE_RESOURCE_STATUSES],
            'followup catalogs' => [CrmCapability::FollowupCatalogs, CrmSchema::TABLE_FOLLOWUP_TYPES],
            'custom fields' => [CrmCapability::CustomFields, CrmSchema::TABLE_FIELD_VALUES],
            'admin identity' => [CrmCapability::AdminIdentity, CrmSchema::TABLE_ADMINS],
        ];
    }

    /** Tabela presente, coluna exigida ausente: `crm_schema_mismatch`. */
    #[DataProvider('missingColumnProvider')]
    public function test_present_table_with_missing_column_is_schema_mismatch(
        CrmCapability $capability,
        string $table,
        string $column,
    ): void {
        $probe = FakeCrmSchemaProbe::healthy()->dropColumn($table, $column);

        $exception = $this->capture(fn() => $this->guard($probe)->assert($capability));

        $this->assertSame(CrmErrorCode::SchemaMismatch, $exception->errorCode);
    }

    /** @return array<string, array{0:CrmCapability,1:string,2:string}> */
    public static function missingColumnProvider(): array
    {
        return [
            'resources sem soft-delete' => [
                CrmCapability::Resources, CrmSchema::TABLE_RESOURCES, 'deleted_at',
            ],
            'resources sem short_description' => [
                CrmCapability::Resources, CrmSchema::TABLE_RESOURCES, 'short_description',
            ],
            'followups sem date' => [
                CrmCapability::Followups, CrmSchema::TABLE_FOLLOWUPS, 'date',
            ],
            'followups sem admin_id' => [
                CrmCapability::Followups, CrmSchema::TABLE_FOLLOWUPS, 'admin_id',
            ],
            'notes sem content' => [
                CrmCapability::Notes, CrmSchema::TABLE_NOTES, 'content',
            ],
            'catálogo sem name' => [
                CrmCapability::ResourceCatalogs, CrmSchema::TABLE_RESOURCE_TYPES, 'name',
            ],
            'admins sem disabled' => [
                CrmCapability::AdminIdentity, CrmSchema::TABLE_ADMINS, 'disabled',
            ],
        ];
    }

    /**
     * O recorte por capacidade é o que impede uma incerteza local de derrubar a
     * superfície inteira: sem `crm_resources.admin_id` as LEITURAS continuam
     * disponíveis, só a atribuição falha.
     */
    public function test_missing_assignment_column_does_not_disable_reads(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropColumn(CrmSchema::TABLE_RESOURCES, 'admin_id');
        $guard = $this->guard($probe);

        $this->assertTrue($guard->isAvailable(CrmCapability::Resources));
        $this->assertFalse($guard->isAvailable(CrmCapability::ResourceAssignment));
        $this->assertSame(
            CrmErrorCode::SchemaMismatch,
            $this->capture(fn() => $guard->assert(CrmCapability::ResourceAssignment))->errorCode
        );
    }

    /** Custom fields com nome de tabela não confirmado falha SOZINHA. */
    public function test_missing_custom_fields_tables_do_not_disable_the_rest(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()
            ->dropTable(CrmSchema::TABLE_FIELDS)
            ->dropTable(CrmSchema::TABLE_FIELD_VALUES);
        $guard = $this->guard($probe);

        $this->assertFalse($guard->isAvailable(CrmCapability::CustomFields));

        foreach ([CrmCapability::Resources, CrmCapability::Followups, CrmCapability::Notes] as $capability) {
            $this->assertTrue($guard->isAvailable($capability), $capability->value);
        }
    }

    /** Coluna opcional presente é reportada; ausente não invalida a capacidade. */
    public function test_optional_active_column_is_detected_when_present(): void
    {
        $shape = $this->guard(FakeCrmSchemaProbe::healthy())->assert(CrmCapability::ResourceCatalogs);

        $this->assertTrue($shape->hasOptionalColumn(CrmSchema::TABLE_RESOURCE_TYPES, 'active'));
    }

    public function test_optional_active_column_absent_still_satisfies_the_capability(): void
    {
        $probe = new FakeCrmSchemaProbe(CrmSchemaFixture::withoutCatalogActiveColumn());

        $shape = $this->guard($probe)->assert(CrmCapability::ResourceCatalogs);

        $this->assertFalse($shape->hasOptionalColumn(CrmSchema::TABLE_RESOURCE_TYPES, 'active'));
    }

    /** Instalação vazia: nada disponível, e nunca uma exceção fora do enum. */
    public function test_empty_installation_closes_every_capability(): void
    {
        $guard = $this->guard(new FakeCrmSchemaProbe([]));

        foreach (CrmCapability::cases() as $capability) {
            $exception = $this->capture(fn() => $guard->assert($capability));
            $this->assertSame(CrmErrorCode::Unavailable, $exception->errorCode, $capability->value);
        }
    }

    /** A decisão é memorizada: o probe não é reexecutado a cada validação. */
    public function test_decision_is_memoised_including_the_failure(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropTable(CrmSchema::TABLE_NOTES);
        $guard = $this->guard($probe);

        $this->capture(fn() => $guard->assert(CrmCapability::Notes));
        $callsAfterFirst = count($probe->calls);
        $this->capture(fn() => $guard->assert(CrmCapability::Notes));

        $this->assertSame($callsAfterFirst, count($probe->calls), 'a falha também precisa ser memorizada');
    }

    /** Nenhuma mensagem carrega nome de tabela, coluna ou SQL. */
    public function test_messages_never_expose_physical_names(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropColumn(CrmSchema::TABLE_RESOURCES, 'email');

        $message = $this->capture(fn() => $this->guard($probe)->assert(CrmCapability::Resources))->getMessage();

        foreach (['crm_resources', 'email', 'SELECT', 'mod_mgcrm'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $message);
        }
    }

    /** `assertAll` para na primeira capacidade fechada. */
    public function test_assert_all_stops_at_the_first_closed_capability(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropTable(CrmSchema::TABLE_RESOURCES);

        $exception = $this->capture(fn() => $this->guard($probe)->assertAll(
            CrmCapability::Resources,
            CrmCapability::Notes,
        ));

        $this->assertSame(CrmErrorCode::Unavailable, $exception->errorCode);
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
