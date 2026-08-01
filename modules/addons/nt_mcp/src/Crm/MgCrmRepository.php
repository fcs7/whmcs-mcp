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
}
