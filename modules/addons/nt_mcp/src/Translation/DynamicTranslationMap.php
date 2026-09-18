<?php

declare(strict_types=1);

namespace NtMcp\Translation;

/**
 * Catálogo FECHADO de "kinds" traduzíveis via `tbldynamic_translations`
 * (Fase 2: produto e grupo de produto — Fase 3 só acrescenta entradas aqui).
 *
 * Nenhuma tool ou repositório aceita nome de tabela/coluna/kind/field vindo de
 * fora sem passar por este mapa: `DynamicTranslationRepository` valida todo
 * `kind`/`field` contra ele antes de qualquer consulta.
 *
 * FATO CONFIRMADO no desenv: `related_type` é o texto LITERAL com a string
 * `{id}` (nunca o id numérico substituído) — ex.: `product.{id}.name`. O id
 * real fica na coluna `related_id`. `relatedType()` reflete isso: o mesmo
 * literal serve para qualquer id daquele kind+field.
 */
final class DynamicTranslationMap
{
    public const KIND_PRODUCT = 'product';

    public const KIND_PRODUCT_GROUP = 'product_group';

    /**
     * kind => [source_table, fields => field => input_type].
     *
     * @var array<string, array{source_table: string, fields: array<string, string>}>
     */
    private const MAP = [
        self::KIND_PRODUCT => [
            'source_table' => TranslationSchema::TABLE_PRODUCTS,
            'fields' => [
                'name' => 'text',
                'description' => 'textarea',
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
     * Literal gravado em `related_type` — contém a string `{id}` tal como
     * está, NUNCA o id numérico. O id real é `related_id`, coluna separada.
     */
    public static function relatedType(string $kind, string $field): string
    {
        return sprintf('%s.{id}.%s', $kind, $field);
    }
}
