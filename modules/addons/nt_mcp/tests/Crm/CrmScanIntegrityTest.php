<?php

declare(strict_types=1);

namespace NtMcp\Tests\Crm;

use NtMcp\Crm\CrmCount;
use NtMcp\Crm\CrmErrorCode;
use NtMcp\Crm\CrmException;
use NtMcp\Crm\CrmQueryPort;
use NtMcp\Crm\CrmSchema;
use NtMcp\Crm\CrmSchemaGuard;
use NtMcp\Crm\CrmSelect;
use NtMcp\Crm\MgCrmRepository;
use NtMcp\Tests\Support\FakeAdminIdentityResolver;
use NtMcp\Tests\Support\FakeCrmQueryPort;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A varredura completa contra um port ADVERSARIAL.
 *
 * A revisão fria do CRM-2.1 derrubou o desenho por offset com um caso simples:
 * o port informou `count=101`, devolveu 1..100 na primeira página e repetiu o
 * ID 1 na segunda. O loop só conferia `count($rows) === $total`, então o
 * catálogo saiu "completo" com 100 IDs únicos e sem o 101.
 *
 * Aqui o port mente de propósito, de várias formas, e cada mentira precisa
 * virar `downstream` — nunca uma coleção que aparenta completude.
 */
class CrmScanIntegrityTest extends TestCase
{
    private function repositoryWith(CrmQueryPort $port): MgCrmRepository
    {
        return new MgCrmRepository(
            new CrmSchemaGuard(FakeCrmSchemaProbe::healthy()),
            $port,
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

    /** O cenário exato reproduzido pela revisão: duplicata + omissão. */
    public function test_duplicate_plus_omission_is_no_longer_accepted_as_complete(): void
    {
        $port = new AdversarialScanPort(total: 101, pages: [
            self::catalogRows(range(1, 100)),
            self::catalogRows([1]),
        ]);

        $failure = $this->capture(fn() => $this->repositoryWith($port)->getKanban(null, 25));

        $this->assertSame(CrmErrorCode::Downstream, $failure->errorCode);
    }

    /** Página que retrocede no id não pode passar por progresso. */
    public function test_non_monotonic_page_fails_closed(): void
    {
        $port = new AdversarialScanPort(total: 150, pages: [
            self::catalogRows(range(1, 100)),
            self::catalogRows(range(50, 99)),
        ]);

        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->capture(fn() => $this->repositoryWith($port)->getKanban(null, 25))->errorCode
        );
    }

    /** Linha sem identidade utilizável derruba a varredura. */
    #[DataProvider('malformedIdProvider')]
    public function test_row_without_usable_identity_fails_closed(mixed $id): void
    {
        $port = new AdversarialScanPort(total: 1, pages: [
            [['id' => $id, 'name' => 'X']],
        ]);

        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->capture(fn() => $this->repositoryWith($port)->getKanban(null, 25))->errorCode
        );
    }

    /** @return array<string, array{0:mixed}> */
    public static function malformedIdProvider(): array
    {
        return [
            'id nulo' => [null],
            'id zero' => [0],
            'id negativo' => [-3],
            'id não numérico' => ['abc'],
        ];
    }

    /** Menos linhas do que o total contado é varredura incompleta. */
    public function test_short_scan_against_the_counted_total_fails_closed(): void
    {
        $port = new AdversarialScanPort(total: 120, pages: [
            self::catalogRows(range(1, 50)),
        ]);

        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->capture(fn() => $this->repositoryWith($port)->getKanban(null, 25))->errorCode
        );
    }

    /** Total que muda entre a contagem e a reconferência é concorrência. */
    public function test_concurrent_total_divergence_fails_closed(): void
    {
        $port = new AdversarialScanPort(
            total: 3,
            pages: [self::catalogRows([1, 2, 3])],
            recountTotal: 4,
        );

        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->capture(fn() => $this->repositoryWith($port)->getKanban(null, 25))->errorCode
        );
    }

    /** Mais linhas do que o contado também é divergência, não bônus. */
    public function test_scan_exceeding_the_total_fails_closed(): void
    {
        $port = new AdversarialScanPort(total: 2, pages: [
            self::catalogRows([1, 2, 3]),
        ]);

        $this->assertSame(
            CrmErrorCode::Downstream,
            $this->capture(fn() => $this->repositoryWith($port)->getKanban(null, 25))->errorCode
        );
    }

    /** Nenhuma mensagem de integridade transporta SQL, tabela ou PII. */
    public function test_scan_failures_stay_sanitized(): void
    {
        $port = new AdversarialScanPort(total: 101, pages: [
            self::catalogRows(range(1, 100)),
            self::catalogRows([1]),
        ]);

        $message = $this->capture(fn() => $this->repositoryWith($port)->getKanban(null, 25))->getMessage();

        // O contexto interno (`catalog_resource_status_duplicate_row`) fica no
        // diagnóstico fechado; o chamador recebe só código e correlação.
        foreach ([
            'SELECT', 'crm_resources_statuses', 'crm_resources', 'duplicate',
            'keyset', 'snapshot', 'scan',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $message, "vazou: {$forbidden}");
        }
    }

    /**
     * A varredura HONESTA continua funcionando: keyset em páginas cheias,
     * ordem pública aplicada no fim.
     */
    public function test_honest_keyset_scan_returns_the_public_order(): void
    {
        $port = new FakeCrmQueryPort();
        $port->seed(CrmSchema::TABLE_RESOURCE_STATUSES, array_map(
            static fn(int $id): array => [
                'id' => $id,
                // Nome decrescente enquanto o id cresce: se a ordem pública não
                // fosse aplicada depois, o resultado sairia na ordem do keyset.
                'name' => sprintf('S%05d', 250 - $id),
                'active' => 1,
                'deleted_at' => null,
            ],
            range(1, 250)
        ));
        foreach ([
            CrmSchema::TABLE_RESOURCE_TYPES,
            CrmSchema::TABLE_FOLLOWUP_TYPES,
            CrmSchema::TABLE_FOLLOWUP_STATUSES,
        ] as $table) {
            $port->seed($table, []);
        }
        $port->seed(CrmSchema::TABLE_RESOURCES, []);

        $catalog = $this->repositoryWith($port)->getKanban(null, 25)['catalogs']['resource_statuses'];

        $this->assertCount(250, $catalog, 'completo através de 3 chunks');
        $this->assertSame(250, $catalog[0]['id'], 'ordem pública é por nome, não por id');
        $this->assertSame('S00000', $catalog[0]['name']);
        $this->assertSame(
            array_map(static fn(array $e): string => $e['name'], $catalog),
            array_values(array_map(
                static fn(array $e): string => $e['name'],
                self::sortedByName($catalog)
            ))
        );
    }

    /**
     * @param array<int, array{id:int, name:string}> $entries
     * @return array<int, array{id:int, name:string}>
     */
    private static function sortedByName(array $entries): array
    {
        usort($entries, static fn(array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);

        return $entries;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private static function catalogRows(array $ids): array
    {
        return array_map(
            static fn(int $id): array => ['id' => $id, 'name' => sprintf('S%05d', $id)],
            $ids
        );
    }
}

/**
 * Port que MENTE de forma controlada sobre a varredura de catálogo.
 *
 * Responde ao teto do snapshot, ao COUNT e às páginas de keyset de forma
 * roteirizada, para que cada modo de corrupção possa ser exercitado
 * isoladamente. Continua somente leitura.
 */
final class AdversarialScanPort implements CrmQueryPort
{
    private int $pageIndex = 0;
    private int $countCalls = 0;

    /** @param array<int, array<int, array<string, mixed>>> $pages */
    public function __construct(
        private readonly int $total,
        private readonly array $pages,
        private readonly ?int $recountTotal = null,
    ) {
    }

    public function withinReadSnapshot(callable $operation): mixed
    {
        return $operation();
    }

    public function selectRows(CrmSelect $select): array
    {
        // Só o catálogo de resource status é roteirizado; os outros três e a
        // tabela de recursos respondem vazio para isolar o cenário.
        if ($select->table !== CrmSchema::TABLE_RESOURCE_STATUSES) {
            return [];
        }

        // Teto do snapshot: primeira consulta, ordenada por id desc, limite 1.
        if ($select->limit === 1 && $select->order === [['id', 'desc']]) {
            return [['id' => 999999, 'name' => 'snapshot']];
        }

        return $this->pages[$this->pageIndex++] ?? [];
    }

    public function countRows(CrmCount $count): int
    {
        if ($count->table !== CrmSchema::TABLE_RESOURCE_STATUSES) {
            return 0;
        }

        $this->countCalls++;

        if ($this->countCalls > 1 && $this->recountTotal !== null) {
            return $this->recountTotal;
        }

        return $this->total;
    }
}
