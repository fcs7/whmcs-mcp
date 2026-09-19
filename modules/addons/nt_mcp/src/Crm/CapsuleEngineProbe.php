<?php

declare(strict_types=1);

namespace NtMcp\Crm;

use NtMcp\Whmcs\Diagnostics;
use WHMCS\Database\Capsule;

/**
 * Probe real do storage engine de uma tabela, via `information_schema.TABLES`.
 *
 * Filtra por `table_schema = database()` (o banco da conexão ativa, nunca um
 * literal vindo de fora) e `table_name = $table` (sempre uma constante de
 * `TranslationSchema`, nunca input do chamador MCP). Só ENGINE é lido — zero
 * linha de dado de negócio.
 *
 * MySQL 8 devolve o nome de coluna de `information_schema` em MAIÚSCULO
 * (`ENGINE`, `TABLE_NAME`) quando não há alias explícito — `select(['engine'])`
 * sozinho não força minúsculo no resultado, só na cláusula. Por isso o SELECT
 * usa `selectRaw('engine as engine_name')`: o alias É a chave do resultado,
 * garantido minúsculo em qualquer driver/versão. `engineOf()` ainda cai para
 * `engine`/`ENGINE` como rede de segurança.
 *
 * Falha do driver vira `CrmSchemaFact::unknown()`, não memorizada — mesma
 * regra de `CapsuleSchemaProbe`.
 */
final class CapsuleEngineProbe implements CrmEngineProbe
{
    /** @var array<string, CrmSchemaFact> apenas fatos conclusivos */
    private array $facts = [];

    public function isInnoDb(string $table): CrmSchemaFact
    {
        if (isset($this->facts[$table])) {
            return $this->facts[$table];
        }

        try {
            $row = Capsule::table('information_schema.tables')
                ->whereRaw('table_schema = database()')
                ->where('table_name', $table)
                ->selectRaw('engine AS engine_name')
                ->first();
        } catch (\Throwable $e) {
            // Deliberadamente FORA do cache: a próxima pergunta tenta de novo.
            return CrmSchemaFact::unknown(
                Diagnostics::report(Diagnostics::CATEGORY_DB_EXCEPTION, 'crm_engine_probe', $e)
            );
        }

        $engine = $this->engineOf($row);
        $fact = (is_string($engine) && strcasecmp($engine, 'InnoDB') === 0)
            ? CrmSchemaFact::present()
            : CrmSchemaFact::absent();

        return $this->facts[$table] = $fact;
    }

    /**
     * `engine_name` é a chave esperada (alias explícito da `selectRaw()`
     * acima). `engine`/`ENGINE` ficam como rede de segurança, caso um driver
     * futuro devolva a coluna sem honrar o alias.
     */
    private function engineOf(mixed $row): ?string
    {
        if ($row === null) {
            return null;
        }

        $value = is_array($row)
            ? ($row['engine_name'] ?? $row['ENGINE'] ?? $row['engine'] ?? null)
            : ($row->engine_name ?? $row->ENGINE ?? $row->engine ?? null);

        return $value === null ? null : (string) $value;
    }
}
