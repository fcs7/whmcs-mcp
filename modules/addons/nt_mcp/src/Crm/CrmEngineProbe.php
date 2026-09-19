<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Seam de METADATA para o storage engine de uma tabela.
 *
 * Mesmo espírito de `CrmSchemaProbe`: nenhuma implementação lê linha de
 * dados, só metadata (`information_schema.TABLES.ENGINE`). O retorno é
 * `CrmSchemaFact`, reaproveitado com a semântica local
 * present=InnoDB/absent=engine diferente/unknown=falha de metadata — os
 * mesmos três estados, para que um driver fora do ar nunca seja publicado
 * como "engine incompatível".
 */
interface CrmEngineProbe
{
    public function isInnoDb(string $table): CrmSchemaFact;
}
