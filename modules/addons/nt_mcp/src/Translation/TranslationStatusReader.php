<?php

declare(strict_types=1);

namespace NtMcp\Translation;

use WHMCS\Database\Capsule;

/**
 * Leitura auxiliar de `whmcs_translation_status`, fora de `tblemailtemplates`.
 *
 * Deliberadamente SEPARADA de `EmailTemplateRepository` (que é a ÚNICA classe
 * que toca `tblemailtemplates`): aqui o dado é `tblclients.language` — só
 * contagem por idioma, nenhum outro campo de cliente — e a leitura da flag
 * "Enable Dynamic Translations" do WHMCS.
 */
final class TranslationStatusReader
{
    /** @var callable():string */
    private $dynamicTranslationsProbe;

    public function __construct(?callable $dynamicTranslationsProbe = null)
    {
        $this->dynamicTranslationsProbe = $dynamicTranslationsProbe ?? self::defaultProbe();
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

            if ($raw === null) {
                return 'unknown';
            }

            return ((string) $raw === '1' || $raw === true) ? 'enabled' : 'disabled';
        };
    }
}
