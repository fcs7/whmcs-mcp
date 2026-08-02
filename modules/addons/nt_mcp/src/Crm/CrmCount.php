<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Contagem de leitura FECHADA — o par de `CrmSelect` para `count`/`total`.
 *
 * Existe como value object PRÓPRIO, e não como um flag em `CrmSelect`, por um
 * motivo de contrato: uma contagem não tem projeção, ordenação, limite nem
 * offset, e reaproveitar o select obrigaria a IGNORAR silenciosamente quatro
 * campos que o chamador acabou de informar. Um campo ignorado é uma promessa
 * quebrada esperando para acontecer — `countRows($select)` com `limit: 25`
 * pareceria contar 25 linhas e contaria todas.
 *
 * A regra que importa para o contrato público é que a contagem enxergue
 * EXATAMENTE o mesmo recorte dos itens. Por isso o filtro aceito aqui é o mesmo
 * de `CrmSelect` — igualdade escalar em coluna conhecida e `IS NULL` em coluna
 * conhecida — e nada além: sem operador escolhível, sem nome vindo do chamador,
 * sem tabela fora do catálogo fixo de `CrmSchema`.
 */
final class CrmCount
{
    /**
     * @param array<string, int|string> $conditions  coluna => valor (igualdade)
     * @param array<int, string>        $nullColumns colunas exigidas `IS NULL`
     */
    public function __construct(
        public readonly string $table,
        public readonly array $conditions = [],
        public readonly array $nullColumns = [],
        public readonly ?int $throughId = null,
    ) {
        if (!CrmSchema::isKnownTable($table)) {
            throw new \LogicException('CrmCount: unknown CRM table.');
        }

        if ($throughId !== null) {
            if ($throughId < 1) {
                throw new \LogicException('CrmCount: the id upper bound must be a positive id.');
            }

            self::assertColumn(CrmSchema::COLUMN_ID, CrmSchema::columnsOf($table));
        }

        $known = CrmSchema::columnsOf($table);

        foreach ($conditions as $column => $value) {
            self::assertColumn((string) $column, $known);
            if (!is_int($value) && !is_string($value)) {
                throw new \LogicException('CrmCount: only scalar equality conditions are allowed.');
            }
        }

        foreach ($nullColumns as $column) {
            self::assertColumn($column, $known);
        }
    }

    /**
     * Constrói a contagem que corresponde a um select — a garantia estrutural
     * de que `count` e `items` compartilham o mesmo filtro. Chamar isto é a
     * única forma usada pelo repositório de paginar.
     */
    public static function matching(CrmSelect $select): self
    {
        return new self(
            $select->table,
            $select->conditions,
            $select->nullColumns,
            $select->throughId
        );
    }

    /**
     * Identificadores que o Activity Log pode registrar (D7), no mesmo formato
     * de `CrmSelect::auditIds()`.
     *
     * @return array<string, int|string>
     */
    public function auditIds(): array
    {
        return $this->conditions;
    }

    /** @param array<int, string> $known */
    private static function assertColumn(string $column, array $known): void
    {
        if (!in_array($column, $known, true)) {
            // Mesmo cuidado de `CrmSelect`: o nome nunca é interpolado, para que
            // uma coluna construída dinamicamente não vire veículo de texto
            // arbitrário num sink.
            throw new \LogicException('CrmCount: column is not part of the CRM contract.');
        }
    }
}
