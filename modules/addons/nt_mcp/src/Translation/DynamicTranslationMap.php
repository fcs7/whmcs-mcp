<?php

declare(strict_types=1);

namespace NtMcp\Translation;

/**
 * Catálogo FECHADO de "kinds" traduzíveis via `tbldynamic_translations`
 * (Fase 2: produto e grupo de produto; Fase 3: custom field, addon de
 * produto e departamento de suporte).
 *
 * Nenhuma tool ou repositório aceita nome de tabela/coluna/kind/field vindo de
 * fora sem passar por este mapa: `DynamicTranslationRepository` valida todo
 * `kind`/`field` contra ele antes de qualquer consulta.
 *
 * FATO CONFIRMADO no desenv: `related_type` é o texto LITERAL com a string
 * `{id}` (nunca o id numérico substituído) — ex.: `product.{id}.name`. O id
 * real fica na coluna `related_id`. `relatedType()` reflete isso: o mesmo
 * literal serve para qualquer id daquele kind+field.
 *
 * CONFIRMADO AO VIVO no desenv (status real de `tbldynamic_translations`,
 * por `related_type`): `product.{id}.name`, `product.{id}.description`,
 * `product.{id}.tagline`, `product.{id}.short_description`,
 * `product_group.{id}.name`/`headline`/`tagline`, `custom_field.{id}.name`,
 * `custom_field.{id}.description`, `product_addon.{id}.name` e
 * `product_addon.{id}.description` — todos com linhas reais no banco.
 *
 * PENDENTE DE CONFIRMAÇÃO AO VIVO: só `ticket_department.{id}.*` (Fase 3)
 * segue sem nenhuma linha em `tbldynamic_translations` até o momento — segue
 * a MESMA convenção dos demais kinds, mas isso ainda não foi observado no
 * banco real. `whmcs_translation_status` (via
 * `TranslationStatusReader::dynamicTranslationCounts()`) lista os
 * `related_type` que já existem de fato e serve para essa confirmação.
 */
final class DynamicTranslationMap
{
    public const KIND_PRODUCT = 'product';

    public const KIND_PRODUCT_GROUP = 'product_group';

    public const KIND_CUSTOM_FIELD = 'custom_field';

    public const KIND_PRODUCT_ADDON = 'product_addon';

    public const KIND_TICKET_DEPARTMENT = 'ticket_department';

    /**
     * kind => [source_table, fields => public_field => input_type, source_columns => public_field => db_column].
     *
     * `source_columns` só precisa de uma entrada quando o nome público
     * (o que aparece no `field` da tool e no literal `related_type`) diverge
     * da coluna real da tabela fonte — hoje só `custom_field.name`, que lê
     * `fieldname`. Quando ausente, o nome público E a coluna fonte são o
     * mesmo texto.
     *
     * @var array<string, array{source_table: string, fields: array<string, string>, source_columns?: array<string, string>}>
     */
    private const MAP = [
        self::KIND_PRODUCT => [
            'source_table' => TranslationSchema::TABLE_PRODUCTS,
            'fields' => [
                'name' => 'text',
                'description' => 'textarea',
                'tagline' => 'text',
                'short_description' => 'text',
            ],
        ],
        self::KIND_PRODUCT_GROUP => [
            'source_table' => TranslationSchema::TABLE_PRODUCT_GROUPS,
            'fields' => [
                'name' => 'text',
                'headline' => 'text',
                'tagline' => 'text',
            ],
        ],
        self::KIND_CUSTOM_FIELD => [
            'source_table' => TranslationSchema::TABLE_CUSTOM_FIELDS,
            'fields' => [
                'name' => 'text',
                'description' => 'text',
            ],
            // Campo público 'name' é a convenção do literal (`custom_field.{id}.name`);
            // a coluna real de `tblcustomfields` é `fieldname`, não `name`.
            'source_columns' => [
                'name' => 'fieldname',
            ],
        ],
        self::KIND_PRODUCT_ADDON => [
            'source_table' => TranslationSchema::TABLE_PRODUCT_ADDONS,
            'fields' => [
                'name' => 'text',
                'description' => 'textarea',
            ],
        ],
        self::KIND_TICKET_DEPARTMENT => [
            'source_table' => TranslationSchema::TABLE_TICKET_DEPARTMENTS,
            'fields' => [
                'name' => 'text',
                'description' => 'text',
            ],
        ],
    ];

    /**
     * kind => capacidade exigida do `TranslationSchemaGuard`. Cada kind novo
     * (Fase 3) tem a SUA PRÓPRIA capacidade — uma tabela ausente derruba só o
     * kind dela, nunca os demais (ver `TranslationSchema::REQUIREMENTS`).
     *
     * @var array<string, string>
     */
    private const CAPABILITY_BY_KIND = [
        self::KIND_PRODUCT => TranslationSchema::CAPABILITY_DYNAMIC,
        self::KIND_PRODUCT_GROUP => TranslationSchema::CAPABILITY_DYNAMIC,
        self::KIND_CUSTOM_FIELD => TranslationSchema::CAPABILITY_DYNAMIC_CUSTOM_FIELD,
        self::KIND_PRODUCT_ADDON => TranslationSchema::CAPABILITY_DYNAMIC_PRODUCT_ADDON,
        self::KIND_TICKET_DEPARTMENT => TranslationSchema::CAPABILITY_DYNAMIC_TICKET_DEPARTMENT,
    ];

    /** @return array<int, string> */
    public static function kinds(): array
    {
        return array_keys(self::MAP);
    }

    public static function isValidKind(string $kind): bool
    {
        return array_key_exists($kind, self::MAP);
    }

    public static function sourceTable(string $kind): string
    {
        return self::MAP[$kind]['source_table']
            ?? throw new \InvalidArgumentException("DynamicTranslationMap: unknown kind '{$kind}'.");
    }

    /** @return array<string, string> field => input_type ('text'|'textarea') */
    public static function fields(string $kind): array
    {
        return self::MAP[$kind]['fields'] ?? [];
    }

    public static function isValidField(string $kind, string $field): bool
    {
        return array_key_exists($field, self::fields($kind));
    }

    public static function inputType(string $kind, string $field): string
    {
        return self::fields($kind)[$field]
            ?? throw new \InvalidArgumentException("DynamicTranslationMap: unknown field '{$field}' for kind '{$kind}'.");
    }

    /**
     * Coluna REAL da tabela fonte para o campo público `$field`. Igual ao
     * nome público, exceto quando `source_columns` do kind diz o contrário
     * (hoje só `custom_field.name` -> `fieldname`).
     */
    public static function sourceColumn(string $kind, string $field): string
    {
        if (!self::isValidField($kind, $field)) {
            throw new \InvalidArgumentException("DynamicTranslationMap: unknown field '{$field}' for kind '{$kind}'.");
        }

        return self::MAP[$kind]['source_columns'][$field] ?? $field;
    }

    /** Capacidade do `TranslationSchemaGuard` exigida para operar neste kind. */
    public static function capabilityFor(string $kind): string
    {
        return self::CAPABILITY_BY_KIND[$kind]
            ?? throw new \InvalidArgumentException("DynamicTranslationMap: unknown kind '{$kind}'.");
    }

    /**
     * Literal gravado em `related_type` — contém a string `{id}` tal como
     * está, NUNCA o id numérico. O id real é `related_id`, coluna separada.
     */
    public static function relatedType(string $kind, string $field): string
    {
        return sprintf('%s.{id}.%s', $kind, $field);
    }
}
