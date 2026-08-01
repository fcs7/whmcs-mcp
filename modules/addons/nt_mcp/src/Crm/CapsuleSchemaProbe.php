<?php

declare(strict_types=1);

namespace NtMcp\Crm;

use NtMcp\Whmcs\Diagnostics;
use WHMCS\Database\Capsule;

/**
 * Probe real, sobre o Schema Builder do WHMCS.
 *
 * Só `hasTable()`/`hasColumn()`: metadata abstrata, zero linha lida.
 *
 * Uma falha do driver NÃO vira "ausente". Ela vira `CrmSchemaFact::unknown()`,
 * com a correlação do incidente já registrado — e **não é memorizada**, para
 * que uma indisponibilidade transitória não congele a resposta pela request
 * inteira. Só resposta afirmativa ou negativa REAL entra no cache.
 *
 * A mensagem do driver nunca sai daqui; só categoria, classe e fingerprint,
 * pela fronteira única de diagnóstico.
 */
final class CapsuleSchemaProbe implements CrmSchemaProbe
{
    /** @var array<string, CrmSchemaFact> apenas fatos conclusivos */
    private array $facts = [];

    public function hasTable(string $table): CrmSchemaFact
    {
        return $this->remember(
            $table,
            static fn(): bool => (bool) Capsule::schema()->hasTable($table)
        );
    }

    public function hasColumn(string $table, string $column): CrmSchemaFact
    {
        return $this->remember(
            $table . '.' . $column,
            static fn(): bool => (bool) Capsule::schema()->hasColumn($table, $column)
        );
    }

    /** @param callable():bool $operation */
    private function remember(string $key, callable $operation): CrmSchemaFact
    {
        if (isset($this->facts[$key])) {
            return $this->facts[$key];
        }

        try {
            $fact = $operation() ? CrmSchemaFact::present() : CrmSchemaFact::absent();
        } catch (\Throwable $e) {
            // Deliberadamente FORA do cache: a próxima pergunta tenta de novo.
            return CrmSchemaFact::unknown(
                Diagnostics::report(Diagnostics::CATEGORY_DB_EXCEPTION, 'crm_schema_probe', $e)
            );
        }

        return $this->facts[$key] = $fact;
    }
}
