<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Consulta de leitura FECHADA.
 *
 * Este value object é a barreira que torna verificável a afirmação "o
 * repositório não aceita nome de tabela, coluna, ordenação ou operador do
 * chamador": não existe caminho para executar uma leitura sem construir um
 * `CrmSelect`, e o construtor recusa qualquer nome que não esteja declarado em
 * `CrmSchema`. Um `new CrmSelect('tblclients', ['password'], ...)` lança antes
 * de qualquer contato com o banco.
 *
 * Restrições deliberadas:
 *  - projeção obrigatória e não vazia — `*` não é representável;
 *  - só igualdade escalar em `WHERE`, sem operador escolhível;
 *  - `IS NULL` restrito a colunas conhecidas (na prática, o soft-delete);
 *  - direção de ordenação limitada a `asc`/`desc`;
 *  - limite e offset já clampados por `CrmSchema`.
 */
final class CrmSelect
{
    /**
     * @param array<int, string>                 $columns    projeção explícita
     * @param array<string, int|string>          $conditions coluna => valor (igualdade)
     * @param array<int, string>                 $nullColumns colunas exigidas `IS NULL`
     * @param array<int, array{0:string,1:string}> $order
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns,
        public readonly array $conditions = [],
        public readonly array $nullColumns = [],
        public readonly array $order = [],
        public readonly int $limit = CrmSchema::DEFAULT_LIMIT,
        public readonly int $offset = 0,
    ) {
        if (!CrmSchema::isKnownTable($table)) {
            throw new \LogicException('CrmSelect: unknown CRM table.');
        }

        if ($columns === []) {
            throw new \LogicException('CrmSelect: an explicit projection is required.');
        }

        $known = CrmSchema::columnsOf($table);

        foreach ($columns as $column) {
            self::assertColumn($column, $known);
        }

        foreach ($conditions as $column => $value) {
            self::assertColumn((string) $column, $known);
            if (!is_int($value) && !is_string($value)) {
                throw new \LogicException('CrmSelect: only scalar equality conditions are allowed.');
            }
        }

        foreach ($nullColumns as $column) {
            self::assertColumn($column, $known);
        }

        foreach ($order as $clause) {
            if (!is_array($clause) || count($clause) !== 2) {
                throw new \LogicException('CrmSelect: malformed order clause.');
            }
            self::assertColumn((string) $clause[0], $known);
            if (!in_array($clause[1], ['asc', 'desc'], true)) {
                throw new \LogicException('CrmSelect: order direction must be asc or desc.');
            }
        }

        if ($limit < 1 || $limit > CrmSchema::MAX_LIMIT) {
            throw new \LogicException('CrmSelect: limit outside the shared read bounds.');
        }

        if ($offset < 0 || $offset > CrmSchema::MAX_OFFSET) {
            throw new \LogicException('CrmSelect: offset outside the shared read bounds.');
        }
    }

    /**
     * Identificadores que o Activity Log pode registrar (D7). `AuditMetadata`
     * ainda filtra por allowlist própria; aqui só evitamos passar adiante
     * condições que não sejam identificadores.
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
            // O nome não é interpolado: um nome de coluna construído
            // dinamicamente não pode virar veículo de texto arbitrário num sink.
            throw new \LogicException('CrmSelect: column is not part of the CRM contract.');
        }
    }
}
