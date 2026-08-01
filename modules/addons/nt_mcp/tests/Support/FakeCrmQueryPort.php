<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

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

    public function selectRows(CrmSelect $select): array
    {
        $this->selects[] = $select;

        if ($this->failure !== null) {
            throw CrmException::downstream(
                Diagnostics::report(Diagnostics::CATEGORY_DB_EXCEPTION, 'crm_select', $this->failure)
            );
        }

        $rows = $this->rows[$select->table] ?? [];

        foreach ($select->conditions as $column => $value) {
            $rows = array_filter($rows, static fn(array $row): bool => ($row[$column] ?? null) == $value);
        }

        foreach ($select->nullColumns as $column) {
            $rows = array_filter($rows, static fn(array $row): bool => ($row[$column] ?? null) === null);
        }

        $rows = array_slice(array_values($rows), $select->offset, $select->limit);

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

    /** @return array<int, string> */
    public function selectedTables(): array
    {
        return array_map(static fn(CrmSelect $select): string => $select->table, $this->selects);
    }
}
