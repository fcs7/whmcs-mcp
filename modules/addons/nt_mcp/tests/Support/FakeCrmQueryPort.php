<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

use NtMcp\Crm\CrmCount;
use NtMcp\Crm\CrmException;
use NtMcp\Crm\CrmQueryPort;
use NtMcp\Crm\CrmSelect;
use NtMcp\Whmcs\Diagnostics;

/**
 * Execução em memória do seam fechado — somente leitura, como o port real.
 *
 * Guarda as consultas executadas, para que os testes possam confirmar que
 * nenhuma acontece antes do schema guard, que toda leitura filtra o soft-delete
 * e que o filtro de atividade do catálogo está na query e não em PHP.
 *
 * A contagem de efeitos de escrita não vive mais aqui: o port não tem mais
 * método de escrita. Quem prova "zero efeito" agora é `FakeCapsule::$mutations`,
 * que observa o DRIVER — uma prova mais forte, porque cobre qualquer caminho,
 * não só o que passa por este dublê.
 */
final class FakeCrmQueryPort implements CrmQueryPort
{
    /** @var array<int, CrmSelect> */
    public array $selects = [];

    /** @var array<string, array<int, array<string, mixed>>> tabela => linhas */
    private array $rows = [];

    private ?\Throwable $failure = null;

    /** @param array<int, array<string, mixed>> $rows */
    public function seed(string $table, array $rows): self
    {
        $this->rows[$table] = $rows;

        return $this;
    }

    public function failWith(?\Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    /** @var array<int, CrmCount> */
    public array $counts = [];

    public function selectRows(CrmSelect $select): array
    {
        $this->selects[] = $select;

        if ($this->failure !== null) {
            throw CrmException::downstream(
                Diagnostics::report(Diagnostics::CATEGORY_DB_EXCEPTION, 'crm_select', $this->failure)
            );
        }

        $rows = $this->matching($select->table, $select->conditions, $select->nullColumns);

        $rows = self::sortRows($rows, $select->order);

        $rows = array_slice($rows, $select->offset, $select->limit);

        // Projeção: o fake devolve APENAS as colunas pedidas, como o driver.
        return array_map(
            static function (array $row) use ($select): array {
                $projected = [];
                foreach ($select->columns as $column) {
                    $projected[$column] = $row[$column] ?? null;
                }

                return $projected;
            },
            $rows
        );
    }

    /**
     * Contagem sob o MESMO filtro do select — sem limite e sem offset, como o
     * `COUNT` real. Se este dublê aplicasse o limite, `has_more` seria sempre
     * falso e a regressão de paginação passaria sem provar nada.
     */
    public function countRows(CrmCount $count): int
    {
        $this->counts[] = $count;

        if ($this->failure !== null) {
            throw CrmException::downstream(
                Diagnostics::report(Diagnostics::CATEGORY_DB_EXCEPTION, 'crm_select', $this->failure)
            );
        }

        return count($this->matching($count->table, $count->conditions, $count->nullColumns));
    }

    /** @return array<int, string> */
    public function selectedTables(): array
    {
        return array_map(static fn(CrmSelect $select): string => $select->table, $this->selects);
    }

    /**
     * @param array<string, int|string> $conditions
     * @param array<int, string>        $nullColumns
     * @return array<int, array<string, mixed>>
     */
    private function matching(string $table, array $conditions, array $nullColumns): array
    {
        $rows = $this->rows[$table] ?? [];

        foreach ($conditions as $column => $value) {
            $rows = array_filter($rows, static fn(array $row): bool => ($row[$column] ?? null) == $value);
        }

        foreach ($nullColumns as $column) {
            $rows = array_filter($rows, static fn(array $row): bool => ($row[$column] ?? null) === null);
        }

        return array_values($rows);
    }

    /**
     * Ordenação real do dublê. Sem ela, um teste de ordenação determinística
     * estaria apenas confirmando a ordem em que o próprio teste semeou.
     *
     * @param array<int, array<string, mixed>>     $rows
     * @param array<int, array{0:string,1:string}> $order
     * @return array<int, array<string, mixed>>
     */
    private static function sortRows(array $rows, array $order): array
    {
        if ($order === []) {
            return $rows;
        }

        usort($rows, static function (array $a, array $b) use ($order): int {
            foreach ($order as [$column, $direction]) {
                $left = $a[$column] ?? null;
                $right = $b[$column] ?? null;

                // Numérico quando ambos os lados são numéricos: o driver
                // devolve id como string e '10' < '9' em comparação textual.
                $comparison = (is_numeric($left) && is_numeric($right))
                    ? ((float) $left <=> (float) $right)
                    : ((string) $left <=> (string) $right);

                if ($comparison !== 0) {
                    return $direction === 'desc' ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $rows;
    }
}
