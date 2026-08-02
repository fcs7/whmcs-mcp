<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Fronteira ÚNICA entre as tools de CRM e o domínio real do mgCRM2.
 *
 * Este ticket (CRM-1) entrega apenas as BARREIRAS: schema, recurso, catálogos,
 * identidade administrativa, instante e limites. As oito tools continuam
 * exatamente como estavam — a migração das leituras é CRM-2 e a das escritas é
 * CRM-3. Nada aqui está ligado a `CrmTools`, ao adapter ou ao registry.
 *
 * NÃO EXISTE ESCRITA nesta tranche — nem aqui, nem no port, nem em nenhum
 * value object. A revisão fria mostrou que oferecer um caminho de escrita
 * "pronto para CRM-3" transformava o invariante "`admin_id` sempre vem do
 * OAuth" numa promessa de disciplina futura: era possível forjar o contexto de
 * autoria e chamar o executor direto. Sem executor gravável, não há o que
 * contornar. CRM-3 introduz escrita por métodos DESTE objeto, que recebem o
 * username OAuth e dados sem `admin_id`.
 *
 * Invariantes que a classe sustenta, e que os testes deste ticket exercitam:
 *
 *  - nenhum método público aceita nome de tabela, coluna, ordenação ou operador;
 *  - toda leitura passa por `CrmSelect`, que recusa nome fora do contrato;
 *  - cada operação prova SOMENTE as tabelas/colunas que a sua query usa;
 *  - nenhuma consulta operacional acontece antes do schema guard da capacidade;
 *  - linha soft-deleted nunca é vista, em nenhuma das leituras;
 *  - catálogo só é aceito com atividade PROVADA, nunca inferida do soft-delete;
 *  - falha de schema, catálogo, recurso, data ou admin não produz efeito algum.
 */
final class MgCrmRepository
{
    public function __construct(
        private readonly CrmSchemaGuard $guard,
        private readonly CrmQueryPort $port,
        private readonly AdminIdentityResolver $admins,
    ) {
    }

    // ---------------------------------------------------------------
    // Disponibilidade
    // ---------------------------------------------------------------

    /**
     * Exige explicitamente as capacidades de uma operação composta (o kanban do
     * CRM-2 é o caso real: lê catálogos e recursos na mesma resposta).
     *
     * @throws CrmException `crm_unavailable`, `crm_schema_mismatch`, `downstream`
     */
    public function assertCapabilities(CrmCapability ...$capabilities): void
    {
        if ($capabilities === []) {
            // Um gate que aceita lista vazia não é um gate.
            throw new \LogicException('MgCrmRepository: at least one capability must be asserted.');
        }

        $this->guard->assertAll(...$capabilities);
    }

    // ---------------------------------------------------------------
    // Recurso
    // ---------------------------------------------------------------

    /**
     * Prova que o recurso existe e não está soft-deleted, devolvendo o id
     * canônico. Toda operação que cita um `resource_id` começa por aqui.
     *
     * Exige apenas `ResourceIdentity` — `id` e `deleted_at`, que é exatamente o
     * que a query usa. Um drift em `short_description` não pode derrubar a
     * pré-condição de nota e follow-up.
     *
     * @throws CrmException `validation`, `crm_resource_not_found`
     */
    public function requireResource(int $resourceId): int
    {
        $this->assertPositiveId($resourceId, 'resource_id');
        $this->assertCapabilities(CrmCapability::ResourceIdentity);

        $rows = $this->port->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_RESOURCES,
            columns: [CrmSchema::COLUMN_ID],
            conditions: [CrmSchema::COLUMN_ID => $resourceId],
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            limit: 1,
        ));

        if ($rows === []) {
            throw CrmException::resourceNotFound();
        }

        return $resourceId;
    }

    // ---------------------------------------------------------------
    // Catálogos
    // ---------------------------------------------------------------

    /**
     * Prova que o id pertence ao catálogo pedido, está ATIVO e não foi
     * soft-deleted. Um id válido no catálogo ERRADO também falha: o catálogo é
     * escolhido pelo chamador do repositório, não inferido do número.
     *
     * Exige apenas a capacidade DAQUELE catálogo. E a capacidade inclui a
     * coluna de atividade: sem ela o guard responde `crm_schema_mismatch` antes
     * desta query, em vez de degradar "ativo" para "não deletado".
     *
     * @throws CrmException `validation`, `crm_catalog_invalid`
     */
    public function requireCatalogEntry(CrmCatalog $catalog, int $id): int
    {
        $this->assertPositiveId($id, $catalog->foreignKey());
        $this->assertCapabilities($catalog->capability());

        $rows = $this->port->selectRows(new CrmSelect(
            table: $catalog->table(),
            columns: [CrmSchema::COLUMN_ID],
            conditions: [
                CrmSchema::COLUMN_ID => $id,
                CrmSchema::COLUMN_ACTIVE => 1,
            ],
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            limit: 1,
        ));

        if ($rows === []) {
            throw CrmException::catalogInvalid($catalog);
        }

        return $id;
    }

    // ---------------------------------------------------------------
    // Leituras públicas (CRM-2)
    //
    // As quatro devolvem estrutura PRONTA para serialização: chaves nossas,
    // tipos normalizados e nenhuma coluna crua do driver. A tool serializa e
    // não interpreta — assim a forma da resposta é decidida em um lugar só, e
    // um drift de coluna não vaza para o contrato público.
    //
    // Nenhuma delas toca `CrmWriteGate`, `admin_id`, `created_at`/`updated_at`
    // do mgCRM2 ou qualquer log do fornecedor: são SELECTs e COUNTs, e o único
    // sink é o Activity Log já existente do port.
    // ---------------------------------------------------------------

    /**
     * Recursos paginados, com filtros OPCIONAIS por tipo e status.
     *
     * Um filtro informado é validado contra o catálogo ATIVO antes de virar
     * condição. Isso é deliberado: `type_id` de um tipo desativado devolveria
     * uma lista vazia indistinguível de "não há recursos desse tipo", e o
     * chamador corrigiria o lugar errado. Com a validação, ele recebe
     * `crm_catalog_invalid` e sabe reler os catálogos do kanban.
     *
     * @return array{items:array<int, array<string, mixed>>, count:int, limit:int, offset:int, has_more:bool}
     * @throws CrmException
     */
    public function listResources(?int $typeId, ?int $statusId, int $limit, int $offset): array
    {
        $limit = CrmSchema::clampLimit($limit);
        $offset = CrmSchema::clampOffset($offset);

        $this->assertCapabilities(CrmCapability::ResourceCore);

        $conditions = [];

        if ($typeId !== null) {
            $conditions[CrmSchema::COLUMN_TYPE_ID] =
                $this->requireCatalogEntry(CrmCatalog::ResourceType, $typeId);
        }

        if ($statusId !== null) {
            $conditions[CrmSchema::COLUMN_STATUS_ID] =
                $this->requireCatalogEntry(CrmCatalog::ResourceStatus, $statusId);
        }

        return $this->paginate(
            new CrmSelect(
                table: CrmSchema::TABLE_RESOURCES,
                columns: CrmSchema::resourceProjection(),
                conditions: $conditions,
                nullColumns: [CrmSchema::COLUMN_DELETED_AT],
                order: CrmSchema::resourceOrder(),
                limit: $limit,
                offset: $offset,
            ),
            static fn(array $row): array => self::projectResource($row),
        );
    }

    /**
     * Recurso único com os custom fields normalizados.
     *
     * A ordem das barreiras importa e é a mais específica primeiro: o recurso
     * ausente responde `crm_resource_not_found` sem depender do estado das
     * tabelas de custom field. Já um recurso EXISTENTE com `crm_fields*`
     * ausente ou incompatível falha fechado (`crm_unavailable` /
     * `crm_schema_mismatch`) em vez de responder `custom_fields: []` — a lista
     * vazia enganosa é indistinguível de "este recurso não tem campos" e o
     * contrato proíbe exatamente isso.
     *
     * @return array{resource:array<string, mixed>, custom_fields:array<int, array<string, mixed>>}
     * @throws CrmException
     */
    public function getResource(int $resourceId): array
    {
        $this->assertPositiveId($resourceId, 'resource_id');
        $this->assertCapabilities(CrmCapability::ResourceCore);

        $rows = $this->port->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_RESOURCES,
            columns: CrmSchema::resourceProjection(),
            conditions: [CrmSchema::COLUMN_ID => $resourceId],
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            limit: 1,
        ));

        if ($rows === []) {
            throw CrmException::resourceNotFound();
        }

        return [
            'resource' => self::projectResource($rows[0]),
            'custom_fields' => $this->readCustomFields($resourceId),
        ];
    }

    /**
     * Follow-ups de UM recurso, paginados, com tipo/status resolvidos em label.
     *
     * O recurso é provado primeiro: follow-ups de um `resource_id` inexistente
     * ou soft-deleted são `crm_resource_not_found`, não uma lista vazia.
     *
     * Os labels vêm dos catálogos ATIVOS, lidos uma única vez por chamada e
     * casados em PHP — dois SELECTs fixos, independentemente do tamanho da
     * página. Um follow-up que aponta para um tipo/status desativado ou
     * removido mantém o `*_id` e recebe `*_name: null`: o dado histórico não é
     * escondido, e o label não é inventado.
     *
     * @return array{items:array<int, array<string, mixed>>, count:int, limit:int, offset:int, has_more:bool}
     * @throws CrmException
     */
    public function listFollowups(int $resourceId, ?int $typeId, ?int $statusId, int $limit, int $offset): array
    {
        $limit = CrmSchema::clampLimit($limit);
        $offset = CrmSchema::clampOffset($offset);

        $conditions = [CrmSchema::COLUMN_RESOURCE_ID => $this->requireResource($resourceId)];

        $this->assertCapabilities(CrmCapability::FollowupsRead);

        if ($typeId !== null) {
            $conditions[CrmSchema::COLUMN_TYPE_ID] =
                $this->requireCatalogEntry(CrmCatalog::FollowupType, $typeId);
        }

        if ($statusId !== null) {
            $conditions[CrmSchema::COLUMN_STATUS_ID] =
                $this->requireCatalogEntry(CrmCatalog::FollowupStatus, $statusId);
        }

        $types = self::labelIndex($this->activeCatalog(CrmCatalog::FollowupType));
        $statuses = self::labelIndex($this->activeCatalog(CrmCatalog::FollowupStatus));

        return $this->paginate(
            new CrmSelect(
                table: CrmSchema::TABLE_FOLLOWUPS,
                columns: CrmSchema::followupReadProjection(),
                conditions: $conditions,
                nullColumns: [CrmSchema::COLUMN_DELETED_AT],
                order: CrmSchema::followupOrder(),
                limit: $limit,
                offset: $offset,
            ),
            static fn(array $row): array => self::projectFollowup($row, $types, $statuses),
        );
    }

    /**
     * Os quatro catálogos ativos mais as raias de recurso por status.
     *
     * Esta é a tool de METADATA da superfície: D11 decidiu não criar uma nona
     * tool, então é daqui que o chamador tira os `type_id`/`status_id` que as
     * escritas de CRM-3 vão exigir. Por isso os catálogos são publicados
     * SEMPRE, inclusive quando nenhuma raia tem recurso — um kanban vazio ainda
     * precisa dizer quais ids existem.
     *
     * Cada raia carrega o total EXATO (COUNT sob o mesmo filtro) e uma página
     * de itens limitada, nunca a raia inteira.
     *
     * @return array{type_id:int|null, limit_per_status:int, catalogs:array<string, array<int, array<string, mixed>>>, lanes:array<int, array<string, mixed>>, lanes_truncated:bool}
     * @throws CrmException
     */
    public function getKanban(?int $typeId, int $limitPerStatus): array
    {
        $limitPerStatus = CrmSchema::clampLimit($limitPerStatus, CrmSchema::MAX_LIMIT_PER_STATUS);

        $this->assertCapabilities(CrmCapability::ResourceCore);

        $filter = [];
        if ($typeId !== null) {
            $filter[CrmSchema::COLUMN_TYPE_ID] =
                $this->requireCatalogEntry(CrmCatalog::ResourceType, $typeId);
        }

        $catalogs = [
            'resource_types' => $this->activeCatalog(CrmCatalog::ResourceType),
            'resource_statuses' => $this->activeCatalog(CrmCatalog::ResourceStatus),
            'followup_types' => $this->activeCatalog(CrmCatalog::FollowupType),
            'followup_statuses' => $this->activeCatalog(CrmCatalog::FollowupStatus),
        ];

        $lanes = [];
        foreach (array_slice($catalogs['resource_statuses'], 0, CrmSchema::MAX_KANBAN_LANES) as $status) {
            $conditions = $filter;
            $conditions[CrmSchema::COLUMN_STATUS_ID] = $status['id'];

            $page = $this->paginate(
                new CrmSelect(
                    table: CrmSchema::TABLE_RESOURCES,
                    columns: CrmSchema::resourceProjection(),
                    conditions: $conditions,
                    nullColumns: [CrmSchema::COLUMN_DELETED_AT],
                    order: CrmSchema::resourceOrder(),
                    limit: $limitPerStatus,
                ),
                static fn(array $row): array => self::projectResource($row),
            );

            $lanes[] = [
                'status_id' => $status['id'],
                'status_name' => $status['name'],
                'total' => $page['count'],
                'items' => $page['items'],
                'has_more' => $page['has_more'],
            ];
        }

        return [
            'type_id' => $typeId,
            'limit_per_status' => $limitPerStatus,
            'catalogs' => $catalogs,
            'lanes' => $lanes,
            'lanes_truncated' => count($catalogs['resource_statuses']) > CrmSchema::MAX_KANBAN_LANES,
        ];
    }

    // ---------------------------------------------------------------
    // Identidade administrativa
    // ---------------------------------------------------------------

    /**
     * Admin ativo vinculado ao token OAuth. Nunca recebe id do chamador, e a
     * atividade é revalidada a cada chamada — não há cache positivo.
     *
     * @throws CrmException `denied`, `downstream`
     */
    public function resolveAuthorAdminId(string $oauthUsername): int
    {
        return $this->admins->resolveActiveAdminId($oauthUsername);
    }

    // ---------------------------------------------------------------
    // Instantes e limites
    // ---------------------------------------------------------------

    /** @throws CrmException `validation` */
    public function normalizeInstant(string $value, string $field): string
    {
        return CrmInstant::toUtcMySql($value, $field);
    }

    public function clampLimit(int $limit, int $max = CrmSchema::MAX_LIMIT): int
    {
        return CrmSchema::clampLimit($limit, $max);
    }

    public function clampOffset(int $offset): int
    {
        return CrmSchema::clampOffset($offset);
    }

    /** @throws CrmException `validation` */
    private function assertPositiveId(int $value, string $field): void
    {
        if ($value < 1) {
            throw CrmException::validation($field, 'expected a positive integer id');
        }
    }

    // ---------------------------------------------------------------
    // Mecânica compartilhada das leituras
    // ---------------------------------------------------------------

    /**
     * Página + total sob o MESMO filtro, por construção.
     *
     * `CrmCount::matching()` deriva a contagem do próprio select, então não
     * existe a classe de bug em que o filtro dos itens e o filtro do total
     * divergem depois de uma edição — a única forma de contar aqui é a partir
     * do select que já vai ser executado.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $map
     * @return array{items:array<int, array<string, mixed>>, count:int, limit:int, offset:int, has_more:bool}
     * @throws CrmException
     */
    private function paginate(CrmSelect $select, callable $map): array
    {
        $total = $this->port->countRows(CrmCount::matching($select));
        $items = array_map($map, $this->port->selectRows($select));

        return [
            'items' => $items,
            'count' => $total,
            'limit' => $select->limit,
            'offset' => $select->offset,
            'has_more' => ($select->offset + count($items)) < $total,
        ];
    }

    /**
     * Entradas ATIVAS e não soft-deleted de um catálogo.
     *
     * `active = 1` e `deleted_at IS NULL` estão na QUERY, não em PHP: um filtro
     * aplicado depois da paginação devolveria menos linhas que o limite pedido
     * e faria a página parecer o fim do catálogo.
     *
     * @return array<int, array{id:int, name:string|null}>
     * @throws CrmException
     */
    private function activeCatalog(CrmCatalog $catalog): array
    {
        $this->assertCapabilities($catalog->capability());

        $entries = [];

        foreach ($this->port->selectRows(new CrmSelect(
            table: $catalog->table(),
            columns: CrmSchema::catalogProjection(),
            conditions: [CrmSchema::COLUMN_ACTIVE => 1],
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            order: CrmSchema::catalogOrder(),
            limit: CrmSchema::MAX_CATALOG_ENTRIES,
        )) as $row) {
            $id = self::toId($row[CrmSchema::COLUMN_ID] ?? null);

            if ($id === null) {
                // Linha sem id utilizável não é publicável como catálogo: o
                // chamador escolheria um id que nenhuma escrita aceitaria.
                continue;
            }

            $entries[] = ['id' => $id, 'name' => self::toText($row[CrmSchema::COLUMN_NAME] ?? null)];
        }

        return $entries;
    }

    /**
     * Custom fields do recurso, normalizados e em BATCH.
     *
     * Duas consultas fixas — os valores do recurso e as definições visíveis —
     * casadas em memória. A alternativa ingênua (uma consulta de definição por
     * valor) seria o N+1 que o contrato proíbe.
     *
     * Definição soft-deleted não aparece: o valor órfão é descartado em vez de
     * ser publicado com nome nulo, porque um campo sem nome não é acionável e
     * revelaria a existência de configuração removida.
     *
     * @return array<int, array{field_id:int, name:string|null, value:string|null}>
     * @throws CrmException
     */
    private function readCustomFields(int $resourceId): array
    {
        $this->assertCapabilities(CrmCapability::CustomFields);

        $values = $this->port->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_FIELD_VALUES,
            columns: CrmSchema::fieldValueProjection(),
            conditions: [CrmSchema::COLUMN_RESOURCE_ID => $resourceId],
            order: CrmSchema::fieldValueOrder(),
            limit: CrmSchema::MAX_CUSTOM_FIELDS,
        ));

        if ($values === []) {
            // Sem valores não há definição a resolver: a segunda consulta seria
            // trabalho puro.
            return [];
        }

        $names = self::labelIndex($this->visibleFieldDefinitions());

        $fields = [];
        foreach ($values as $row) {
            $fieldId = self::toId($row[CrmSchema::COLUMN_FIELD_ID] ?? null);

            if ($fieldId === null || !array_key_exists($fieldId, $names)) {
                continue;
            }

            $fields[] = [
                'field_id' => $fieldId,
                'name' => $names[$fieldId],
                'value' => self::toText($row['value'] ?? null),
            ];
        }

        // Ordem determinística por nome e id, para que a resposta não dependa
        // da ordem física das linhas de valor.
        usort(
            $fields,
            static fn(array $a, array $b): int
                => [$a['name'] ?? '', $a['field_id']] <=> [$b['name'] ?? '', $b['field_id']]
        );

        return $fields;
    }

    /**
     * Definições de custom field visíveis (não soft-deleted).
     *
     * `crm_fields` não tem coluna de atividade no contrato — só `deleted_at` —
     * então este NÃO é um catálogo publicável e não passa por `activeCatalog()`,
     * que exige `active = 1`. A distinção é proposital: os quatro catálogos de
     * tipo/status alimentam escrita e precisam da prova de atividade; estas
     * definições só resolvem um nome de leitura.
     *
     * @return array<int, array{id:int, name:string|null}>
     * @throws CrmException
     */
    private function visibleFieldDefinitions(): array
    {
        $definitions = [];

        foreach ($this->port->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_FIELDS,
            columns: CrmSchema::catalogProjection(),
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            order: CrmSchema::catalogOrder(),
            limit: CrmSchema::MAX_CUSTOM_FIELDS,
        )) as $row) {
            $id = self::toId($row[CrmSchema::COLUMN_ID] ?? null);

            if ($id !== null) {
                $definitions[] = ['id' => $id, 'name' => self::toText($row[CrmSchema::COLUMN_NAME] ?? null)];
            }
        }

        return $definitions;
    }

    // ---------------------------------------------------------------
    // Projeções públicas e normalização de tipos
    //
    // O driver devolve inteiro como string conforme a configuração do PDO. Sem
    // normalizar, o mesmo campo mudaria de tipo entre instalações e o contrato
    // publicado deixaria de ser estável.
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function projectResource(array $row): array
    {
        return [
            'resource_id' => self::toId($row[CrmSchema::COLUMN_ID] ?? null),
            'type_id' => self::toId($row[CrmSchema::COLUMN_TYPE_ID] ?? null),
            'status_id' => self::toId($row[CrmSchema::COLUMN_STATUS_ID] ?? null),
            'name' => self::toText($row[CrmSchema::COLUMN_NAME] ?? null),
            'lastname' => self::toText($row['lastname'] ?? null),
            'email' => self::toText($row['email'] ?? null),
            'phone' => self::toText($row['phone'] ?? null),
            'country' => self::toText($row['country'] ?? null),
            'short_description' => self::toText($row['short_description'] ?? null),
            'description' => self::toText($row['description'] ?? null),
            'created_at' => self::toText($row[CrmSchema::COLUMN_CREATED_AT] ?? null),
            'updated_at' => self::toText($row[CrmSchema::COLUMN_UPDATED_AT] ?? null),
        ];
    }

    /**
     * @param array<string, mixed>       $row
     * @param array<int, string|null>    $types
     * @param array<int, string|null>    $statuses
     * @return array<string, mixed>
     */
    private static function projectFollowup(array $row, array $types, array $statuses): array
    {
        $typeId = self::toId($row[CrmSchema::COLUMN_TYPE_ID] ?? null);
        $statusId = self::toId($row[CrmSchema::COLUMN_STATUS_ID] ?? null);

        return [
            'followup_id' => self::toId($row[CrmSchema::COLUMN_ID] ?? null),
            'resource_id' => self::toId($row[CrmSchema::COLUMN_RESOURCE_ID] ?? null),
            'type_id' => $typeId,
            'type_name' => $typeId === null ? null : ($types[$typeId] ?? null),
            'status_id' => $statusId,
            'status_name' => $statusId === null ? null : ($statuses[$statusId] ?? null),
            'description' => self::toText($row['description'] ?? null),
            'date' => self::toText($row['date'] ?? null),
            'created_at' => self::toText($row[CrmSchema::COLUMN_CREATED_AT] ?? null),
            'updated_at' => self::toText($row[CrmSchema::COLUMN_UPDATED_AT] ?? null),
        ];
    }

    /**
     * @param array<int, array{id:int, name:string|null}> $entries
     * @return array<int, string|null>
     */
    private static function labelIndex(array $entries): array
    {
        $index = [];
        foreach ($entries as $entry) {
            $index[$entry['id']] = $entry['name'];
        }

        return $index;
    }

    /** Id positivo, aceitando a forma string do driver. */
    private static function toId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9]\d{0,17}\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Texto ou nulo. Objeto/array do driver NÃO atravessa: viraria estrutura
     * inesperada no JSON público e poderia carregar estado interno do ORM.
     */
    private static function toText(mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return null;
    }
}
