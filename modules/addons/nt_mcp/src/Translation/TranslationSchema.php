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

    /**
     * Fase 2: tabela de destino de toda tradução de conteúdo dinâmico
     * (produto, grupo de produto — e, nas fases seguintes, config option,
     * custom field, addon de produto, departamento). `DynamicTranslationRepository`
     * é a ÚNICA classe que toca esta tabela.
     */
    public const TABLE_DYNAMIC_TRANSLATIONS = 'tbldynamic_translations';

    public const TABLE_PRODUCTS = 'tblproducts';

    public const TABLE_PRODUCT_GROUPS = 'tblproductgroups';

    public const CAPABILITY_EMAIL_TEMPLATES = 'email_templates';

    public const CAPABILITY_DYNAMIC = 'dynamic_translations';

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
        self::CAPABILITY_DYNAMIC => [
            self::TABLE_DYNAMIC_TRANSLATIONS => [
                'id', 'related_type', 'related_id', 'language', 'translation', 'input_type',
            ],
            self::TABLE_PRODUCTS => [
                'id', 'gid', 'name', 'description', 'hidden', 'retired',
            ],
            self::TABLE_PRODUCT_GROUPS => [
                'id', 'name', 'headline', 'tagline', 'hidden',
            ],
        ],
    ];

    /** @return array<string, array<int, string>> tabela => colunas exigidas */
    public static function requirementsFor(string $capability): array
    {
        return self::REQUIREMENTS[$capability] ?? [];
    }
}
