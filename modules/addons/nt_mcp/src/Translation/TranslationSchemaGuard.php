<?php

declare(strict_types=1);

namespace NtMcp\Translation;

use NtMcp\Crm\CapsuleEngineProbe;
use NtMcp\Crm\CrmEngineProbe;
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

    /** @var array<string, true|TranslationException> apenas decisões conclusivas, por tabela */
    private array $decidedEngines = [];

    public function __construct(
        private readonly CrmSchemaProbe $probe,
        private readonly CrmEngineProbe $engineProbe = new CapsuleEngineProbe(),
    ) {
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
     * Barreira de ENGINE, para o caminho de ESCRITA apenas — tudo-ou-nada e
     * `lockForUpdate()` assumem InnoDB (MyISAM não tem transação nem lock de
     * linha real). Chamar SÓ antes de `applyBatch()`/`applyArticleBatch()`/
     * `applyCategoryBatch()`, nunca nos caminhos de leitura.
     *
     * `$table` é sempre uma constante de `TranslationSchema` — nunca input do
     * chamador MCP. Tabela ausente da metadata (probe já falhou antes desta
     * checagem no fluxo normal) é tratada como engine incompatível, não como
     * `translation_unavailable` — essa distinção já foi feita por `assert()`.
     *
     * @throws TranslationException `unsupported_engine` ou `downstream`
     */
    public function assertInnoDb(string $table): void
    {
        if (isset($this->decidedEngines[$table])) {
            $decision = $this->decidedEngines[$table];
            if ($decision instanceof TranslationException) {
                throw $decision;
            }

            return;
        }

        $decision = $this->decideEngine($table);

        $this->decidedEngines[$table] = $decision;

        if ($decision instanceof TranslationException) {
            throw $decision;
        }
    }

    /**
     * @return true|TranslationException conclusão memorizável
     * @throws TranslationException `downstream` quando a metadata é indisponível
     */
    private function decideEngine(string $table): bool|TranslationException
    {
        $fact = $this->engineProbe->isInnoDb($table);

        if ($fact->isUnknown()) {
            throw TranslationException::downstream((string) $fact->correlationId);
        }

        return $fact->isPresent() ? true : TranslationException::unsupportedEngine($table);
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
