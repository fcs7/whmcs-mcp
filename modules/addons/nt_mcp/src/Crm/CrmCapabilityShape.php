<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * O que o guard PROVOU sobre uma capacidade: ela está disponível e estas
 * colunas opcionais existem fisicamente.
 *
 * O repositório consulta isto para decidir o filtro de atividade dos catálogos
 * — nunca para decidir se pode consultar. Essa decisão já foi tomada (e pode
 * ter falhado fechado) antes de existir um shape.
 */
final class CrmCapabilityShape
{
    /** @param array<string, array<int, string>> $optionalColumns tabela => colunas presentes */
    public function __construct(
        public readonly CrmCapability $capability,
        private readonly array $optionalColumns = [],
    ) {
    }

    public function hasOptionalColumn(string $table, string $column): bool
    {
        return in_array($column, $this->optionalColumns[$table] ?? [], true);
    }
}
