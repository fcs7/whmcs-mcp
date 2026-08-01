<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Seam de METADATA. Nenhuma implementação pode ler linha de cliente.
 *
 * As duas operações são as únicas que o guard precisa e as únicas que o
 * Schema Builder expõe sem tocar dados. Os argumentos vêm exclusivamente das
 * constantes de `CrmSchema` — nunca do chamador MCP.
 *
 * O retorno é `CrmSchemaFact`, não `bool`: uma falha do driver precisa ser
 * distinguível de uma ausência real, senão a indisponibilidade transitória é
 * publicada como schema ausente.
 */
interface CrmSchemaProbe
{
    public function hasTable(string $table): CrmSchemaFact;

    public function hasColumn(string $table, string $column): CrmSchemaFact;
}
