<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Seam de METADATA. Nenhuma implementação pode ler linha de cliente.
 *
 * As duas operações são as únicas que o guard precisa e as únicas que o
 * Schema Builder expõe sem tocar dados. Os argumentos vêm exclusivamente das
 * constantes de `CrmSchema` — nunca do chamador MCP.
 */
interface CrmSchemaProbe
{
    public function hasTable(string $table): bool;

    public function hasColumn(string $table, string $column): bool;
}
