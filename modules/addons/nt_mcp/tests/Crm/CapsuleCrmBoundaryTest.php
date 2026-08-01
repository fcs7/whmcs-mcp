<?php

declare(strict_types=1);

namespace NtMcp\Tests\Crm;

use NtMcp\Crm\CapsuleAdminIdentityResolver;
use NtMcp\Crm\CapsuleQueryPort;
use NtMcp\Crm\CapsuleSchemaProbe;
use NtMcp\Crm\CrmCapability;
use NtMcp\Crm\CrmErrorCode;
use NtMcp\Crm\CrmException;
use NtMcp\Crm\CrmMutation;
use NtMcp\Crm\CrmSchema;
use NtMcp\Crm\CrmSchemaGuard;
use NtMcp\Crm\CrmSelect;
use NtMcp\Crm\CrmWriteGate;
use NtMcp\Tests\Support\ActivityLogSpy;
use NtMcp\Tests\Support\CrmSchemaFixture;
use NtMcp\Tests\Support\ErrorLogSpy;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeSchemaBuilder;
use PHPUnit\Framework\TestCase;

/**
 * As duas implementações que falam com o driver, exercitadas no caminho REAL.
 *
 * Um dublê do repositório provaria só o dublê. Aqui a cadeia
 * `table()->select()->where()->whereNull()->orderBy()->skip()->take()->get()` é
 * observada de fato, e o probe é observado NÃO tocando dados.
 */
class CapsuleCrmBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        FakeCapsule::reset();
        FakeSchemaBuilder::reset();
        \WHMCS\Config\Setting::reset();
        ActivityLogSpy::start();
        ErrorLogSpy::start();
        \NtMcp\Whmcs\Diagnostics::setFingerprintKey(hash('sha256', 'nt-mcp crm boundary key'));
    }

    protected function tearDown(): void
    {
        \NtMcp\Whmcs\Diagnostics::resetFingerprintKey();
        ErrorLogSpy::stop();
        ActivityLogSpy::stop();
        FakeSchemaBuilder::reset();
        FakeCapsule::reset();
        \WHMCS\Config\Setting::reset();
    }

    // ---------------------------------------------------------------
    // Probe: metadata e só metadata
    // ---------------------------------------------------------------

    public function test_probe_reads_metadata_and_never_touches_rows(): void
    {
        FakeSchemaBuilder::install(CrmSchemaFixture::completeInstallation());

        (new CrmSchemaGuard(new CapsuleSchemaProbe()))->assert(CrmCapability::Resources);

        $this->assertNotEmpty(FakeSchemaBuilder::$calls, 'o probe precisa consultar metadata');
        $this->assertSame([], FakeCapsule::$calls, 'nenhum acesso a linha durante o probe');
    }

    public function test_probe_caches_each_question_once(): void
    {
        FakeSchemaBuilder::install(CrmSchemaFixture::completeInstallation());
        $probe = new CapsuleSchemaProbe();

        $probe->hasTable(CrmSchema::TABLE_RESOURCES);
        $probe->hasTable(CrmSchema::TABLE_RESOURCES);
        $probe->hasColumn(CrmSchema::TABLE_RESOURCES, 'email');
        $probe->hasColumn(CrmSchema::TABLE_RESOURCES, 'email');

        $this->assertSame(
            ['hasTable(crm_resources)', 'hasColumn(crm_resources,email)'],
            FakeSchemaBuilder::$calls
        );
    }

    /** Driver fora do ar durante o probe: fail-closed, sem vazar a mensagem. */
    public function test_probe_failure_fails_closed_without_leaking(): void
    {
        FakeSchemaBuilder::$failure = new \RuntimeException('SQLSTATE[28000] password=hunter2SuperSecret');

        $guard = new CrmSchemaGuard(new CapsuleSchemaProbe());

        $this->assertFalse($guard->isAvailable(CrmCapability::Resources));
        $this->assertStringNotContainsString('hunter2SuperSecret', ErrorLogSpy::contents());
        $this->assertStringNotContainsString('hunter2SuperSecret', implode("\n", ActivityLogSpy::entries()));
        $this->assertTrue(ErrorLogSpy::hasLineContaining('context=crm_schema_probe'));
    }

    // ---------------------------------------------------------------
    // Port: projeção explícita, soft-delete e paginação
    // ---------------------------------------------------------------

    public function test_select_builds_the_expected_closed_chain(): void
    {
        FakeCapsule::withRows(CrmSchema::TABLE_RESOURCES, [
            ['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.test', 'deleted_at' => null],
            ['id' => 2, 'name' => 'Bruno', 'email' => 'bruno@example.test', 'deleted_at' => '2026-01-01 00:00:00'],
            ['id' => 3, 'name' => 'Célia', 'email' => 'celia@example.test', 'deleted_at' => null],
        ]);

        $rows = (new CapsuleQueryPort())->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_RESOURCES,
            columns: ['id', 'name'],
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            order: CrmSchema::resourceOrder(),
            limit: 10,
            offset: 0,
        ));

        $this->assertSame(
            [
                'table(crm_resources)',
                'select(id,name)',
                'whereNull(deleted_at)',
                'orderBy(id,desc)',
                'skip(0)',
                'take(10)',
                'get()',
            ],
            FakeCapsule::$calls
        );

        // Soft-deleted não aparece, e a projeção não traz `email`.
        $this->assertSame([['id' => 3, 'name' => 'Célia'], ['id' => 1, 'name' => 'Ana']], $rows);
    }

    public function test_select_honours_offset_and_limit(): void
    {
        FakeCapsule::withRows(CrmSchema::TABLE_RESOURCES, array_map(
            static fn(int $id): array => ['id' => $id, 'name' => "n{$id}", 'deleted_at' => null],
            range(1, 10)
        ));

        $rows = (new CapsuleQueryPort())->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_RESOURCES,
            columns: ['id'],
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            order: [['id', 'asc']],
            limit: 3,
            offset: 4,
        ));

        $this->assertSame([['id' => 5], ['id' => 6], ['id' => 7]], $rows);
    }

    /** Falha do driver vira `downstream` com correlação — nunca texto cru. */
    public function test_driver_failure_becomes_downstream_and_leaks_nowhere(): void
    {
        FakeCapsule::$failure = new \RuntimeException('SQLSTATE[42000] SELECT * FROM tblclients WHERE id=1');

        try {
            (new CapsuleQueryPort())->selectRows(new CrmSelect(CrmSchema::TABLE_RESOURCES, ['id']));
            $this->fail('esperava CrmException');
        } catch (CrmException $e) {
            $this->assertSame(CrmErrorCode::Downstream, $e->errorCode);
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
            $this->assertStringNotContainsString('tblclients', $e->getMessage());
            $this->assertNotNull($e->correlationId);
            $this->assertStringContainsString("[corr:{$e->correlationId}]", ErrorLogSpy::contents());
        }

        foreach ([ErrorLogSpy::contents(), implode("\n", ActivityLogSpy::entries())] as $sink) {
            $this->assertStringNotContainsString('SQLSTATE', $sink);
            $this->assertStringNotContainsString('tblclients', $sink);
        }
        $this->assertTrue(ErrorLogSpy::hasLineContaining('category=database_exception'));
    }

    // ---------------------------------------------------------------
    // Gate de escrita
    // ---------------------------------------------------------------

    /** Default DESLIGADO: sem opt-in explícito, nenhuma mutação acontece. */
    public function test_write_gate_is_closed_by_default_and_leaves_no_effect(): void
    {
        FakeCapsule::withRows(CrmSchema::TABLE_NOTES, []);

        try {
            (new CapsuleQueryPort())->insert(
                CrmMutation::insert(CrmSchema::TABLE_NOTES, ['resource_id' => 7, 'content' => 'x'])
            );
            $this->fail('esperava CrmException');
        } catch (CrmException $e) {
            $this->assertSame(CrmErrorCode::Validation, $e->errorCode);
        }

        $this->assertSame([], FakeCapsule::$mutations, 'gate fechado não pode produzir efeito');
        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP DB WRITE BLOCKED'));
    }

    /** O master switch readonly vence o opt-in de WRITE. */
    public function test_readonly_master_switch_overrides_the_write_opt_in(): void
    {
        \WHMCS\Config\Setting::setValue('nt_mcp_enable_write', '1');
        \WHMCS\Config\Setting::setValue('nt_mcp_readonly', '1');

        $this->assertFalse((new CrmWriteGate())->isWritable());
    }

    /** Valor não canônico em `nt_mcp_readonly` bloqueia e audita. */
    public function test_non_canonical_readonly_value_fails_closed(): void
    {
        \WHMCS\Config\Setting::setValue('nt_mcp_enable_write', '1');
        \WHMCS\Config\Setting::setValue('nt_mcp_readonly', 'yes');

        $this->assertFalse((new CrmWriteGate())->isWritable());
        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP CONFIG INVALID'));
    }

    /** Falha de leitura da configuração também bloqueia, sem vazar. */
    public function test_config_read_failure_fails_closed_without_leaking(): void
    {
        \WHMCS\Config\Setting::$throwOnRead = true;
        \WHMCS\Config\Setting::$readFailure = new \RuntimeException('dsn=mysql://root:hunter2SuperSecret@db');

        $this->assertFalse((new CrmWriteGate())->isWritable());

        $this->assertStringNotContainsString('hunter2SuperSecret', ErrorLogSpy::contents());
        $this->assertStringNotContainsString('hunter2SuperSecret', implode("\n", ActivityLogSpy::entries()));
        $this->assertTrue(ErrorLogSpy::hasLineContaining('category=config_read_failure'));
    }

    public function test_opted_in_write_reaches_the_driver_once(): void
    {
        \WHMCS\Config\Setting::setValue('nt_mcp_enable_write', '1');
        FakeCapsule::withRows(CrmSchema::TABLE_NOTES, []);

        $id = (new CapsuleQueryPort())->insert(
            CrmMutation::insert(CrmSchema::TABLE_NOTES, ['resource_id' => 7, 'content' => 'x'])
        );

        $this->assertSame(1, $id);
        $this->assertCount(1, FakeCapsule::$mutations);
        $this->assertSame('INSERT', FakeCapsule::$mutations[0]['verb']);
        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP DB INSERT'));
        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP DB OK'));
    }

    /** D7: o Activity Log recebe identificadores, nunca o texto livre. */
    public function test_activity_log_records_ids_not_free_text(): void
    {
        \WHMCS\Config\Setting::setValue('nt_mcp_enable_write', '1');
        FakeCapsule::withRows(CrmSchema::TABLE_NOTES, []);

        (new CapsuleQueryPort())->insert(CrmMutation::insert(CrmSchema::TABLE_NOTES, [
            'resource_id' => 7,
            'admin_id' => 3,
            'content' => 'hunter2SuperSecret 123.456.789-00',
        ]));

        $entries = implode("\n", ActivityLogSpy::entries());

        $this->assertStringContainsString('resource_id', $entries);
        $this->assertStringNotContainsString('hunter2SuperSecret', $entries);
        $this->assertStringNotContainsString('123.456.789-00', $entries);
    }

    // ---------------------------------------------------------------
    // Identidade administrativa pelo caminho real
    // ---------------------------------------------------------------

    private function resolver(): CapsuleAdminIdentityResolver
    {
        FakeSchemaBuilder::install(CrmSchemaFixture::completeInstallation());

        return new CapsuleAdminIdentityResolver(
            new CrmSchemaGuard(new CapsuleSchemaProbe()),
            new CapsuleQueryPort(),
        );
    }

    public function test_active_admin_resolves_to_its_id(): void
    {
        FakeCapsule::withRows(CrmSchema::TABLE_ADMINS, [
            ['id' => 9, 'username' => 'operador', 'disabled' => 0],
            ['id' => 10, 'username' => 'outro', 'disabled' => 0],
        ]);

        $this->assertSame(9, $this->resolver()->resolveActiveAdminId('operador'));
    }

    public function test_disabled_admin_is_refused(): void
    {
        FakeCapsule::withRows(CrmSchema::TABLE_ADMINS, [
            ['id' => 9, 'username' => 'operador', 'disabled' => 1],
        ]);

        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->captureAdmin('operador')->errorCode
        );
    }

    public function test_unknown_admin_is_refused(): void
    {
        FakeCapsule::withRows(CrmSchema::TABLE_ADMINS, []);

        $this->assertSame(CrmErrorCode::Downstream, $this->captureAdmin('fantasma')->errorCode);
    }

    /** Ambiguidade nunca escolhe o primeiro: recusa. */
    public function test_ambiguous_admin_is_refused(): void
    {
        FakeCapsule::withRows(CrmSchema::TABLE_ADMINS, [
            ['id' => 9, 'username' => 'operador', 'disabled' => 0],
            ['id' => 11, 'username' => 'operador', 'disabled' => 0],
        ]);

        $this->assertSame(CrmErrorCode::Downstream, $this->captureAdmin('operador')->errorCode);
    }

    public function test_blank_username_is_refused_before_any_query(): void
    {
        FakeCapsule::withRows(CrmSchema::TABLE_ADMINS, [
            ['id' => 9, 'username' => 'operador', 'disabled' => 0],
        ]);
        FakeCapsule::$calls = [];

        $this->assertSame(CrmErrorCode::Downstream, $this->captureAdmin('   ')->errorCode);
        $this->assertSame([], FakeCapsule::$calls, 'username vazio não chega ao banco');
    }

    /** Nenhuma recusa devolve o superadmin, um id seed ou o admin global. */
    public function test_refusal_never_falls_back_to_a_default_admin(): void
    {
        FakeCapsule::withRows(CrmSchema::TABLE_ADMINS, [
            ['id' => 1, 'username' => 'admin', 'disabled' => 0],
        ]);

        $exception = $this->captureAdmin('operador');

        $this->assertSame(CrmErrorCode::Downstream, $exception->errorCode);
        $this->assertStringNotContainsString('admin', $exception->getMessage());
    }

    /** O username nunca aparece nos sinks. */
    public function test_username_never_reaches_a_sink(): void
    {
        FakeCapsule::withRows(CrmSchema::TABLE_ADMINS, []);

        $this->captureAdmin('operador.confidencial');

        $this->assertStringNotContainsString('operador.confidencial', ErrorLogSpy::contents());
        $this->assertStringNotContainsString(
            'operador.confidencial',
            implode("\n", ActivityLogSpy::entries())
        );
    }

    private function captureAdmin(string $username): CrmException
    {
        try {
            $this->resolver()->resolveActiveAdminId($username);
        } catch (CrmException $e) {
            return $e;
        }

        $this->fail('esperava CrmException');
    }
}
