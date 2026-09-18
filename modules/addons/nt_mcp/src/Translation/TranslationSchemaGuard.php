<?php

declare(strict_types=1);

namespace NtMcp\Translation;

use NtMcp\Crm\CrmSchemaProbe;

/**
 * Barreira de schema, ANTES de qualquer consulta operacional de tradução.
 *
 * Mesmo contrato do `NtMcp\Crm\CrmSchemaGuard`: tabela mínima ausente vira
 * `translation_unavailable`; tabela presente e coluna ausente vira
 * `translation_schema_mismatch`; metadata indisponível vira `downstream` e NÃO
 * é memorizada (uma indisponibilidade transitória não pode congelar a
 * resposta pela request inteira).
 *
 * Reusa `NtMcp\Crm\CrmSchemaProbe` como está — é `hasTable`/`hasColumn`
 * genérico, o namespace do CRM só reflete de onde ele nasceu. Não duplicar o
 * probe.
 */
final class TranslationSchemaGuard
{
    /** @var array<string, true|TranslationException> apenas decisões conclusivas */
    private array $decided = [];

    public function __construct(private readonly CrmSchemaProbe $probe)
    {
    }

    /** @throws TranslationException */
    public function assert(string $capability = TranslationSchema::CAPABILITY_EMAIL_TEMPLATES): void
    {
        if (isset($this->decided[$capability])) {
            $decision = $this->decided[$capability];
            if ($decision instanceof TranslationException) {
                throw $decision;
            }

            return;
        }

        // Metadata indisponível NÃO é memorizada: `decide()` lança direto.
        $decision = $this->decide($capability);

        $this->decided[$capability] = $decision;

        if ($decision instanceof TranslationException) {
            throw $decision;
        }
    }

    /**
     * @return true|TranslationException conclusão memorizável
     * @throws TranslationException `downstream` quando a metadata é indisponível
     */
    private function decide(string $capability): bool|TranslationException // PHP 8.1: sem tipo `true` standalone
    {
        $requirements = TranslationSchema::requirementsFor($capability);

        if ($requirements === []) {
            return TranslationException::unavailable($capability);
        }

        foreach ($requirements as $table => $columns) {
            $tableFact = $this->probe->hasTable($table);

            if ($tableFact->isUnknown()) {
                throw TranslationException::downstream((string) $tableFact->correlationId);
            }

            if ($tableFact->isAbsent()) {
                return TranslationException::unavailable($capability);
            }

            foreach ($columns as $column) {
                $columnFact = $this->probe->hasColumn($table, $column);

                if ($columnFact->isUnknown()) {
                    throw TranslationException::downstream((string) $columnFact->correlationId);
                }

                if ($columnFact->isAbsent()) {
                    return TranslationException::schemaMismatch($capability);
                }
            }
        }

        return true;
    }
}
