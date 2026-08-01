<?php

declare(strict_types=1);

namespace NtMcp\Tests\Crm;

use NtMcp\Crm\CrmMutation;
use NtMcp\Crm\CrmSchema;
use NtMcp\Crm\CrmSelect;
use NtMcp\Crm\MgCrmRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Varredura MECÂNICA do código novo, mais as recusas estruturais.
 *
 * A afirmação "não existe API genérica de tabela/coluna e não existe
 * `SELECT *`" só vale se for verificável sem ler o diff — é o que este arquivo
 * faz. Um `select('*')` acrescentado por engano, um nome `mod_mgcrm_*`
 * ressuscitado ou um parâmetro `$table` num método público da fronteira
 * derrubam a suíte.
 */
class CrmClosedSurfaceTest extends TestCase
{
    /** @return array<int, string> */
    private static function crmSources(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/src/Crm/*.php');

        return $files === false ? [] : $files;
    }

    public function test_the_crm_namespace_has_sources_to_scan(): void
    {
        $this->assertGreaterThanOrEqual(10, count(self::crmSources()));
    }

    /** Nenhuma projeção coringa, em nenhuma forma. */
    public function test_no_wildcard_projection_anywhere(): void
    {
        foreach (self::crmSources() as $file) {
            $source = (string) file_get_contents($file);

            foreach (["select('*')", 'select("*")', "['*']", '"*"'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    basename($file) . ' não pode projetar coringa'
                );
            }
        }
    }

    /** As tabelas fictícias não voltam, nem como fallback nem como comentário. */
    public function test_no_legacy_mgcrm_table_names(): void
    {
        foreach (self::crmSources() as $file) {
            $this->assertStringNotContainsString(
                'mod_mgcrm_',
                (string) file_get_contents($file),
                basename($file)
            );
        }
    }

    /** Só as duas classes de infraestrutura falam com o Capsule. */
    public function test_only_the_capsule_adapters_touch_the_driver(): void
    {
        $allowed = ['CapsuleQueryPort.php', 'CapsuleSchemaProbe.php'];

        foreach (self::crmSources() as $file) {
            if (in_array(basename($file), $allowed, true)) {
                continue;
            }

            $this->assertStringNotContainsString(
                'Capsule::',
                (string) file_get_contents($file),
                basename($file) . ' não pode falar com o driver'
            );
        }
    }

    /**
     * D6: a mensagem do driver só pode ser lida onde vira fingerprint. Em
     * nenhum outro ponto do domínio.
     */
    public function test_driver_messages_are_only_read_for_fingerprinting(): void
    {
        foreach (self::crmSources() as $file) {
            $source = (string) file_get_contents($file);

            // `$this->getMessage()` é a NOSSA mensagem; o que não pode circular
            // é a da causa (`$e->getMessage()`).
            if (!str_contains($source, '$e->getMessage()')) {
                continue;
            }

            $this->assertSame(
                'CapsuleQueryPort.php',
                basename($file),
                'só a fronteira de execução pode tocar a mensagem da causa'
            );
            $this->assertStringContainsString('Diagnostics::fingerprint($e->getMessage())', $source);
        }
    }

    /**
     * A fronteira pública não pode ter um parâmetro que aceite estrutura de
     * banco. Se um método novo introduzir `$table`, `$column` ou `$orderBy`,
     * este teste falha antes da revisão.
     */
    public function test_the_repository_never_accepts_database_structure(): void
    {
        // `field` fica de fora de propósito: `normalizeInstant($value, $field)`
        // usa o nome apenas como rótulo da mensagem de validação, e ele é
        // sempre um literal nosso. `fields` (plural) continua vedado — seria
        // uma lista de colunas.
        $forbidden = ['table', 'tables', 'column', 'columns', 'order', 'orderby',
            'sort', 'operator', 'where', 'sql', 'query', 'fields'];

        $reflection = new \ReflectionClass(MgCrmRepository::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $this->assertNotContains(
                    strtolower($parameter->getName()),
                    $forbidden,
                    "{$method->getName()}() expõe estrutura de banco"
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // Recusas estruturais de CrmSelect
    // ---------------------------------------------------------------

    #[DataProvider('rejectedSelectProvider')]
    public function test_select_refuses_anything_outside_the_contract(callable $build): void
    {
        $this->expectException(\LogicException::class);
        $build();
    }

    /** @return array<string, array{0:callable}> */
    public static function rejectedSelectProvider(): array
    {
        return [
            'tabela do WHMCS' => [static fn() => new CrmSelect('tblclients', ['id'])],
            'tabela fictícia' => [static fn() => new CrmSelect('mod_mgcrm_contacts', ['id'])],
            'projeção vazia' => [static fn() => new CrmSelect(CrmSchema::TABLE_RESOURCES, [])],
            'projeção coringa' => [static fn() => new CrmSelect(CrmSchema::TABLE_RESOURCES, ['*'])],
            'coluna inventada' => [
                static fn() => new CrmSelect(CrmSchema::TABLE_RESOURCES, ['id', 'password']),
            ],
            'condição inventada' => [
                static fn() => new CrmSelect(CrmSchema::TABLE_RESOURCES, ['id'], ['secret' => 1]),
            ],
            'condição não escalar' => [
                static fn() => new CrmSelect(CrmSchema::TABLE_RESOURCES, ['id'], ['id' => [1, 2]]),
            ],
            'null em coluna inventada' => [
                static fn() => new CrmSelect(CrmSchema::TABLE_RESOURCES, ['id'], [], ['secret']),
            ],
            'ordenação inventada' => [
                static fn() => new CrmSelect(CrmSchema::TABLE_RESOURCES, ['id'], [], [], [['secret', 'asc']]),
            ],
            'direção injetada' => [
                static fn() => new CrmSelect(
                    CrmSchema::TABLE_RESOURCES,
                    ['id'],
                    [],
                    [],
                    [['id', 'asc, (SELECT 1)']]
                ),
            ],
            'limite acima do teto' => [
                static fn() => new CrmSelect(CrmSchema::TABLE_RESOURCES, ['id'], limit: CrmSchema::MAX_LIMIT + 1),
            ],
            'limite zero' => [
                static fn() => new CrmSelect(CrmSchema::TABLE_RESOURCES, ['id'], limit: 0),
            ],
            'offset negativo' => [
                static fn() => new CrmSelect(CrmSchema::TABLE_RESOURCES, ['id'], offset: -1),
            ],
        ];
    }

    public function test_select_accepts_the_declared_contract(): void
    {
        $select = new CrmSelect(
            table: CrmSchema::TABLE_FOLLOWUPS,
            columns: CrmSchema::followupProjection(),
            conditions: [CrmSchema::COLUMN_RESOURCE_ID => 7],
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            order: CrmSchema::followupOrder(),
            limit: CrmSchema::DEFAULT_LIMIT,
        );

        $this->assertNotContains('*', $select->columns);
        $this->assertSame(CrmSchema::TABLE_FOLLOWUPS, $select->table);
    }

    /** Toda ordenação declarada termina em `id`, senão a paginação não é estável. */
    #[DataProvider('orderProvider')]
    public function test_every_order_ends_with_the_unique_key(array $order): void
    {
        $this->assertSame('id', $order[array_key_last($order)][0]);
    }

    /** @return array<string, array{0:array<int, array{0:string,1:string}>}> */
    public static function orderProvider(): array
    {
        return [
            'resources' => [CrmSchema::resourceOrder()],
            'followups' => [CrmSchema::followupOrder()],
            'notes' => [CrmSchema::noteOrder()],
            'catalogs' => [CrmSchema::catalogOrder()],
        ];
    }

    // ---------------------------------------------------------------
    // Recusas estruturais de CrmMutation
    // ---------------------------------------------------------------

    #[DataProvider('rejectedMutationProvider')]
    public function test_mutation_refuses_anything_outside_the_contract(callable $build): void
    {
        $this->expectException(\LogicException::class);
        $build();
    }

    /** @return array<string, array{0:callable}> */
    public static function rejectedMutationProvider(): array
    {
        return [
            'tabela de catálogo' => [
                static fn() => CrmMutation::insert(CrmSchema::TABLE_RESOURCE_TYPES, ['name' => 'x']),
            ],
            'tabela do WHMCS' => [
                static fn() => CrmMutation::insert(CrmSchema::TABLE_ADMINS, ['username' => 'x']),
            ],
            'identidade gravável' => [
                static fn() => CrmMutation::insert(CrmSchema::TABLE_RESOURCES, ['id' => 1, 'name' => 'x']),
            ],
            'soft-delete gravável' => [
                static fn() => CrmMutation::insert(CrmSchema::TABLE_RESOURCES, ['deleted_at' => null]),
            ],
            'insert vazio' => [
                static fn() => CrmMutation::insert(CrmSchema::TABLE_RESOURCES, []),
            ],
            'update sem where' => [
                static fn() => CrmMutation::update(CrmSchema::TABLE_RESOURCES, ['name' => 'x'], []),
            ],
            'update sem valores' => [
                static fn() => CrmMutation::update(CrmSchema::TABLE_RESOURCES, [], ['id' => 1]),
            ],
            'where fora do contrato' => [
                static fn() => CrmMutation::update(CrmSchema::TABLE_RESOURCES, ['name' => 'x'], ['email' => 'a']),
            ],
            'valor não escalar' => [
                static fn() => CrmMutation::insert(CrmSchema::TABLE_NOTES, ['content' => ['a']]),
            ],
        ];
    }

    public function test_mutation_accepts_the_declared_contract(): void
    {
        $insert = CrmMutation::insert(CrmSchema::TABLE_NOTES, [
            'resource_id' => 7,
            'admin_id' => 3,
            'content' => 'texto livre',
            'created_at' => '2026-08-10 10:00:00',
        ]);

        $this->assertSame('INSERT', $insert->verb);
        $this->assertSame(['resource_id' => 7, 'admin_id' => 3], $insert->auditIds());

        $update = CrmMutation::update(CrmSchema::TABLE_RESOURCES, ['name' => 'Ana'], ['id' => 7]);

        $this->assertSame('UPDATE', $update->verb);
        $this->assertSame([CrmSchema::COLUMN_DELETED_AT], $update->nullConditions);
    }
}
