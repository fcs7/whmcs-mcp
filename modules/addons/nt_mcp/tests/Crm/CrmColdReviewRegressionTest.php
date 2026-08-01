<?php

declare(strict_types=1);

namespace NtMcp\Tests\Crm;

use NtMcp\Crm\CapsuleAdminIdentityResolver;
use NtMcp\Crm\CapsuleQueryPort;
use NtMcp\Crm\CapsuleSchemaProbe;
use NtMcp\Crm\CrmCapability;
use NtMcp\Crm\CrmCatalog;
use NtMcp\Crm\CrmErrorCode;
use NtMcp\Crm\CrmException;
use NtMcp\Crm\CrmInstant;
use NtMcp\Crm\CrmSchema;
use NtMcp\Crm\CrmSchemaGuard;
use NtMcp\Crm\CrmWriteGate;
use NtMcp\Crm\MgCrmRepository;
use NtMcp\Tests\Support\ActivityLogSpy;
use NtMcp\Tests\Support\CrmSchemaFixture;
use NtMcp\Tests\Support\ErrorLogSpy;
use NtMcp\Tests\Support\FakeAdminIdentityResolver;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeCrmQueryPort;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use NtMcp\Tests\Support\FakeSchemaBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Um teste por finding da revisão fria de CRM-1, reproduzindo o cenário EXATO
 * que o revisor descreveu e afirmando o comportamento novo.
 *
 * O objetivo aqui não é cobertura — as suítes vizinhas cobrem o contrato
 * inteiro — e sim rastreabilidade: se um destes voltar a passar pelo caminho
 * antigo, o finding ressuscitou.
 */
class CrmColdReviewRegressionTest extends TestCase
{
    private FakeCrmQueryPort $port;

    protected function setUp(): void
    {
        $this->port = new FakeCrmQueryPort();
        FakeCapsule::reset();
        FakeSchemaBuilder::reset();
        \WHMCS\Config\Setting::reset();
        ActivityLogSpy::start();
        ErrorLogSpy::start();
    }

    protected function tearDown(): void
    {
        ErrorLogSpy::stop();
        ActivityLogSpy::stop();
        FakeSchemaBuilder::reset();
        FakeCapsule::reset();
        \WHMCS\Config\Setting::reset();
    }

    private function repository(
        ?FakeCrmSchemaProbe $probe = null,
        ?FakeAdminIdentityResolver $admins = null,
    ): MgCrmRepository {
        return new MgCrmRepository(
            new CrmSchemaGuard($probe ?? FakeCrmSchemaProbe::healthy()),
            $this->port,
            $admins ?? FakeAdminIdentityResolver::resolvingTo(7),
        );
    }

    // ---------------------------------------------------------------
    // P1 #1 — guard agregado bloqueava operação com schema íntegro
    // ---------------------------------------------------------------

    /**
     * Reprodução do review: `crm_resources.short_description` ausente, `id` e
     * `deleted_at` presentes, recurso 42 saudável. Antes: `crm_schema_mismatch`
     * e zero selects. Agora: o recurso é encontrado.
     */
    public function test_finding1_resource_identity_survives_core_column_drift(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropColumn(CrmSchema::TABLE_RESOURCES, 'short_description');
        $this->port->seed(CrmSchema::TABLE_RESOURCES, [
            ['id' => 42, 'deleted_at' => null],
        ]);

        $this->assertSame(42, $this->repository($probe)->requireResource(42));
        $this->assertCount(1, $this->port->selects, 'a query precisa acontecer');
    }

    /**
     * Reprodução do review: apenas `crm_resources_statuses` ausente; um tipo
     * íntegro era recusado como `crm_unavailable`. Agora passa.
     */
    public function test_finding1_type_catalog_survives_a_missing_status_catalog(): void
    {
        $probe = FakeCrmSchemaProbe::healthy()->dropTable(CrmSchema::TABLE_RESOURCE_STATUSES);
        $this->port->seed(CrmCatalog::ResourceType->table(), [
            ['id' => 3, 'name' => 'Lead', 'active' => 1, 'deleted_at' => null],
        ]);

        $this->assertSame(3, $this->repository($probe)->requireCatalogEntry(CrmCatalog::ResourceType, 3));
    }

    // ---------------------------------------------------------------
    // P1 #2 — ausência de `active` era tratada como prova de atividade
    // ---------------------------------------------------------------

    /**
     * Reprodução do review: fixture sem `active`, linha
     * `crm_resources_types(id=3, deleted_at=NULL)` era ACEITA e a query saía só
     * com `id=3`. Agora falha fechado antes da query.
     */
    public function test_finding2_catalog_without_activity_proof_is_schema_mismatch(): void
    {
        $probe = new FakeCrmSchemaProbe(CrmSchemaFixture::withoutCatalogActiveColumn());
        $this->port->seed(CrmCatalog::ResourceType->table(), [
            ['id' => 3, 'name' => 'Lead', 'deleted_at' => null],
        ]);

        try {
            $this->repository($probe)->requireCatalogEntry(CrmCatalog::ResourceType, 3);
            $this->fail('esperava CrmException');
        } catch (CrmException $e) {
            $this->assertSame(CrmErrorCode::SchemaMismatch, $e->errorCode);
        }

        $this->assertSame([], $this->port->selects, 'nada pode ser consultado sem prova de atividade');
    }

    // ---------------------------------------------------------------
    // P1 #3 — autoria OAuth podia ser forjada fora do repositório
    // ---------------------------------------------------------------

    /**
     * Reprodução do review: construir um contexto de escrita com `admin_id`
     * arbitrário, montar a mutação e chamar o port de produção com o gate
     * aberto. Hoje nada disso existe — as classes sumiram e o port não tem
     * método de escrita.
     */
    public function test_finding3_no_forgeable_write_path_exists(): void
    {
        $this->assertFalse(class_exists('NtMcp\Crm\CrmWriteContext'));
        $this->assertFalse(class_exists('NtMcp\Crm\CrmMutation'));
        $this->assertFalse(method_exists(CapsuleQueryPort::class, 'insert'));
        $this->assertFalse(method_exists(CapsuleQueryPort::class, 'update'));
        $this->assertFalse(method_exists(MgCrmRepository::class, 'prepareWrite'));
    }

    /** Com o gate ABERTO, nenhuma mutação alcança o driver — não há caminho. */
    public function test_finding3_open_gate_still_produces_no_driver_mutation(): void
    {
        \WHMCS\Config\Setting::setValue('nt_mcp_enable_write', '1');
        FakeSchemaBuilder::install(CrmSchemaFixture::completeInstallation());
        FakeCapsule::withRows(CrmSchema::TABLE_RESOURCES, [
            ['id' => 42, 'deleted_at' => null],
        ]);

        (new CrmWriteGate())->assertWritable();

        $repository = new MgCrmRepository(
            new CrmSchemaGuard(new CapsuleSchemaProbe()),
            new CapsuleQueryPort(),
            FakeAdminIdentityResolver::resolvingTo(999),
        );
        $repository->requireResource(42);
        $repository->resolveAuthorAdminId('operador');

        $this->assertSame([], FakeCapsule::$mutations);
    }

    /** O gate composto não aceita mais lista vazia de capacidades. */
    public function test_finding3_capability_assertion_cannot_be_empty(): void
    {
        $this->expectException(\LogicException::class);
        $this->repository()->assertCapabilities();
    }

    // ---------------------------------------------------------------
    // P2 #4 — erro de metadata virava ausência/mismatch
    // ---------------------------------------------------------------

    /**
     * Reprodução do review: `FakeSchemaBuilder::$failure` com segredo dentro.
     * Antes: `crm_unavailable` dizendo que as tabelas não estão instaladas.
     */
    public function test_finding4_metadata_error_is_downstream_not_absence(): void
    {
        FakeSchemaBuilder::$failure = new \RuntimeException('SQLSTATE secret');

        try {
            (new CrmSchemaGuard(new CapsuleSchemaProbe()))->assert(CrmCapability::ResourceCore);
            $this->fail('esperava CrmException');
        } catch (CrmException $e) {
            $this->assertSame(CrmErrorCode::Downstream, $e->errorCode);
            $this->assertStringNotContainsString('not installed', $e->getMessage());
            $this->assertStringNotContainsString('SQLSTATE secret', $e->getMessage());
        }
    }

    /** Falha em `hasColumn` com a tabela já provada também é `downstream`. */
    public function test_finding4_column_probe_error_is_not_schema_mismatch(): void
    {
        FakeSchemaBuilder::install(CrmSchemaFixture::completeInstallation());
        $probe = new CapsuleSchemaProbe();

        $this->assertTrue($probe->hasTable(CrmSchema::TABLE_RESOURCES)->isPresent());

        FakeSchemaBuilder::$failure = new \RuntimeException('driver down mid-request');

        $this->assertTrue($probe->hasColumn(CrmSchema::TABLE_RESOURCES, 'email')->isUnknown());
    }

    // ---------------------------------------------------------------
    // P2 #5 — taxonomia: D12 `denied`
    // ---------------------------------------------------------------

    /** Gate WRITE fechado era `validation`; agora é `denied`. */
    public function test_finding5_closed_write_gate_is_denied(): void
    {
        try {
            (new CrmWriteGate(false))->assertWritable();
            $this->fail('esperava CrmException');
        } catch (CrmException $e) {
            $this->assertSame(CrmErrorCode::Denied, $e->errorCode);
        }
    }

    /** Username OAuth vazio era `downstream`; agora é `denied`. */
    public function test_finding5_empty_oauth_username_is_denied(): void
    {
        FakeSchemaBuilder::install(CrmSchemaFixture::completeInstallation());
        FakeCapsule::withRows(CrmSchema::TABLE_ADMINS, []);

        $resolver = new CapsuleAdminIdentityResolver(
            new CrmSchemaGuard(new CapsuleSchemaProbe()),
            new CapsuleQueryPort(),
        );

        try {
            $resolver->resolveActiveAdminId('');
            $this->fail('esperava CrmException');
        } catch (CrmException $e) {
            $this->assertSame(CrmErrorCode::Denied, $e->errorCode);
        }
    }

    /** E a falha REAL do driver continua separada, como `downstream`. */
    public function test_finding5_real_driver_failure_stays_downstream(): void
    {
        FakeSchemaBuilder::install(CrmSchemaFixture::completeInstallation());
        FakeCapsule::$failure = new \RuntimeException('connection refused');

        $resolver = new CapsuleAdminIdentityResolver(
            new CrmSchemaGuard(new CapsuleSchemaProbe()),
            new CapsuleQueryPort(),
        );

        try {
            $resolver->resolveActiveAdminId('operador');
            $this->fail('esperava CrmException');
        } catch (CrmException $e) {
            $this->assertSame(CrmErrorCode::Downstream, $e->errorCode);
        }
    }

    // ---------------------------------------------------------------
    // P2 #6 — cache positivo autorizava admin revogado
    // ---------------------------------------------------------------

    /**
     * Reprodução do review: mesma instância, `operador` ativo resolve para 9;
     * depois `disabled=1`; a segunda chamada devolvia 9 e executava zero
     * queries.
     */
    public function test_finding6_revoked_admin_is_refused_by_the_same_instance(): void
    {
        FakeSchemaBuilder::install(CrmSchemaFixture::completeInstallation());
        FakeCapsule::withRows(CrmSchema::TABLE_ADMINS, [
            ['id' => 9, 'username' => 'operador', 'disabled' => 0],
        ]);

        $resolver = new CapsuleAdminIdentityResolver(
            new CrmSchemaGuard(new CapsuleSchemaProbe()),
            new CapsuleQueryPort(),
        );

        $this->assertSame(9, $resolver->resolveActiveAdminId('operador'));

        FakeCapsule::withRows(CrmSchema::TABLE_ADMINS, [
            ['id' => 9, 'username' => 'operador', 'disabled' => 1],
        ]);
        FakeCapsule::$calls = [];

        try {
            $resolver->resolveActiveAdminId('operador');
            $this->fail('esperava recusa após revogação');
        } catch (CrmException $e) {
            $this->assertSame(CrmErrorCode::Denied, $e->errorCode);
        }

        $this->assertNotSame([], FakeCapsule::$calls, 'precisa reconsultar o banco');
    }

    // ---------------------------------------------------------------
    // P2 #7 — instante fora do intervalo do MySQL
    // ---------------------------------------------------------------

    /** Os três valores exatos que o review reproduziu. */
    public function test_finding7_out_of_range_instants_are_rejected(): void
    {
        foreach ([
            '9999-12-31T23:59:59-14:00',
            '0001-01-01T00:00:00+14:00',
            '0999-06-15T12:00:00Z',
        ] as $input) {
            $this->assertNull(CrmInstant::tryToUtcMySql($input), $input);
        }
    }

    /** As bordas exatas do `TIMESTAMP` continuam válidas. */
    public function test_finding7_range_borders_remain_valid(): void
    {
        $this->assertSame('1970-01-01 00:00:01', CrmInstant::toUtcMySql('1970-01-01T00:00:01Z', 'date'));
        $this->assertSame('2038-01-19 03:14:07', CrmInstant::toUtcMySql('2038-01-19T03:14:07Z', 'date'));
    }
}
