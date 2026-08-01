<?php

declare(strict_types=1);

namespace NtMcp\Crm;

use NtMcp\Whmcs\Diagnostics;
use WHMCS\Database\Capsule;

/**
 * Probe real, sobre o Schema Builder do WHMCS.
 *
 * Só `hasTable()`/`hasColumn()`: metadata abstrata, zero linha lida. Uma falha
 * do driver NÃO é convertida em "existe" — devolve `false`, e o guard traduz
 * isso em `crm_unavailable`. Fail-closed é a única resposta segura aqui: um
 * `true` otimista deixaria a operação seguir contra um schema desconhecido.
 *
 * A mensagem do driver nunca sai daqui; só categoria, classe e fingerprint,
 * pela fronteira única de diagnóstico.
 */
final class CapsuleSchemaProbe implements CrmSchemaProbe
{
    /** @var array<string, bool> */
    private array $tables = [];

    /** @var array<string, bool> */
    private array $columns = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= $this->probe(
            static fn(): bool => (bool) Capsule::schema()->hasTable($table)
        );
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->columns[$table . '.' . $column] ??= $this->probe(
            static fn(): bool => (bool) Capsule::schema()->hasColumn($table, $column)
        );
    }

    /** @param callable():bool $operation */
    private function probe(callable $operation): bool
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            Diagnostics::report(Diagnostics::CATEGORY_DB_EXCEPTION, 'crm_schema_probe', $e);

            return false;
        }
    }
}
