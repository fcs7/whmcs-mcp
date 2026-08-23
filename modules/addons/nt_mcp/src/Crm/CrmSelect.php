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
     * @param array<string, array<int, int>>     $inConditions coluna => ids (`IN`)
     * @param int|null                           $afterId   `id >` exclusivo (keyset)
     * @param int|null                           $throughId `id <=` inclusivo (upper bound)
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns,
        public readonly array $conditions = [],
        public readonly array $nullColumns = [],
        public readonly array $order = [],
        public readonly int $limit = CrmSchema::DEFAULT_LIMIT,
        public readonly int $offset = 0,
        public readonly array $inConditions = [],
        public readonly ?int $afterId = null,
        public readonly ?int $throughId = null,
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

        self::assertInConditions($table, $inConditions, $known);
        self::assertIdRange($table, $afterId, $throughId, $known);
    }

    /**
     * Faixa de `id` FECHADA — o keyset das varreduras completas.
     *
     * A coluna é FIXA (`id`) e os operadores são literais nossos (`>` e `<=`).
     * O chamador do repositório nunca escolhe nenhum dos dois: `afterId` é o
     * último id já lido pela própria varredura e `throughId` é o teto lógico
     * capturado no início dela. Isto substitui a paginação por offset, que a
     * revisão fria mostrou aceitar duplicata + omissão como conjunto completo
     * quando as linhas se deslocam entre páginas.
     *
     * @param array<int, string> $known
     */
    private static function assertIdRange(string $table, ?int $afterId, ?int $throughId, array $known): void
    {
        if ($afterId === null && $throughId === null) {
            return;
        }

        self::assertColumn(CrmSchema::COLUMN_ID, $known);

        foreach ([$afterId, $throughId] as $bound) {
            if ($bound !== null && $bound < 1) {
                throw new \LogicException('CrmSelect: id bounds must be positive ids.');
            }
        }

        if ($afterId !== null && $throughId !== null && $afterId > $throughId) {
            throw new \LogicException('CrmSelect: the id range is inverted.');
        }
    }

    /**
     * `IN` FECHADO — a única forma de resolver um lote de ids sem N+1.
     *
     * Continua sem operador escolhível: a coluna vem do catálogo fixo, os
     * valores são exclusivamente ids inteiros positivos (nunca string, nunca
     * expressão) e a lista tem teto. Uma lista VAZIA é recusada de propósito:
     * `IN ()` é SQL inválido em MySQL e, pior, um chamador interno que montasse
     * um lote vazio esperaria "nenhum filtro" e receberia a tabela inteira.
     *
     * @param array<string, array<int, int>> $inConditions
     * @param array<int, string>             $known
     */
    private static function assertInConditions(string $table, array $inConditions, array $known): void
    {
        foreach ($inConditions as $column => $values) {
            self::assertColumn((string) $column, $known);

            if (!is_array($values) || $values === []) {
                throw new \LogicException('CrmSelect: an IN condition requires a non-empty id list.');
            }

            if (count($values) > CrmSchema::MAX_IN_VALUES) {
                throw new \LogicException('CrmSelect: IN condition exceeds the shared batch bound.');
            }

            foreach ($values as $value) {
                if (!is_int($value) || $value < 1) {
                    throw new \LogicException('CrmSelect: IN conditions accept positive integer ids only.');
                }
            }
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
