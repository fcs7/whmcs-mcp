<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Catálogo FECHADO do domínio mgCRM2: tabelas, colunas, projeções, ordenações e
 * limites. É a única fonte de nomes físicos do CRM em todo o addon.
 *
 * Nada aqui é parametrizável. Não existe API que receba nome de tabela, coluna,
 * ordenação ou operador do chamador — `CrmSelect` só aceita o que estas
 * constantes declaram, e qualquer outro nome é recusado na construção da query,
 * antes de tocar o banco.
 *
 * Evidência e limite
 * ------------------
 * Os nomes vêm do DDL legível do mgCRM2 (`2.0.0` + updates até `2.11.0`)
 * inspecionado em dev. O DDL empacotado NÃO prova o estado físico do MySQL da
 * instalação, então cada capacidade é verificada em runtime pelo
 * `CrmSchemaGuard` antes da primeira consulta operacional. As tabelas fictícias
 * do contrato antigo não aparecem aqui e não existe fallback para elas — a
 * varredura mecânica da suíte prova que aqueles nomes sumiram do código novo.
 *
 * Colunas OPCIONAIS
 * -----------------
 * `active` nos quatro catálogos é o único caso: a evidência prova o
 * soft-delete (`deleted_at`) mas não prova o nome da coluna de atividade. O
 * guard detecta a coluna por metadata; quando ela existe, o filtro `active = 1`
 * é aplicado e é obrigatório. Quando não existe, "ativo" significa "não
 * soft-deleted". Em nenhuma das duas leituras uma linha inativa/apagada
 * atravessa — é por isso que a opcionalidade não é um relaxamento de gate.
 */
final class CrmSchema
{
    // ---------------------------------------------------------------
    // Tabelas
    // ---------------------------------------------------------------
    public const TABLE_RESOURCES = 'crm_resources';
    public const TABLE_RESOURCE_TYPES = 'crm_resources_types';
    public const TABLE_RESOURCE_STATUSES = 'crm_resources_statuses';
    public const TABLE_FOLLOWUPS = 'crm_followups';
    public const TABLE_FOLLOWUP_TYPES = 'crm_followup_types';
    public const TABLE_FOLLOWUP_STATUSES = 'crm_followup_statuses';
    public const TABLE_NOTES = 'crm_notes';
    public const TABLE_FIELDS = 'crm_fields';
    public const TABLE_FIELD_VALUES = 'crm_fields_values';
    public const TABLE_ADMINS = 'tbladmins';

    // ---------------------------------------------------------------
    // Colunas recorrentes
    // ---------------------------------------------------------------
    public const COLUMN_ID = 'id';
    public const COLUMN_RESOURCE_ID = 'resource_id';
    public const COLUMN_TYPE_ID = 'type_id';
    public const COLUMN_STATUS_ID = 'status_id';
    public const COLUMN_ADMIN_ID = 'admin_id';
    public const COLUMN_FIELD_ID = 'field_id';
    public const COLUMN_NAME = 'name';
    public const COLUMN_ACTIVE = 'active';
    public const COLUMN_DELETED_AT = 'deleted_at';
    public const COLUMN_CREATED_AT = 'created_at';
    public const COLUMN_UPDATED_AT = 'updated_at';

    // ---------------------------------------------------------------
    // Limites compartilhados
    // ---------------------------------------------------------------
    public const DEFAULT_LIMIT = 25;
    public const MAX_LIMIT = 100;
    public const MAX_LIMIT_PER_STATUS = 25;
    public const MAX_OFFSET = 100000;

    /**
     * Conjunto MÍNIMO exigido por capacidade. O guard prova tabela a tabela e
     * coluna a coluna antes de qualquer consulta operacional.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const REQUIREMENTS = [
        CrmCapability::Resources->value => [
            self::TABLE_RESOURCES => [
                'id', 'type_id', 'status_id', 'name', 'lastname', 'email', 'phone',
                'country', 'short_description', 'description',
                'created_at', 'updated_at', 'deleted_at',
            ],
        ],
        CrmCapability::ResourceAssignment->value => [
            self::TABLE_RESOURCES => ['id', 'admin_id'],
        ],
        CrmCapability::ResourceCatalogs->value => [
            self::TABLE_RESOURCE_TYPES => ['id', 'name', 'deleted_at'],
            self::TABLE_RESOURCE_STATUSES => ['id', 'name', 'deleted_at'],
        ],
        CrmCapability::Followups->value => [
            self::TABLE_FOLLOWUPS => [
                'id', 'resource_id', 'type_id', 'status_id', 'admin_id',
                'description', 'date', 'created_at', 'updated_at', 'deleted_at',
            ],
        ],
        CrmCapability::FollowupCatalogs->value => [
            self::TABLE_FOLLOWUP_TYPES => ['id', 'name', 'deleted_at'],
            self::TABLE_FOLLOWUP_STATUSES => ['id', 'name', 'deleted_at'],
        ],
        CrmCapability::Notes->value => [
            self::TABLE_NOTES => [
                'id', 'resource_id', 'admin_id', 'content',
                'created_at', 'updated_at', 'deleted_at',
            ],
        ],
        CrmCapability::CustomFields->value => [
            self::TABLE_FIELDS => ['id', 'name', 'deleted_at'],
            self::TABLE_FIELD_VALUES => ['id', 'field_id', 'resource_id', 'value'],
        ],
        CrmCapability::AdminIdentity->value => [
            self::TABLE_ADMINS => ['id', 'username', 'disabled'],
        ],
    ];

    /**
     * Colunas cuja PRESENÇA é detectada, mas cuja ausência não invalida a
     * capacidade. Ver a nota de topo: quando presente, `active` é filtrado.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const OPTIONAL_COLUMNS = [
        CrmCapability::ResourceCatalogs->value => [
            self::TABLE_RESOURCE_TYPES => ['active'],
            self::TABLE_RESOURCE_STATUSES => ['active'],
        ],
        CrmCapability::FollowupCatalogs->value => [
            self::TABLE_FOLLOWUP_TYPES => ['active'],
            self::TABLE_FOLLOWUP_STATUSES => ['active'],
        ],
    ];

    /**
     * Colunas conhecidas por tabela — o universo que `CrmSelect` aceita.
     * Derivado das duas tabelas acima para que não exista uma terceira lista a
     * manter em sincronia.
     *
     * @return array<int, string>
     */
    public static function columnsOf(string $table): array
    {
        static $index = null;

        if ($index === null) {
            $index = [];
            foreach ([self::REQUIREMENTS, self::OPTIONAL_COLUMNS] as $source) {
                foreach ($source as $tables) {
                    foreach ($tables as $name => $columns) {
                        $index[$name] = array_values(array_unique(
                            array_merge($index[$name] ?? [], $columns)
                        ));
                    }
                }
            }
        }

        return $index[$table] ?? [];
    }

    public static function isKnownTable(string $table): bool
    {
        return self::columnsOf($table) !== [];
    }

    /** @return array<string, array<int, string>> tabela => colunas exigidas */
    public static function requirementsFor(CrmCapability $capability): array
    {
        return self::REQUIREMENTS[$capability->value] ?? [];
    }

    /** @return array<string, array<int, string>> tabela => colunas opcionais */
    public static function optionalColumnsFor(CrmCapability $capability): array
    {
        return self::OPTIONAL_COLUMNS[$capability->value] ?? [];
    }

    // ---------------------------------------------------------------
    // Projeções explícitas — nunca `*`
    // ---------------------------------------------------------------

    /** @return array<int, string> */
    public static function resourceProjection(): array
    {
        return [
            'id', 'type_id', 'status_id', 'name', 'lastname', 'email', 'phone',
            'country', 'short_description', 'description', 'created_at', 'updated_at',
        ];
    }

    /** @return array<int, string> */
    public static function followupProjection(): array
    {
        return [
            'id', 'resource_id', 'type_id', 'status_id', 'admin_id',
            'description', 'date', 'created_at', 'updated_at',
        ];
    }

    /** @return array<int, string> */
    public static function noteProjection(): array
    {
        return ['id', 'resource_id', 'admin_id', 'content', 'created_at', 'updated_at'];
    }

    /** @return array<int, string> */
    public static function catalogProjection(): array
    {
        return ['id', 'name'];
    }

    // ---------------------------------------------------------------
    // Ordenações determinísticas
    //
    // Toda ordenação termina em `id`, que é único: sem desempate a paginação
    // por offset pode repetir ou pular linhas entre páginas quando duas linhas
    // compartilham a chave de ordenação.
    // ---------------------------------------------------------------

    /** @return array<int, array{0:string,1:string}> */
    public static function resourceOrder(): array
    {
        return [['id', 'desc']];
    }

    /** @return array<int, array{0:string,1:string}> */
    public static function followupOrder(): array
    {
        return [['date', 'asc'], ['id', 'asc']];
    }

    /** @return array<int, array{0:string,1:string}> */
    public static function noteOrder(): array
    {
        return [['id', 'desc']];
    }

    /** @return array<int, array{0:string,1:string}> */
    public static function catalogOrder(): array
    {
        return [['name', 'asc'], ['id', 'asc']];
    }

    // ---------------------------------------------------------------
    // Clamps
    // ---------------------------------------------------------------

    public static function clampLimit(int $limit, int $max = self::MAX_LIMIT): int
    {
        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, $max);
    }

    public static function clampOffset(int $offset): int
    {
        return min(max($offset, 0), self::MAX_OFFSET);
    }
}
