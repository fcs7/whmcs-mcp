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
     * Página das varreduras COMPLETAS (catálogos, valores de custom field).
     *
     * A revisão fria do CRM-2 reprovou o desenho anterior, em que este número
     * era um TETO: com 101 entradas ativas, a de número 101 simplesmente
     * sumia do catálogo publicado — e `get_kanban` é a fonte dos IDs que as
     * escritas de CRM-3 vão exigir. Agora ele é só o tamanho do chunk; a
     * varredura pagina até o total exato.
     */
    public const CHUNK_SIZE = self::MAX_LIMIT;

    /**
     * Tamanho máximo de uma lista `IN`.
     *
     * Resolver definições/labels em lote precisa de `IN`, e uma lista sem teto
     * viraria uma query gigante. As listas são quebradas neste tamanho; o
     * número de lotes é limitado pelo total já contado.
     */
    public const MAX_IN_VALUES = self::MAX_LIMIT;

    /**
     * Tetos de SEGURANÇA das varreduras completas.
     *
     * Não truncam: excedê-los é falha FECHADA (`downstream` de integridade).
     * O contrato exige completude comprovável, então "li só uma parte" não é um
     * desfecho aceitável — ou a leitura é completa, ou ela falha dizendo que
     * não conseguiu prová-la.
     */
    public const MAX_CATALOG_TOTAL = 5000;
    public const MAX_CUSTOM_FIELD_VALUES = 5000;

    /**
     * Teto de RAIAS materializadas por request (D14).
     *
     * O catálogo de status continua completo na resposta; o que é paginado são
     * as raias. Sem isto, 5.000 statuses ativos produziam ~10.000 consultas e a
     * mesma quantidade de gravações no Activity Log numa única READ — o teto de
     * volume virava multiplicador de I/O. Com `status_limit` em 1..25, o custo
     * de raias por request é no máximo 25 COUNTs e 25 SELECTs de itens.
     */
    public const MAX_STATUS_LIMIT = 25;

    /**
     * Conjunto MÍNIMO exigido por capacidade — e cada capacidade corresponde ao
     * que UMA operação consulta, não ao que uma tabela oferece.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    // PHP 8.1 compat: `Enum->value` em expressão constante só existe a partir do 8.2;
    // as chaves são os valores literais dos cases (verificados por CrmSchemaGuardTest).
    private const REQUIREMENTS = [
        'resource_identity' /* CrmCapability::ResourceIdentity */ => [
            self::TABLE_RESOURCES => ['id', 'deleted_at'],
        ],
        'resource_core' /* CrmCapability::ResourceCore */ => [
            self::TABLE_RESOURCES => [
                'id', 'type_id', 'status_id', 'name', 'lastname', 'email', 'phone',
                'country', 'short_description', 'description',
                'created_at', 'updated_at', 'deleted_at',
            ],
        ],
        'resource_assignment' /* CrmCapability::ResourceAssignment */ => [
            self::TABLE_RESOURCES => ['id', 'admin_id'],
        ],
        'resource_types' /* CrmCapability::ResourceTypes */ => [
            self::TABLE_RESOURCE_TYPES => ['id', 'name', 'active', 'deleted_at'],
        ],
        'resource_statuses' /* CrmCapability::ResourceStatuses */ => [
            self::TABLE_RESOURCE_STATUSES => ['id', 'name', 'active', 'deleted_at'],
        ],
        'followups' /* CrmCapability::Followups */ => [
            self::TABLE_FOLLOWUPS => [
                'id', 'resource_id', 'type_id', 'status_id', 'admin_id',
                'description', 'date', 'created_at', 'updated_at', 'deleted_at',
            ],
        ],
        'followups_read' /* CrmCapability::FollowupsRead */ => [
            self::TABLE_FOLLOWUPS => [
                'id', 'resource_id', 'type_id', 'status_id',
                'description', 'date', 'created_at', 'updated_at', 'deleted_at',
            ],
        ],
        'followup_types' /* CrmCapability::FollowupTypes */ => [
            self::TABLE_FOLLOWUP_TYPES => ['id', 'name', 'active', 'deleted_at'],
        ],
        'followup_statuses' /* CrmCapability::FollowupStatuses */ => [
            self::TABLE_FOLLOWUP_STATUSES => ['id', 'name', 'active', 'deleted_at'],
        ],
        'followup_type_labels' /* CrmCapability::FollowupTypeLabels */ => [
            self::TABLE_FOLLOWUP_TYPES => ['id', 'name', 'deleted_at'],
        ],
        'followup_status_labels' /* CrmCapability::FollowupStatusLabels */ => [
            self::TABLE_FOLLOWUP_STATUSES => ['id', 'name', 'deleted_at'],
        ],
        'notes' /* CrmCapability::Notes */ => [
            self::TABLE_NOTES => [
                'id', 'resource_id', 'admin_id', 'content',
                'created_at', 'updated_at', 'deleted_at',
            ],
        ],
        'custom_fields' /* CrmCapability::CustomFields */ => [
            self::TABLE_FIELDS => ['id', 'name', 'deleted_at'],
            self::TABLE_FIELD_VALUES => ['id', 'field_id', 'resource_id', 'value'],
        ],
        'admin_identity' /* CrmCapability::AdminIdentity */ => [
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

    /**
     * Colunas cujo tipo físico é INTEIRO — ids, chaves estrangeiras e flags.
     *
     * Existe porque ordenação depende do TIPO da coluna, não da aparência do
     * valor. A revisão fria reprovou um comparador que decidia pelo conteúdo:
     * um catálogo com `name` VARCHAR valendo `"9"` e `"10"` ordenava
     * numericamente no dublê e lexicalmente no MySQL. Aqui a decisão vem do
     * schema, então `name` é sempre texto mesmo quando parece número.
     *
     * @var array<int, string>
     */
    private const INTEGER_COLUMNS = [
        self::COLUMN_ID,
        self::COLUMN_RESOURCE_ID,
        self::COLUMN_TYPE_ID,
        self::COLUMN_STATUS_ID,
        self::COLUMN_ADMIN_ID,
        self::COLUMN_FIELD_ID,
        self::COLUMN_ACTIVE,
        'disabled',
    ];

    public static function isIntegerColumn(string $column): bool
    {
        return in_array($column, self::INTEGER_COLUMNS, true);
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

    /**
     * Projeção PÚBLICA de follow-up: a de escrita menos `admin_id`.
     *
     * O id interno do staff não atravessa a superfície MCP. Ele não é dado do
     * chamador, não é acionável por ele e é exatamente o tipo de identificador
     * interno que o contrato de erros/sinks mantém fora do que é publicado.
     *
     * @return array<int, string>
     */
    public static function followupReadProjection(): array
    {
        return [
            'id', 'resource_id', 'type_id', 'status_id',
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

    /**
     * Valor de custom field: o vínculo e o conteúdo, nada da configuração.
     *
     * `crm_fields` guarda a DEFINIÇÃO (nome, e na instalação real também
     * validators, opções e visibilidade). Só `id` e `name` são contratados, e
     * só eles são projetados — o normalizado que chega ao chamador é
     * `{field_id, name, value}`. Validator e configuração interna do mgCRM2 não
     * têm caminho até a resposta porque não têm caminho até a query.
     *
     * @return array<int, string>
     */
    public static function fieldValueProjection(): array
    {
        return ['id', 'field_id', 'value'];
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

    /** @return array<int, array{0:string,1:string}> */
    public static function fieldValueOrder(): array
    {
        return [['field_id', 'asc'], ['id', 'asc']];
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
