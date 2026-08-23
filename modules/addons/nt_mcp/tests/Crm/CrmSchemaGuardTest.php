<?php

declare(strict_types=1);

namespace NtMcp\Tests\Crm;

use NtMcp\Crm\CrmCapability;
use NtMcp\Crm\CrmErrorCode;
use NtMcp\Crm\CrmException;
use NtMcp\Crm\CrmSchema;
use NtMcp\Crm\CrmSchemaFact;
use NtMcp\Crm\CrmSchemaGuard;
use NtMcp\Crm\CrmSchemaProbe;
use NtMcp\Tests\Support\CrmSchemaFixture;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Todos os estados do schema guard: presente, ausente, coluna faltando e
 * metadata indisponível.
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
            'resource identity' => [CrmCapability::ResourceIdentity, CrmSchema::TABLE_RESOURCES],
            'resource core' => [CrmCapability::ResourceCore, CrmSchema::TABLE_RESOURCES],
            'followups' => [CrmCapability::Followups, CrmSchema::TABLE_FOLLOWUPS],
            'notes' => [CrmCapability::Notes, CrmSchema::TABLE_NOTES],
            'resource types' => [CrmCapability::ResourceTypes, CrmSchema::TABLE_RESOURCE_TYPES],
            'resource statuses' => [CrmCapability::ResourceStatuses, CrmSchema::TABLE_RESOURCE_STATUSES],
            'followup types' => [CrmCapability::FollowupTypes, CrmSchema::TABLE_FOLLOWUP_TYPES],
            'followup statuses' => [CrmCapability::FollowupStatuses, CrmSchema::TABLE_FOLLOWUP_STATUSES],
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
            'identidade sem soft-delete' => [
                CrmCapability::ResourceIdentity, CrmSchema::TABLE_RESOURCES, 'deleted_at',
            ],
            'core sem short_description' => [
                CrmCapability::ResourceCore, CrmSchema::TABLE_RESOURCES, 'short_description',
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
                CrmCapability::ResourceTypes, CrmSchema::TABLE_RESOURCE_TYPES, 'name',
            ],
            'catálogo sem active' => [
                CrmCapability::ResourceTypes, CrmSchema::TABLE_RESOURCE_TYPES, 'active',
            ],
            'admins sem disabled' => [
                CrmCapability::AdminIdentity, CrmSchema::TABLE_ADMINS, 'disabled',
            ],
        ];
    }

    /**
     * Atividade não comprovada é MISMATCH, nunca degradação para soft-delete.
     * É o finding fail-open que a revisão fria reproduziu.
     */
    #[DataProvider('catalogCapabilityProvider')]
    public function test_catalog_without_activity_column_is_schema_mismatch(CrmCapability $capability): void
    {
        $probe = new FakeCrmSchemaProbe(CrmSchemaFixture::withoutCatalogActiveColumn());

        $this->assertSame(
            CrmErrorCode::SchemaMismatch,
            $this->capture(fn() => $this->guard($probe)->assert($capability))->errorCode
        );
    }

    /** @return array<string, array{0:CrmCapability}> */
    public static function catalogCapabilityProvider(): array
    {
        return [
            'resource types' => [CrmCapability::ResourceTypes],
            'resource statuses' => [CrmCapability::ResourceStatuses],
            'followup types' => [CrmCapability::FollowupTypes],
            'followup statuses' => [CrmCapability::FollowupStatuses],
        ];
    }

    /**
     * O recorte mínimo é o que impede drift localizado de derrubar operação
     * alheia: sem `short_description`, a identidade do recurso continua íntegra.
     */
    public function test_core_drift_does_not_break_resource_identity(): void
    {
        $guard = $this->guard(
            FakeCrmSchemaProbe::healthy()->dropColumn(CrmSchema::TABLE_RESOURCES, 'short_description')
        );

        $guard->assert(CrmCapability::ResourceIdentity);

        $this->assertSame(
            CrmErrorCode::SchemaMismatch,
            $this->capture(fn() => $guard->assert(CrmCapability::ResourceCore))->errorCode
        );
    }

    /** Cada catálogo é independente dos outros três. */
    public function test_one_missing_catalog_does_not_close_the_others(): void
    {
        $guard = $this->guard(
            FakeCrmSchemaProbe::healthy()->dropTable(CrmSchema::TABLE_RESOURCE_STATUSES)
        );

        $guard->assert(CrmCapability::ResourceTypes);
        $guard->assert(CrmCapability::FollowupTypes);
        $guard->assert(CrmCapability::FollowupStatuses);

        $this->assertSame(
            CrmErrorCode::Unavailable,
            $this->capture(fn() => $guard->assert(CrmCapability::ResourceStatuses))->errorCode
        );
    }

    /** Sem `crm_resources.admin_id` as leituras continuam disponíveis. */
    public function test_missing_assignment_column_does_not_disable_reads(): void
    {
        $guard = $this->guard(
            FakeCrmSchemaProbe::healthy()->dropColumn(CrmSchema::TABLE_RESOURCES, 'admin_id')
        );

        $guard->assert(CrmCapability::ResourceIdentity);
        $guard->assert(CrmCapability::ResourceCore);

        $this->assertSame(
            CrmErrorCode::SchemaMismatch,
            $this->capture(fn() => $guard->assert(CrmCapability::ResourceAssignment))->errorCode
        );
    }

    /** Custom fields com nome de tabela não confirmado falha SOZINHA. */
    public function test_missing_custom_fields_tables_do_not_disable_the_rest(): void
    {
        $guard = $this->guard(
            FakeCrmSchemaProbe::healthy()
                ->dropTable(CrmSchema::TABLE_FIELDS)
                ->dropTable(CrmSchema::TABLE_FIELD_VALUES)
        );

        $this->assertSame(
            CrmErrorCode::Unavailable,
            $this->capture(fn() => $guard->assert(CrmCapability::CustomFields))->errorCode
        );

        foreach ([CrmCapability::ResourceIdentity, CrmCapability::Followups, CrmCapability::Notes] as $ok) {
            $guard->assert($ok);
        }
    }

    // ---------------------------------------------------------------
    // Terceiro estado: metadata indisponível
    // ---------------------------------------------------------------

    /** Erro de metadata NÃO é ausência: é `downstream`, com correlação. */
    public function test_metadata_error_is_downstream_not_absence(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->failWith('abcd1234');

        $exception = $this->capture(fn() => $this->guard($probe)->assert(CrmCapability::ResourceIdentity));

        $this->assertSame(CrmErrorCode::Downstream, $exception->errorCode);
        $this->assertSame('abcd1234', $exception->correlationId);
        $this->assertStringNotContainsString('not installed', $exception->getMessage());
    }

    /** Falha em `hasColumn` também é `downstream`, não mismatch. */
    public function test_metadata_error_after_a_present_table_is_downstream(): void
    {
        // A tabela responde presente; só a pergunta de coluna falha.
        $failing = new class implements CrmSchemaProbe {
            public function hasTable(string $table): CrmSchemaFact
            {
                return CrmSchemaFact::present();
            }

            public function hasColumn(string $table, string $column): CrmSchemaFact
            {
                return CrmSchemaFact::unknown('feed0001');
            }
        };

        $exception = $this->capture(
            fn() => (new CrmSchemaGuard($failing))->assert(CrmCapability::ResourceIdentity)
        );

        $this->assertSame(CrmErrorCode::Downstream, $exception->errorCode);
        $this->assertSame('feed0001', $exception->correlationId);
    }

    /** Falha transitória não é memorizada: a próxima pergunta reprova o schema. */
    public function test_metadata_error_is_not_memoised(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->failWith();
        $guard = $this->guard($probe);

        $this->capture(fn() => $guard->assert(CrmCapability::ResourceIdentity));
        $callsAfterFailure = count($probe->calls);

        $this->capture(fn() => $guard->assert(CrmCapability::ResourceIdentity));

        $this->assertGreaterThan(
            $callsAfterFailure,
            count($probe->calls),
            'erro de metadata precisa ser reavaliado, não congelado'
        );
    }

    /** Já uma CONCLUSÃO é memorizada, inclusive a negativa. */
    public function test_conclusive_decision_is_memoised_including_the_failure(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropTable(CrmSchema::TABLE_NOTES);
        $guard = $this->guard($probe);

        $this->capture(fn() => $guard->assert(CrmCapability::Notes));
        $callsAfterFirst = count($probe->calls);
        $this->capture(fn() => $guard->assert(CrmCapability::Notes));

        $this->assertSame($callsAfterFirst, count($probe->calls));
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

    /** Nenhuma mensagem carrega nome de tabela, coluna ou SQL. */
    public function test_messages_never_expose_physical_names(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropColumn(CrmSchema::TABLE_RESOURCES, 'email');

        $message = $this->capture(fn() => $this->guard($probe)->assert(CrmCapability::ResourceCore))->getMessage();

        foreach (['crm_resources', 'email', 'SELECT', 'mod_mgcrm'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $message);
        }
    }

    /** `assertAll` para na primeira capacidade fechada. */
    public function test_assert_all_stops_at_the_first_closed_capability(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropTable(CrmSchema::TABLE_RESOURCES);

        $exception = $this->capture(fn() => $this->guard($probe)->assertAll(
            CrmCapability::ResourceIdentity,
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
