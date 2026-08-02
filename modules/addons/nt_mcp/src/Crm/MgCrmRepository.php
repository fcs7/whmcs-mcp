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
     * D13 — os labels são resolvidos POR ID, em lote, somente para os ids que
     * aparecem NA PÁGINA: linha existente e não soft-deleted resolve o nome
     * mesmo com `active = 0`, porque um follow-up antigo aponta legitimamente
     * para um tipo que saiu de circulação. Essa entrada continua fora do
     * catálogo ativo de `get_kanban` e inválida para escrita.
     *
     * Referência soft-deleted, ausente ou com nome inutilizável é INTEGRIDADE
     * QUEBRADA e falha `downstream` — nunca `*_name: null`, que tornava
     * corrupção indistinguível de label legítimo.
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

        $page = $this->paginate(
            new CrmSelect(
                table: CrmSchema::TABLE_FOLLOWUPS,
                columns: CrmSchema::followupReadProjection(),
                conditions: $conditions,
                nullColumns: [CrmSchema::COLUMN_DELETED_AT],
                order: CrmSchema::followupOrder(),
                limit: $limit,
                offset: $offset,
            ),
            static fn(array $row): array => self::projectFollowup($row),
        );

        $types = $this->resolveHistoricalLabels(
            CrmCatalog::FollowupType,
            self::distinctIds($page['items'], 'type_id')
        );
        $statuses = $this->resolveHistoricalLabels(
            CrmCatalog::FollowupStatus,
            self::distinctIds($page['items'], 'status_id')
        );

        foreach ($page['items'] as $index => $item) {
            $page['items'][$index]['type_name'] = self::requireLabel($types, $item['type_id'], 'followup_type');
            $page['items'][$index]['status_name'] = self::requireLabel($statuses, $item['status_id'], 'followup_status');
        }

        return $page;
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
     * Existe uma raia para CADA status de recurso ativo — sem teto e sem corte.
     * A revisão fria reprovou o desenho anterior, que materializava 25 raias e
     * anunciava a omissão num campo: um status válido com recursos dentro
     * simplesmente desaparecia da visão, e o campo extra não substitui o dado
     * contratado.
     *
     * Cada raia carrega o total EXATO (COUNT sob o mesmo filtro) e uma página
     * de itens limitada, nunca a raia inteira. Raia vazia não paga a consulta
     * de itens, então o custo acompanha os statuses que realmente têm recurso.
     *
     * @return array{type_id:int|null, limit_per_status:int, catalogs:array<string, array<int, array<string, mixed>>>, lanes:array<int, array<string, mixed>>}
     * @throws CrmException
     */
    public function getKanban(
        ?int $typeId,
        int $limitPerStatus,
        int $statusLimit = CrmSchema::MAX_STATUS_LIMIT,
        int $statusOffset = 0,
    ): array {
        $limitPerStatus = CrmSchema::clampLimit($limitPerStatus, CrmSchema::MAX_LIMIT_PER_STATUS);
        $statusLimit = CrmSchema::clampLimit($statusLimit, CrmSchema::MAX_STATUS_LIMIT);
        $statusOffset = CrmSchema::clampOffset($statusOffset);

        $this->assertCapabilities(CrmCapability::ResourceCore);

        $filter = [];
        if ($typeId !== null) {
            $filter[CrmSchema::COLUMN_TYPE_ID] =
                $this->requireCatalogEntry(CrmCatalog::ResourceType, $typeId);
        }

        // Os quatro catálogos continuam COMPLETOS em toda resposta: eles são a
        // fonte dos ids que CRM-3 vai exigir, e paginá-los reabriria o defeito
        // de "id ativo que o cliente nunca descobre". O que pagina são as raias.
        $catalogs = [
            'resource_types' => $this->activeCatalog(CrmCatalog::ResourceType),
            'resource_statuses' => $this->activeCatalog(CrmCatalog::ResourceStatus),
            'followup_types' => $this->activeCatalog(CrmCatalog::FollowupType),
            'followup_statuses' => $this->activeCatalog(CrmCatalog::FollowupStatus),
        ];

        $statusCount = count($catalogs['resource_statuses']);

        // A página sai do catálogo já materializado e ordenado: determinística,
        // sem consulta extra, e toda raia é alcançável avançando o offset.
        $page = array_slice($catalogs['resource_statuses'], $statusOffset, $statusLimit);

        $lanes = [];
        foreach ($page as $status) {
            $conditions = $filter;
            $conditions[CrmSchema::COLUMN_STATUS_ID] = $status['id'];

            $select = new CrmSelect(
                table: CrmSchema::TABLE_RESOURCES,
                columns: CrmSchema::resourceProjection(),
                conditions: $conditions,
                nullColumns: [CrmSchema::COLUMN_DELETED_AT],
                order: CrmSchema::resourceOrder(),
                limit: $limitPerStatus,
            );

            $total = $this->port->countRows(CrmCount::matching($select));

            // Raia vazia é o caso comum num kanban largo: sem recurso, a
            // consulta de itens não tem o que devolver e é pulada.
            $items = $total === 0
                ? []
                : array_map(
                    static fn(array $row): array => self::projectResource($row),
                    $this->port->selectRows($select)
                );

            $lanes[] = [
                'status_id' => $status['id'],
                'status_name' => $status['name'],
                'total' => $total,
                'items' => $items,
                'has_more' => count($items) < $total,
            ];
        }

        return [
            'type_id' => $typeId,
            'limit_per_status' => $limitPerStatus,
            'status_count' => $statusCount,
            'status_limit' => $statusLimit,
            'status_offset' => $statusOffset,
            'status_has_more' => ($statusOffset + count($lanes)) < $statusCount,
            'catalogs' => $catalogs,
            'lanes' => $lanes,
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
     * Catálogo ATIVO COMPLETO — todas as entradas, sem teto silencioso.
     *
     * `active = 1` e `deleted_at IS NULL` estão na QUERY, não em PHP: um filtro
     * aplicado depois da paginação devolveria menos linhas que a página pedida
     * e faria o chunk parecer o fim do catálogo.
     *
     * A completude importa porque `get_kanban` É a fonte dos IDs que as
     * escritas de CRM-3 vão exigir: um id ativo que o cliente nunca vê é um id
     * que ele nunca consegue usar, mesmo sendo válido.
     *
     * @return array<int, array{id:int, name:string|null}>
     * @throws CrmException
     */
    private function activeCatalog(CrmCatalog $catalog): array
    {
        $this->assertCapabilities($catalog->capability());

        $entries = [];

        foreach ($this->scanAll(
            table: $catalog->table(),
            columns: CrmSchema::catalogProjection(),
            conditions: [CrmSchema::COLUMN_ACTIVE => 1],
            nullColumns: [CrmSchema::COLUMN_DELETED_AT],
            order: CrmSchema::catalogOrder(),
            ceiling: CrmSchema::MAX_CATALOG_TOTAL,
            context: 'catalog_' . $catalog->value,
        ) as $row) {
            $id = self::toId($row[CrmSchema::COLUMN_ID] ?? null);

            if ($id === null) {
                // Um catálogo com linha sem id utilizável está corrompido: o
                // chamador escolheria um id que nenhuma escrita aceitaria.
                throw CrmException::integrity('catalog_row_without_id');
            }

            $entries[] = [
                'id' => $id,
                'name' => self::requireName(
                    $row[CrmSchema::COLUMN_NAME] ?? null,
                    'catalog_' . $catalog->value
                ),
            ];
        }

        return $entries;
    }

    /**
     * Nome PUBLICÁVEL de catálogo ou label — validação única das duas rotas.
     *
     * A revisão fria mostrou o buraco de ter duas validações diferentes: o
     * catálogo ativo publicava `name: null` e o label histórico aceitava
     * `"   "`, porque só a resolução histórica checava vazio e nenhuma das duas
     * fazia `trim()`. Um rótulo em branco não é acionável pelo cliente e torna
     * corrupção indistinguível de dado válido — os dois casos são integridade.
     *
     * Devolve o nome já normalizado, para que a resposta não carregue o
     * whitespace de borda do banco.
     *
     * @throws CrmException
     */
    private static function requireName(mixed $raw, string $context): string
    {
        $name = trim(self::toText($raw) ?? '');

        if ($name === '') {
            throw CrmException::integrity($context . '_name_blank');
        }

        return $name;
    }

    /**
     * Varredura COMPLETA em chunks fechados, provada contra o total exato.
     *
     * O contrato pede completude comprovável, e "li a primeira página" não é
     * prova. Então: conta primeiro, pagina em chunks até a varredura terminar
     * e, no fim, EXIGE que o volume lido seja o volume contado. Divergência é
     * integridade/concorrência, não um resultado parcial aceitável.
     *
     * O teto é de SEGURANÇA e não trunca: excedê-lo falha fechado. Truncar
     * silenciosamente foi exatamente o defeito reprovado na revisão fria.
     *
     * @param array<int, string>                   $columns
     * @param array<string, int|string>            $conditions
     * @param array<int, string>                   $nullColumns
     * @param array<int, array{0:string,1:string}> $order
     * @return array<int, array<string, mixed>>
     * @throws CrmException
     */
    private function scanAll(
        string $table,
        array $columns,
        array $conditions,
        array $nullColumns,
        array $order,
        int $ceiling,
        string $context,
    ): array {
        // Snapshot lógico: o maior id existente AGORA. Tudo inserido depois
        // recebe id maior e fica fora desta leitura, então a varredura tem um
        // conjunto-alvo estável em vez de perseguir um alvo móvel.
        $throughId = $this->highestId($table, $conditions, $nullColumns, $columns, $context);

        if ($throughId === null) {
            // Sem nenhuma linha visível, o total precisa concordar.
            if ($this->port->countRows(new CrmCount($table, $conditions, $nullColumns)) !== 0) {
                throw CrmException::integrity($context . '_snapshot_divergence');
            }

            return [];
        }

        $total = $this->port->countRows(new CrmCount($table, $conditions, $nullColumns, $throughId));

        if ($total > $ceiling) {
            throw CrmException::integrity($context . '_above_safety_ceiling');
        }

        $rows = [];
        $afterId = null;
        $seen = [];
        $maxIterations = intdiv($ceiling, CrmSchema::CHUNK_SIZE) + 2;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $page = $this->port->selectRows(new CrmSelect(
                table: $table,
                columns: $columns,
                conditions: $conditions,
                nullColumns: $nullColumns,
                // A varredura ordena por IDENTIDADE, não pela ordem pública: só
                // uma chave imutável e única torna o keyset livre de duplicata
                // e omissão. A ordem pública é aplicada no fim, em memória.
                order: [[CrmSchema::COLUMN_ID, 'asc']],
                limit: CrmSchema::CHUNK_SIZE,
                afterId: $afterId,
                throughId: $throughId,
            ));

            if ($page === []) {
                break;
            }

            foreach ($page as $row) {
                $id = self::toId($row[CrmSchema::COLUMN_ID] ?? null);

                if ($id === null) {
                    throw CrmException::integrity($context . '_row_without_identity');
                }

                if (isset($seen[$id])) {
                    throw CrmException::integrity($context . '_duplicate_row');
                }

                // Progresso ESTRITO: um id que não avança significa que a
                // página repetiu ou retrocedeu, e continuar produziria um
                // conjunto que parece completo sem ser.
                if ($afterId !== null && $id <= $afterId) {
                    throw CrmException::integrity($context . '_non_monotonic_scan');
                }

                $seen[$id] = true;
                $rows[] = $row;
                $afterId = $id;

                if (count($rows) > $total) {
                    throw CrmException::integrity($context . '_scan_exceeded_total');
                }
            }

            // Página curta sob um teto de id FIXO significa exaustão: não há
            // mais nada entre o último id lido e `throughId`. Se a página
            // encurtou por remoção concorrente, a conferência final contra o
            // total pega — então economizar este round trip não enfraquece a
            // prova de completude.
            if (count($page) < CrmSchema::CHUNK_SIZE) {
                break;
            }
        }

        if (count($rows) !== $total || count($seen) !== $total) {
            throw CrmException::integrity($context . '_incomplete_scan');
        }

        // Revalidação sob o MESMO snapshot: se o total mudou dentro do teto de
        // ids já fechado, houve escrita concorrente e a completude deixou de
        // ser demonstrável.
        if ($this->port->countRows(new CrmCount($table, $conditions, $nullColumns, $throughId)) !== $total) {
            throw CrmException::integrity($context . '_concurrent_divergence');
        }

        return self::sortRows($rows, $order);
    }

    /**
     * Maior `id` visível sob o filtro — o teto do snapshot lógico.
     *
     * @param array<string, int|string> $conditions
     * @param array<int, string>        $nullColumns
     * @param array<int, string>        $columns
     * @throws CrmException
     */
    private function highestId(
        string $table,
        array $conditions,
        array $nullColumns,
        array $columns,
        string $context,
    ): ?int {
        $rows = $this->port->selectRows(new CrmSelect(
            table: $table,
            columns: in_array(CrmSchema::COLUMN_ID, $columns, true) ? [CrmSchema::COLUMN_ID] : $columns,
            conditions: $conditions,
            nullColumns: $nullColumns,
            order: [[CrmSchema::COLUMN_ID, 'desc']],
            limit: 1,
        ));

        if ($rows === []) {
            return null;
        }

        $id = self::toId($rows[0][CrmSchema::COLUMN_ID] ?? null);

        if ($id === null) {
            throw CrmException::integrity($context . '_row_without_identity');
        }

        return $id;
    }

    /**
     * Ordem PÚBLICA aplicada em memória, depois de a completude estar provada.
     *
     * @param array<int, array<string, mixed>>     $rows
     * @param array<int, array{0:string,1:string}> $order
     * @return array<int, array<string, mixed>>
     */
    private static function sortRows(array $rows, array $order): array
    {
        if ($order === []) {
            return $rows;
        }

        usort($rows, static function (array $a, array $b) use ($order): int {
            foreach ($order as [$column, $direction]) {
                $left = $a[$column] ?? null;
                $right = $b[$column] ?? null;

                // `strcmp()` e não `<=>`: em PHP 8 o spaceship compara DUAS
                // strings numéricas como números, então um `name` VARCHAR
                // valendo "9" e "10" sairia em ordem numérica — que não é a
                // ordem que o MySQL devolve para uma coluna textual.
                $comparison = CrmSchema::isIntegerColumn($column)
                    ? (self::toId($left) ?? 0) <=> (self::toId($right) ?? 0)
                    : strcmp((string) $left, (string) $right);

                if ($comparison !== 0) {
                    return $direction === 'desc' ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $rows;
    }

    /**
     * Resolução HISTÓRICA de labels por id, em lotes fechados (D13).
     *
     * Sem filtro de `active`: tipo/status desativado ainda resolve o nome, e é
     * essa a diferença que o contrato pede — o catálogo publicado continua
     * sendo só o ativo, mas o dado histórico não perde o rótulo.
     *
     * Toda referência pedida DEVE voltar com nome utilizável. Faltar (porque
     * está soft-deleted ou porque a linha sumiu) é integridade quebrada.
     *
     * @param array<int, int> $ids
     * @return array<int, string>
     * @throws CrmException
     */
    private function resolveHistoricalLabels(CrmCatalog $catalog, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $this->assertCapabilities($catalog->labelCapability());

        $labels = [];

        foreach (array_chunk($ids, CrmSchema::MAX_IN_VALUES) as $batch) {
            foreach ($this->port->selectRows(new CrmSelect(
                table: $catalog->table(),
                columns: CrmSchema::catalogProjection(),
                nullColumns: [CrmSchema::COLUMN_DELETED_AT],
                order: CrmSchema::catalogOrder(),
                limit: CrmSchema::CHUNK_SIZE,
                inConditions: [CrmSchema::COLUMN_ID => $batch],
            )) as $row) {
                $id = self::toId($row[CrmSchema::COLUMN_ID] ?? null);

                if ($id === null) {
                    throw CrmException::integrity('label_' . $catalog->value . '_row_without_id');
                }

                // Nome em branco (null, vazio ou só whitespace) é integridade
                // quebrada, não um label visualmente vazio aceitável — D13.
                $labels[$id] = self::requireName(
                    $row[CrmSchema::COLUMN_NAME] ?? null,
                    'label_' . $catalog->value
                );
            }
        }

        return $labels;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, int>
     */
    private static function distinctIds(array $items, string $key): array
    {
        $ids = [];

        foreach ($items as $item) {
            $id = $item[$key] ?? null;
            if (is_int($id) && $id > 0) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<int, string> $labels
     * @throws CrmException
     */
    private static function requireLabel(array $labels, ?int $id, string $context): string
    {
        if ($id === null) {
            // A coluna é `NOT NULL` no contrato: sem id não há o que resolver.
            throw CrmException::integrity($context . '_reference_missing');
        }

        if (!array_key_exists($id, $labels)) {
            throw CrmException::integrity($context . '_reference_unresolved');
        }

        return $labels[$id];
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

        // TODOS os valores do recurso, em chunks — o teto anterior escondia o
        // 101º valor e apresentava um contato incompleto como completo.
        $values = $this->scanAll(
            table: CrmSchema::TABLE_FIELD_VALUES,
            columns: CrmSchema::fieldValueProjection(),
            conditions: [CrmSchema::COLUMN_RESOURCE_ID => $resourceId],
            nullColumns: [],
            order: CrmSchema::fieldValueOrder(),
            ceiling: CrmSchema::MAX_CUSTOM_FIELD_VALUES,
            context: 'custom_field_values',
        );

        if ($values === []) {
            // Sem valores não há definição a resolver: a consulta de definições
            // seria trabalho puro.
            return [];
        }

        $entries = [];
        $fieldIds = [];

        foreach ($values as $row) {
            $fieldId = self::toId($row[CrmSchema::COLUMN_FIELD_ID] ?? null);

            if ($fieldId === null) {
                throw CrmException::integrity('custom_field_value_without_definition_reference');
            }

            $fieldIds[$fieldId] = true;
            $entries[] = [
                'field_id' => $fieldId,
                'value_id' => self::toId($row[CrmSchema::COLUMN_ID] ?? null) ?? 0,
                'value' => self::toText($row['value'] ?? null),
            ];
        }

        // Somente as definições REFERENCIADAS, em lotes fechados: nem N+1, nem
        // varredura do catálogo global (que era o que fazia uma definição além
        // da primeira página sumir com o valor junto).
        $names = $this->resolveFieldDefinitionNames(array_keys($fieldIds));

        $fields = [];
        foreach ($entries as $entry) {
            if (!array_key_exists($entry['field_id'], $names)) {
                // Definição ausente ou soft-deleted com valor vivo apontando
                // para ela: integridade quebrada, nunca descarte silencioso.
                throw CrmException::integrity('custom_field_definition_unresolved');
            }

            $fields[] = [
                'field_id' => $entry['field_id'],
                'name' => $names[$entry['field_id']],
                'value' => $entry['value'],
                '_value_id' => $entry['value_id'],
            ];
        }

        // Ordem determinística mesmo com VÁRIOS valores no mesmo campo: nome,
        // depois id do campo, depois id do valor.
        usort(
            $fields,
            static fn(array $a, array $b): int
                => [$a['name'], $a['field_id'], $a['_value_id']]
                <=> [$b['name'], $b['field_id'], $b['_value_id']]
        );

        // `_value_id` é só chave de ordenação; não faz parte do contrato.
        return array_map(
            static fn(array $field): array => [
                'field_id' => $field['field_id'],
                'name' => $field['name'],
                'value' => $field['value'],
            ],
            $fields
        );
    }

    /**
     * Nomes das definições de custom field REFERENCIADAS, em lotes fechados.
     *
     * `crm_fields` não tem coluna de atividade no contrato — só `deleted_at` —
     * então isto não passa por `activeCatalog()`, que exige `active = 1`. A
     * distinção é proposital: os quatro catálogos de tipo/status alimentam
     * escrita e precisam da prova de atividade; estas definições só resolvem um
     * nome de leitura.
     *
     * A projeção continua `id`/`name`: validators, opções e visibilidade não
     * têm caminho até a resposta porque não têm caminho até a query.
     *
     * @param array<int, int> $fieldIds
     * @return array<int, string>
     * @throws CrmException
     */
    private function resolveFieldDefinitionNames(array $fieldIds): array
    {
        $names = [];

        foreach (array_chunk($fieldIds, CrmSchema::MAX_IN_VALUES) as $batch) {
            foreach ($this->port->selectRows(new CrmSelect(
                table: CrmSchema::TABLE_FIELDS,
                columns: CrmSchema::catalogProjection(),
                nullColumns: [CrmSchema::COLUMN_DELETED_AT],
                order: CrmSchema::catalogOrder(),
                limit: CrmSchema::CHUNK_SIZE,
                inConditions: [CrmSchema::COLUMN_ID => $batch],
            )) as $row) {
                $id = self::toId($row[CrmSchema::COLUMN_ID] ?? null);

                if ($id === null) {
                    throw CrmException::integrity('custom_field_definition_without_id');
                }

                $names[$id] = self::requireName(
                    $row[CrmSchema::COLUMN_NAME] ?? null,
                    'custom_field_definition'
                );
            }
        }

        return $names;
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
     * Projeção do follow-up SEM os labels: eles são resolvidos em lote depois
     * da página, a partir dos ids que ela realmente contém, e preenchidos por
     * `listFollowups()`. A ordem das chaves é fixa aqui para que o shape
     * público não dependa da ordem de preenchimento.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function projectFollowup(array $row): array
    {
        return [
            'followup_id' => self::toId($row[CrmSchema::COLUMN_ID] ?? null),
            'resource_id' => self::toId($row[CrmSchema::COLUMN_RESOURCE_ID] ?? null),
            'type_id' => self::toId($row[CrmSchema::COLUMN_TYPE_ID] ?? null),
            'type_name' => null,
            'status_id' => self::toId($row[CrmSchema::COLUMN_STATUS_ID] ?? null),
            'status_name' => null,
            'description' => self::toText($row['description'] ?? null),
            'date' => self::toText($row['date'] ?? null),
            'created_at' => self::toText($row[CrmSchema::COLUMN_CREATED_AT] ?? null),
            'updated_at' => self::toText($row[CrmSchema::COLUMN_UPDATED_AT] ?? null),
        ];
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
