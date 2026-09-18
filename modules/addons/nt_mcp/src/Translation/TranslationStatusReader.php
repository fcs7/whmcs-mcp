<?php

declare(strict_types=1);

namespace NtMcp\Translation;

use NtMcp\Crm\CapsuleSchemaProbe;
use NtMcp\Crm\CrmSchemaProbe;
use WHMCS\Database\Capsule;

/**
 * Leitura auxiliar de `whmcs_translation_status`, fora de `tblemailtemplates`.
 *
 * Deliberadamente SEPARADA de `EmailTemplateRepository` (que é a ÚNICA classe
 * que toca `tblemailtemplates`) e de `DynamicTranslationRepository` (que é a
 * ÚNICA classe que ESCREVE em `tbldynamic_translations`): aqui o dado é
 * `tblclients.language` — só contagem por idioma, nenhum outro campo de
 * cliente —, a leitura da flag "Enable Dynamic Translations" do WHMCS, e uma
 * leitura AGREGADA e somente-leitura de `tbldynamic_translations` (contagem
 * por idioma e por `related_type`) para confirmar ao vivo os formatos
 * gravados pela Fase 2+.
 */
final class TranslationStatusReader
{
    /** @var callable():string */
    private $dynamicTranslationsProbe;

    private CrmSchemaProbe $schemaProbe;

    public function __construct(?callable $dynamicTranslationsProbe = null, ?CrmSchemaProbe $schemaProbe = null)
    {
        $this->dynamicTranslationsProbe = $dynamicTranslationsProbe ?? self::defaultProbe();
        $this->schemaProbe = $schemaProbe ?? new CapsuleSchemaProbe();
    }

    /**
     * Contagem de clientes por `language`. Somente idioma e contagem — nenhum
     * outro dado de cliente atravessa esta classe.
     *
     * @return array<string, int>
     */
    public function clientLanguageCounts(): array
    {
        $rows = Capsule::table('tblclients')->select(['language'])->get();

        $counts = [];
        foreach ($rows as $row) {
            $language = (string) (is_array($row) ? ($row['language'] ?? '') : ($row->language ?? ''));
            $counts[$language] = ($counts[$language] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * `GetConfigurationValue` não está no `ALLOWED_COMMANDS` da LocalAPI, então
     * a leitura é direta em `\WHMCS\Config\Setting`. Classe ausente ou falha de
     * leitura devolve 'unknown' — nunca assume um dos dois estados.
     */
    public function dynamicTranslationsEnabled(): string
    {
        return (string) ($this->dynamicTranslationsProbe)();
    }

    /**
     * Contagem por `language` e por `related_type` (os literais fechados de
     * `DynamicTranslationMap`, ex.: `'product.{id}.name'`) em
     * `tbldynamic_translations` — só tipos e contagens, nunca o texto
     * traduzido. Tabela ausente (fase ainda não confirmada no desenv/prod, ou
     * "Enable Dynamic Translations" nunca usado) devolve contagens vazias em
     * vez de erro: esta leitura é panorama complementar de `status`, não um
     * requisito para o restante do payload.
     *
     * @return array{by_language: array<string,int>, by_related_type: array<string,int>}
     */
    public function dynamicTranslationCounts(): array
    {
        $tableFact = $this->schemaProbe->hasTable(TranslationSchema::TABLE_DYNAMIC_TRANSLATIONS);
        if (!$tableFact->isPresent()) {
            return ['by_language' => [], 'by_related_type' => []];
        }

        $rows = Capsule::table(TranslationSchema::TABLE_DYNAMIC_TRANSLATIONS)
            ->select(['language', 'related_type'])
            ->get();

        $byLanguage = [];
        $byRelatedType = [];
        foreach ($rows as $row) {
            $language = (string) (is_array($row) ? ($row['language'] ?? '') : ($row->language ?? ''));
            $relatedType = (string) (is_array($row) ? ($row['related_type'] ?? '') : ($row->related_type ?? ''));
            $byLanguage[$language] = ($byLanguage[$language] ?? 0) + 1;
            $byRelatedType[$relatedType] = ($byRelatedType[$relatedType] ?? 0) + 1;
        }
        ksort($byLanguage);
        ksort($byRelatedType);

        return ['by_language' => $byLanguage, 'by_related_type' => $byRelatedType];
    }

    /**
     * Contagem por `language` de `tblannouncements`, `tblknowledgebase` e
     * `tblknowledgebasecats` (Fase 4) — serve para confirmar ao vivo o modelo
     * de linha-filha documentado em `AnnouncementRepository`/
     * `KnowledgebaseRepository`. Cada tabela é isolada: ausente reporta
     * `'unavailable'` sem derrubar as demais nem o restante de `status`.
     *
     * @return array<string, array<string,int>|string>
     */
    public function contentVariantCounts(): array
    {
        return [
            'announcements' => $this->languageCountsFor(TranslationSchema::TABLE_ANNOUNCEMENTS),
            'kb_articles' => $this->languageCountsFor(TranslationSchema::TABLE_KNOWLEDGEBASE),
            'kb_categories' => $this->languageCountsFor(TranslationSchema::TABLE_KNOWLEDGEBASE_CATS),
        ];
    }

    /** @return array<string,int>|string 'unavailable' quando a tabela nao existe */
    private function languageCountsFor(string $table): array|string
    {
        $tableFact = $this->schemaProbe->hasTable($table);
        if (!$tableFact->isPresent()) {
            return 'unavailable';
        }

        $rows = Capsule::table($table)->select(['language'])->get();

        $counts = [];
        foreach ($rows as $row) {
            $language = (string) (is_array($row) ? ($row['language'] ?? '') : ($row->language ?? ''));
            $counts[$language] = ($counts[$language] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    private static function defaultProbe(): callable
    {
        return static function (): string {
            if (!class_exists('\WHMCS\Config\Setting')) {
                return 'unknown';
            }
            try {
                $raw = \WHMCS\Config\Setting::getValue('EnableTranslations');
            } catch (\Throwable $e) {
                return 'unknown';
            }

            return self::normalizeFlag($raw);
        };
    }

    /**
     * Normaliza um valor de flag de `tblconfiguration` (WHMCS guarda checkbox
     * como `'on'`, mas também aceitamos `'1'`/`'true'`/`'yes'` e a forma
     * booleana). Qualquer outro valor é `'unknown'` — nunca assume um dos dois
     * estados quando o dado não é reconhecido.
     */
    public static function normalizeFlag(mixed $raw): string
    {
        if ($raw === null) {
            return 'unknown';
        }

        if (is_bool($raw)) {
            return $raw ? 'enabled' : 'disabled';
        }

        $value = strtolower(trim((string) $raw));

        if (in_array($value, ['1', 'on', 'true', 'yes'], true)) {
            return 'enabled';
        }

        if (in_array($value, ['', '0', 'off', 'false', 'no'], true)) {
            return 'disabled';
        }

        return 'unknown';
    }
}
