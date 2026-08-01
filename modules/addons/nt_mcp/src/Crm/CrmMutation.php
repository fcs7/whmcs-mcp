<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Mutação FECHADA sobre o domínio CRM.
 *
 * Existe agora, ainda sem estar ligada a nenhuma tool (CRM-3 faz a ligação),
 * por dois motivos concretos:
 *
 *  1. o seam de escrita precisa existir para que os testes deste ticket possam
 *     provar ZERO efeito quando schema, recurso, catálogo, data ou identidade
 *     administrativa falham — uma contagem de mutações que nunca pode subir;
 *  2. a allowlist de colunas graváveis é uma barreira de segurança, e criá-la
 *     junto com a de leitura evita que a escrita nasça depois sem ela.
 *
 * Colunas graváveis são um subconjunto ESTRITO das conhecidas: nenhuma tool
 * pode escrever `id`, `deleted_at` ou colunas de catálogo.
 */
final class CrmMutation
{
    /**
     * Colunas que cada tabela aceita em INSERT/UPDATE. `id` e `deleted_at`
     * nunca aparecem: identidade e soft-delete não são graváveis pela
     * superfície MCP.
     *
     * @var array<string, array<int, string>>
     */
    private const WRITABLE_COLUMNS = [
        CrmSchema::TABLE_RESOURCES => [
            'type_id', 'status_id', 'admin_id', 'name', 'lastname', 'email', 'phone',
            'country', 'short_description', 'description', 'created_at', 'updated_at',
        ],
        CrmSchema::TABLE_FOLLOWUPS => [
            'resource_id', 'type_id', 'status_id', 'admin_id',
            'description', 'date', 'created_at', 'updated_at',
        ],
        CrmSchema::TABLE_NOTES => [
            'resource_id', 'admin_id', 'content', 'created_at', 'updated_at',
        ],
    ];

    /** Colunas aceitas para localizar a linha em UPDATE. */
    private const WHERE_COLUMNS = [
        CrmSchema::TABLE_RESOURCES => ['id'],
        CrmSchema::TABLE_FOLLOWUPS => ['id', 'resource_id'],
        CrmSchema::TABLE_NOTES => ['id', 'resource_id'],
    ];

    /**
     * @param array<string, int|string|null>   $values
     * @param array<string, int|string>        $conditions vazio em INSERT
     * @param array<int, string>               $nullConditions soft-delete
     */
    private function __construct(
        public readonly string $verb,
        public readonly string $table,
        public readonly array $values,
        public readonly array $conditions = [],
        public readonly array $nullConditions = [],
    ) {
    }

    /** @param array<string, int|string|null> $values */
    public static function insert(string $table, array $values): self
    {
        self::assertTable($table);
        self::assertValues($table, $values);

        if ($values === []) {
            throw new \LogicException('CrmMutation: an INSERT needs at least one value.');
        }

        return new self('INSERT', $table, $values);
    }

    /**
     * @param array<string, int|string|null> $values
     * @param array<string, int|string>      $conditions
     * @param array<int, string>             $nullConditions
     */
    public static function update(
        string $table,
        array $values,
        array $conditions,
        array $nullConditions = [CrmSchema::COLUMN_DELETED_AT],
    ): self {
        self::assertTable($table);
        self::assertValues($table, $values);

        if ($values === []) {
            throw new \LogicException('CrmMutation: an UPDATE needs at least one value.');
        }

        if ($conditions === []) {
            // Um UPDATE sem WHERE atinge a tabela inteira. Nunca.
            throw new \LogicException('CrmMutation: an UPDATE without conditions is not permitted.');
        }

        $whereColumns = self::WHERE_COLUMNS[$table] ?? [];
        foreach ($conditions as $column => $value) {
            if (!in_array((string) $column, $whereColumns, true)) {
                throw new \LogicException('CrmMutation: condition column is not part of the CRM contract.');
            }
            if (!is_int($value) && !is_string($value)) {
                throw new \LogicException('CrmMutation: only scalar equality conditions are allowed.');
            }
        }

        $known = CrmSchema::columnsOf($table);
        foreach ($nullConditions as $column) {
            if (!in_array($column, $known, true)) {
                throw new \LogicException('CrmMutation: null condition column is not part of the CRM contract.');
            }
        }

        return new self('UPDATE', $table, $values, $conditions, $nullConditions);
    }

    private static function assertTable(string $table): void
    {
        if (!array_key_exists($table, self::WRITABLE_COLUMNS)) {
            throw new \LogicException('CrmMutation: table is not writable through the CRM surface.');
        }
    }

    /** @param array<string, int|string|null> $values */
    private static function assertValues(string $table, array $values): void
    {
        $writable = self::WRITABLE_COLUMNS[$table];

        foreach ($values as $column => $value) {
            if (!in_array((string) $column, $writable, true)) {
                throw new \LogicException('CrmMutation: column is not writable through the CRM surface.');
            }
            if ($value !== null && !is_int($value) && !is_string($value)) {
                throw new \LogicException('CrmMutation: only scalar values are writable.');
            }
        }
    }

    /** @return array<string, int|string> identificadores para o Activity Log */
    public function auditIds(): array
    {
        $ids = $this->conditions;

        foreach ($this->values as $column => $value) {
            if (str_ends_with((string) $column, '_id') && is_int($value)) {
                $ids[(string) $column] = $value;
            }
        }

        return $ids;
    }
}
