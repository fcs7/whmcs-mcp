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

    /** Fase 3. Coluna literal/pública é `name`, mas a coluna fonte real é `fieldname` — ver `DynamicTranslationMap`. */
    public const TABLE_CUSTOM_FIELDS = 'tblcustomfields';

    /** Fase 3 (addon de produto). */
    public const TABLE_PRODUCT_ADDONS = 'tbladdons';

    /** Fase 3. */
    public const TABLE_TICKET_DEPARTMENTS = 'tblticketdepartments';

    /** Fase 4 (anúncio). Linha original: `parentid=0`, `language=''`; variante: linha filha `parentid=<id>`. */
    public const TABLE_ANNOUNCEMENTS = 'tblannouncements';

    /** Fase 4 (artigo de KB). Linha original: `parentid=0`, `language=''`; variante: linha filha `parentid=<id>`. */
    public const TABLE_KNOWLEDGEBASE = 'tblknowledgebase';

    /** Fase 4 (categoria de KB). Linha original: `catid=0`, `language=''`; variante: linha filha `catid=<id>`. */
    public const TABLE_KNOWLEDGEBASE_CATS = 'tblknowledgebasecats';

    public const CAPABILITY_EMAIL_TEMPLATES = 'email_templates';

    public const CAPABILITY_DYNAMIC = 'dynamic_translations';

    /** Fase 3 — capacidade ISOLADA por kind: tabela ausente de um kind não derruba os demais. */
    public const CAPABILITY_DYNAMIC_CUSTOM_FIELD = 'dynamic_translations_custom_field';

    public const CAPABILITY_DYNAMIC_PRODUCT_ADDON = 'dynamic_translations_product_addon';

    public const CAPABILITY_DYNAMIC_TICKET_DEPARTMENT = 'dynamic_translations_ticket_department';

    /** Fase 4 — capacidade ISOLADA por tabela: uma tabela ausente não derruba as demais. */
    public const CAPABILITY_ANNOUNCEMENT = 'announcement';

    public const CAPABILITY_KB_ARTICLE = 'kb_article';

    public const CAPABILITY_KB_CATEGORY = 'kb_category';

    private const DYNAMIC_TRANSLATIONS_COLUMNS = [
        'id', 'related_type', 'related_id', 'language', 'translation', 'input_type',
    ];

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
            self::TABLE_DYNAMIC_TRANSLATIONS => self::DYNAMIC_TRANSLATIONS_COLUMNS,
            self::TABLE_PRODUCTS => [
                'id', 'gid', 'name', 'description', 'tagline', 'short_description', 'hidden', 'retired',
            ],
            self::TABLE_PRODUCT_GROUPS => [
                'id', 'name', 'headline', 'tagline', 'hidden',
            ],
        ],
        self::CAPABILITY_DYNAMIC_CUSTOM_FIELD => [
            self::TABLE_DYNAMIC_TRANSLATIONS => self::DYNAMIC_TRANSLATIONS_COLUMNS,
            self::TABLE_CUSTOM_FIELDS => [
                'id', 'type', 'relid', 'fieldname', 'description', 'adminonly',
            ],
        ],
        self::CAPABILITY_DYNAMIC_PRODUCT_ADDON => [
            self::TABLE_DYNAMIC_TRANSLATIONS => self::DYNAMIC_TRANSLATIONS_COLUMNS,
            self::TABLE_PRODUCT_ADDONS => [
                'id', 'name', 'description', 'hidden',
            ],
        ],
        self::CAPABILITY_DYNAMIC_TICKET_DEPARTMENT => [
            self::TABLE_DYNAMIC_TRANSLATIONS => self::DYNAMIC_TRANSLATIONS_COLUMNS,
            self::TABLE_TICKET_DEPARTMENTS => [
                'id', 'name', 'description', 'hidden',
            ],
        ],
        self::CAPABILITY_ANNOUNCEMENT => [
            self::TABLE_ANNOUNCEMENTS => [
                'id', 'date', 'title', 'announcement', 'published', 'parentid', 'language',
            ],
        ],
        self::CAPABILITY_KB_ARTICLE => [
            self::TABLE_KNOWLEDGEBASE => [
                'id', 'title', 'article', 'views', 'votes', 'useful', 'private', 'order', 'parentid', 'language',
            ],
        ],
        self::CAPABILITY_KB_CATEGORY => [
            self::TABLE_KNOWLEDGEBASE_CATS => [
                'id', 'parentid', 'name', 'description', 'hidden', 'catid', 'language',
            ],
        ],
    ];

    /** @return array<string, array<int, string>> tabela => colunas exigidas */
    public static function requirementsFor(string $capability): array
    {
        return self::REQUIREMENTS[$capability] ?? [];
    }
}
