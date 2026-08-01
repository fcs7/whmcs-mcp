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
 * Atividade de catálogo é EXIGIDA, não inferida
 * ---------------------------------------------
 * A versão anterior tratava `active` como coluna opcional e, na ausência dela,
 * equiparava "não soft-deleted" a "ativo". Isso é fail-open: o contrato exige
 * tipo/status existente **e ativo**, e sem prova da semântica de atividade a
 * segunda metade da exigência simplesmente desaparecia. Agora `active` é
 * requisito mínimo de cada catálogo; instalação sem ela responde
 * `crm_schema_mismatch` até o T6 provar qual é a regra física real. Nenhuma
 * degradação silenciosa.
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
     * Conjunto MÍNIMO exigido por capacidade — e cada capacidade corresponde ao
     * que UMA operação consulta, não ao que uma tabela oferece.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const REQUIREMENTS = [
        CrmCapability::ResourceIdentity->value => [
            self::TABLE_RESOURCES => ['id', 'deleted_at'],
        ],
        CrmCapability::ResourceCore->value => [
            self::TABLE_RESOURCES => [
                'id', 'type_id', 'status_id', 'name', 'lastname', 'email', 'phone',
                'country', 'short_description', 'description',
                'created_at', 'updated_at', 'deleted_at',
            ],
        ],
        CrmCapability::ResourceAssignment->value => [
            self::TABLE_RESOURCES => ['id', 'admin_id'],
        ],
        CrmCapability::ResourceTypes->value => [
            self::TABLE_RESOURCE_TYPES => ['id', 'name', 'active', 'deleted_at'],
        ],
        CrmCapability::ResourceStatuses->value => [
            self::TABLE_RESOURCE_STATUSES => ['id', 'name', 'active', 'deleted_at'],
        ],
        CrmCapability::Followups->value => [
            self::TABLE_FOLLOWUPS => [
                'id', 'resource_id', 'type_id', 'status_id', 'admin_id',
                'description', 'date', 'created_at', 'updated_at', 'deleted_at',
            ],
        ],
        CrmCapability::FollowupTypes->value => [
            self::TABLE_FOLLOWUP_TYPES => ['id', 'name', 'active', 'deleted_at'],
        ],
        CrmCapability::FollowupStatuses->value => [
            self::TABLE_FOLLOWUP_STATUSES => ['id', 'name', 'active', 'deleted_at'],
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
     * Colunas conhecidas por tabela — o universo que `CrmSelect` aceita.
     * Derivado dos requisitos para que não exista uma segunda lista a manter em
     * sincronia.
     *
     * @return array<int, string>
     */
    public static function columnsOf(string $table): array
    {
        static $index = null;

        if ($index === null) {
            $index = [];
            foreach (self::REQUIREMENTS as $tables) {
                foreach ($tables as $name => $columns) {
                    $index[$name] = array_values(array_unique(
                        array_merge($index[$name] ?? [], $columns)
                    ));
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
        $ceiling = min(max($max, 1), self::MAX_LIMIT);

        if ($limit < 1) {
            return min(self::DEFAULT_LIMIT, $ceiling);
        }

        return min($limit, $ceiling);
    }

    public static function clampOffset(int $offset): int
    {
        return min(max($offset, 0), self::MAX_OFFSET);
    }
}
