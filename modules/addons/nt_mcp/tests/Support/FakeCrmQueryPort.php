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

    private ?\Throwable $rawFailure = null;

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

    /**
     * Falha CRUA, sem passar pela conversão em `CrmException`.
     *
     * Simula o que o port real NÃO faz — deixar um `Throwable` escapar — para
     * exercitar a fronteira de último recurso da tool. É esse caminho que a
     * revisão fria usou para publicar SQLSTATE, senha, path e e-mail.
     */
    public function failWithRaw(\Throwable $failure): self
    {
        $this->rawFailure = $failure;

        return $this;
    }

    /** @var array<int, CrmCount> */
    public array $counts = [];

    public function selectRows(CrmSelect $select): array
    {
        $this->selects[] = $select;

        if ($this->rawFailure !== null) {
            throw $this->rawFailure;
        }

        if ($this->failure !== null) {
            throw CrmException::downstream(
                Diagnostics::report(Diagnostics::CATEGORY_DB_EXCEPTION, 'crm_select', $this->failure)
            );
        }

        $rows = $this->matching(
            $select->table,
            $select->conditions,
            $select->nullColumns,
            $select->inConditions
        );

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

        if ($this->rawFailure !== null) {
            throw $this->rawFailure;
        }

        if ($this->failure !== null) {
            throw CrmException::downstream(
                Diagnostics::report(Diagnostics::CATEGORY_DB_EXCEPTION, 'crm_select', $this->failure)
            );
        }

        return count($this->matching($count->table, $count->conditions, $count->nullColumns));
    }

    /**
     * Comparação de inteiros SEM ponto flutuante.
     *
     * O dublê antes convertia os dois lados para `float`, e `float` não
     * distingue inteiros acima de `2^53`: `9007199254740992` e
     * `9007199254740993` viravam o mesmo número, então a ordenação saía errada
     * — no fake. O MySQL ordena BIGINT sem essa perda, então a regressão
     * passava aqui e quebraria em produção.
     *
     * A comparação é por sinal, depois comprimento, depois lexicografia — o que
     * é exatamente a ordem numérica para inteiros normalizados, em qualquer
     * magnitude.
     */
    private static function compareIntegerStrings(string $left, string $right): int
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $leftDigits = ltrim(ltrim($left, '+-'), '0');
        $rightDigits = ltrim(ltrim($right, '+-'), '0');
        $leftDigits = $leftDigits === '' ? '0' : $leftDigits;
        $rightDigits = $rightDigits === '' ? '0' : $rightDigits;

        $comparison = strlen($leftDigits) <=> strlen($rightDigits);

        if ($comparison === 0) {
            $comparison = strcmp($leftDigits, $rightDigits);
        }

        return $leftNegative ? -$comparison : $comparison;
    }

    /** Inteiro PHP ou string de dígitos — o que o driver devolve para BIGINT. */
    private static function isIntegerLike(mixed $value): bool
    {
        return is_int($value)
            || (is_string($value) && preg_match('/^[+-]?\d+\z/', $value) === 1);
    }

    /** @return array<int, string> */
    public function selectedTables(): array
    {
        return array_map(static fn(CrmSelect $select): string => $select->table, $this->selects);
    }

    /**
     * @param array<string, int|string>      $conditions
     * @param array<int, string>             $nullColumns
     * @param array<string, array<int, int>> $inConditions
     * @return array<int, array<string, mixed>>
     */
    private function matching(
        string $table,
        array $conditions,
        array $nullColumns,
        array $inConditions = [],
    ): array {
        $rows = $this->rows[$table] ?? [];

        foreach ($conditions as $column => $value) {
            $rows = array_filter($rows, static fn(array $row): bool => ($row[$column] ?? null) == $value);
        }

        foreach ($inConditions as $column => $values) {
            $rows = array_filter(
                $rows,
                static fn(array $row): bool => in_array((int) ($row[$column] ?? 0), $values, true)
            );
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

                // Inteiro (inclusive na forma string do driver) compara como
                // inteiro — '10' < '9' em texto puro. BIGINT não passa por
                // `float`, que perderia precisão acima de 2^53.
                $comparison = (self::isIntegerLike($left) && self::isIntegerLike($right))
                    ? self::compareIntegerStrings((string) $left, (string) $right)
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
