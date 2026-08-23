<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

use NtMcp\Crm\CrmCount;
use NtMcp\Crm\CrmException;
use NtMcp\Crm\CrmQueryPort;
use NtMcp\Crm\CrmSchema;
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
    public int $snapshotCount = 0;

    /** @var array<int, CrmSelect> */
    public array $selects = [];

    /** @var array<string, array<int, array<string, mixed>>> tabela => linhas */
    private array $rows = [];

    private ?\Throwable $failure = null;

    private ?\Throwable $rawFailure = null;

    /** @var array<string, array<int, array<string, mixed>>>|null */
    private ?array $snapshotRows = null;

    private bool $ambientTransaction = false;

    /** @var array{begin:?\Throwable,commit:?\Throwable,rollback:?\Throwable} */
    private array $snapshotFailures = ['begin' => null, 'commit' => null, 'rollback' => null];

    /** @var null|callable():void */
    private $onSnapshotQuery = null;

    /** @var array<string, int> tabela => teto falso reportado pelo executor */
    private array $highestIdOverrides = [];

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

    public function simulateAmbientTransaction(): self
    {
        $this->ambientTransaction = true;

        return $this;
    }

    public function failSnapshot(string $phase, ?\Throwable $failure): self
    {
        if (!array_key_exists($phase, $this->snapshotFailures)) {
            throw new \LogicException('unknown snapshot phase');
        }

        $this->snapshotFailures[$phase] = $failure;

        return $this;
    }

    /** @param callable():void $mutation */
    public function mutateAfterNextSnapshotQuery(callable $mutation): self
    {
        $this->onSnapshotQuery = $mutation;

        return $this;
    }

    /** Simula um executor que informa um teto que não corresponde às páginas. */
    public function reportHighestIdFor(string $table, int $id): self
    {
        $this->highestIdOverrides[$table] = $id;

        return $this;
    }

    public function withinReadSnapshot(callable $operation): mixed
    {
        $this->snapshotCount++;

        if ($this->ambientTransaction || $this->snapshotRows !== null) {
            throw CrmException::downstream(Diagnostics::report(
                Diagnostics::CATEGORY_DB_EXCEPTION,
                'crm_snapshot',
                new \RuntimeException('ambient transaction')
            ));
        }

        if ($this->snapshotFailures['begin'] !== null) {
            throw CrmException::downstream(Diagnostics::report(
                Diagnostics::CATEGORY_DB_EXCEPTION,
                'crm_snapshot',
                $this->snapshotFailures['begin']
            ));
        }

        $this->snapshotRows = $this->rows;

        try {
            $result = $operation();
        } catch (CrmException $e) {
            $this->closeSnapshot('rollback');
            throw $e;
        } catch (\Throwable $e) {
            $this->closeSnapshot('rollback');
            throw CrmException::downstream(Diagnostics::report(
                Diagnostics::CATEGORY_DB_EXCEPTION,
                'crm_snapshot',
                $e
            ));
        }

        $this->closeSnapshot('commit');

        return $result;
    }

    /** @var array<int, CrmCount> */
    public array $counts = [];

    public function selectRows(CrmSelect $select): array
    {
        $this->selects[] = $select;

        $this->runScheduledMutation();

        if ($this->rawFailure !== null) {
            throw $this->rawFailure;
        }

        if ($this->failure !== null) {
            throw CrmException::downstream(
                Diagnostics::report(Diagnostics::CATEGORY_DB_EXCEPTION, 'crm_select', $this->failure)
            );
        }

        if (
            isset($this->highestIdOverrides[$select->table])
            && $select->limit === 1
            && $select->order === [[CrmSchema::COLUMN_ID, 'desc']]
        ) {
            return [[CrmSchema::COLUMN_ID => $this->highestIdOverrides[$select->table]]];
        }

        $rows = $this->matching(
            $select->table,
            $select->conditions,
            $select->nullColumns,
            $select->inConditions,
            $select->afterId,
            $select->throughId
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

        $this->runScheduledMutation();

        if ($this->rawFailure !== null) {
            throw $this->rawFailure;
        }

        if ($this->failure !== null) {
            throw CrmException::downstream(
                Diagnostics::report(Diagnostics::CATEGORY_DB_EXCEPTION, 'crm_select', $this->failure)
            );
        }

        return count($this->matching(
            $count->table,
            $count->conditions,
            $count->nullColumns,
            $count->inConditions,
            null,
            $count->throughId
        ));
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
        $leftDigits = self::digitsOf($left);
        $rightDigits = self::digitsOf($right);

        // Zero é zero: `-0` e `0` são o MESMO número, então o sinal só conta
        // depois de descartar o caso em que os dígitos são nulos.
        $leftNegative = str_starts_with($left, '-') && $leftDigits !== '0';
        $rightNegative = str_starts_with($right, '-') && $rightDigits !== '0';

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $comparison = strlen($leftDigits) <=> strlen($rightDigits);

        if ($comparison === 0) {
            $comparison = strcmp($leftDigits, $rightDigits);
        }

        return $leftNegative ? -$comparison : $comparison;
    }

    /** Dígitos significativos, sem sinal e sem zeros à esquerda. */
    private static function digitsOf(string $value): string
    {
        $digits = ltrim(ltrim(trim($value), '+-'), '0');

        return $digits === '' ? '0' : $digits;
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
        ?int $afterId = null,
        ?int $throughId = null,
    ): array {
        $rows = ($this->snapshotRows ?? $this->rows)[$table] ?? [];

        if ($afterId !== null) {
            $rows = array_filter(
                $rows,
                static fn(array $row): bool => (int) ($row[CrmSchema::COLUMN_ID] ?? 0) > $afterId
            );
        }

        if ($throughId !== null) {
            $rows = array_filter(
                $rows,
                static fn(array $row): bool => (int) ($row[CrmSchema::COLUMN_ID] ?? 0) <= $throughId
            );
        }

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

                // A decisão vem do SCHEMA, não do conteúdo. Um `name` VARCHAR
                // valendo "9" e "10" ordena lexicalmente no MySQL; decidir pelo
                // valor fazia o dublê ratificar uma ordem que produção não tem.
                // Só id/FK/flag compara como inteiro — e aí sem `float`, que
                // perderia precisão acima de 2^53.
                // `strcmp()` e não `<=>`: em PHP 8 o spaceship compara DUAS
                // strings numéricas como números, então `"9"` vs `"10"` num
                // VARCHAR sairia numérico e divergiria do MySQL.
                $comparison = CrmSchema::isIntegerColumn($column)
                    ? self::compareIntegerStrings((string) $left, (string) $right)
                    : strcmp((string) $left, (string) $right);

                if ($comparison !== 0) {
                    return $direction === 'desc' ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $rows;
    }

    private function runScheduledMutation(): void
    {
        if ($this->onSnapshotQuery === null) {
            return;
        }

        $mutation = $this->onSnapshotQuery;
        $this->onSnapshotQuery = null;
        $mutation();
    }

    private function closeSnapshot(string $phase): void
    {
        $this->snapshotRows = null;

        if ($this->snapshotFailures[$phase] !== null) {
            throw CrmException::downstream(Diagnostics::report(
                Diagnostics::CATEGORY_DB_EXCEPTION,
                'crm_snapshot',
                $this->snapshotFailures[$phase]
            ));
        }
    }
}
