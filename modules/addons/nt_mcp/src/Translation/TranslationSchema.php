<?php

declare(strict_types=1);

namespace NtMcp\Translation;

/**
 * Catálogo FECHADO do domínio de tradução (Fase 1: e-mail).
 *
 * Mesmo espírito de `NtMcp\Crm\CrmSchema`: nenhuma tool ou repositório aceita
 * nome de tabela ou coluna vindo de fora. `EmailTemplateRepository` é a ÚNICA
 * classe que toca `tblemailtemplates`, e as colunas abaixo são exatamente o
 * conjunto que ela lê/grava.
 */
final class TranslationSchema
{
    public const TABLE_EMAIL_TEMPLATES = 'tblemailtemplates';

    public const CAPABILITY_EMAIL_TEMPLATES = 'email_templates';

    /**
     * Conjunto mínimo exigido por capacidade.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const REQUIREMENTS = [
        self::CAPABILITY_EMAIL_TEMPLATES => [
            self::TABLE_EMAIL_TEMPLATES => [
                'id', 'type', 'name', 'subject', 'message', 'language', 'fromname', 'fromemail',
                'attachments', 'copyto', 'blind_copy_to', 'plaintext', 'disabled', 'custom',
            ],
        ],
    ];

    /** @return array<string, array<int, string>> tabela => colunas exigidas */
    public static function requirementsFor(string $capability): array
    {
        return self::REQUIREMENTS[$capability] ?? [];
    }
}
