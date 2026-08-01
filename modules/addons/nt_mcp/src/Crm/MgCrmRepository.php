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
 * Invariantes que a classe sustenta, e que os testes deste ticket exercitam:
 *
 *  - nenhum método público aceita nome de tabela, coluna, ordenação ou operador;
 *  - toda leitura passa por `CrmSelect`, que recusa nome fora do contrato;
 *  - nenhuma consulta operacional acontece antes do schema guard da capacidade;
 *  - linha soft-deleted nunca é vista, em nenhuma das leituras;
 *  - falha de schema, catálogo, recurso, data ou admin não produz efeito algum;
 *  - `admin_id` vem sempre do OAuth resolvido, nunca do input MCP.
 */
final class MgCrmRepository
{
    public function __construct(
        private readonly CrmSchemaGuard $guard,
        private readonly CrmQueryPort $port,
        private readonly AdminIdentityResolver $admins,
        private readonly CrmWriteGate $writeGate = new CrmWriteGate(),
    ) {
    }

    // ---------------------------------------------------------------
    // Disponibilidade
    // ---------------------------------------------------------------

    /** @throws CrmException `crm_unavailable` / `crm_schema_mismatch` */
    public function assertCapabilities(CrmCapability ...$capabilities): void
    {
        $this->guard->assertAll(...$capabilities);
    }

    public function supports(CrmCapability $capability): bool
    {
        return $this->guard->isAvailable($capability);
    }

    // ---------------------------------------------------------------
    // Recurso
    // ---------------------------------------------------------------

    /**
     * Prova que o recurso existe e não está soft-deleted, devolvendo o id
     * canônico. Toda operação que cita um `resource_id` começa por aqui.
     *
     * @throws CrmException `validation`, `crm_resource_not_found`
     */
    public function requireResource(int $resourceId): int
    {
        $this->assertPositiveId($resourceId, 'resource_id');
        $this->assertCapabilities(CrmCapability::Resources);

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
     * Prova que o id pertence ao catálogo pedido, está ativo e não foi
     * soft-deleted. Um id válido no catálogo ERRADO também falha: o catálogo é
     * escolhido pelo chamador do repositório, não inferido do número.
     *
     * @throws CrmException `validation`, `crm_catalog_invalid`
     */
    public function requireCatalogEntry(CrmCatalog $catalog, int $id): int
    {
        $this->assertPositiveId($id, $catalog->foreignKey());

        $shape = $this->guard->assert($catalog->capability());
        $table = $catalog->table();

        $conditions = [CrmSchema::COLUMN_ID => $id];
        if ($shape->hasOptionalColumn($table, CrmSchema::COLUMN_ACTIVE)) {
            $conditions[CrmSchema::COLUMN_ACTIVE] = 1;
        }

        $rows = $this->port->selectRows(new CrmSelect(
            table: $table,
            columns: [CrmSchema::COLUMN_ID],
            conditions: $conditions,
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
     * Admin ativo vinculado ao token OAuth. Nunca recebe id do chamador.
     *
     * @throws CrmException
     */
    public function resolveAuthorAdminId(string $oauthUsername): int
    {
        return $this->admins->resolveActiveAdminId($oauthUsername);
    }

    /**
     * Executa, em ordem, TODAS as barreiras que precedem um efeito. Qualquer
     * uma delas falhando impede a escrita — e a ordem importa: o schema é
     * verificado antes do gate, e o gate antes da identidade, para que nenhuma
     * consulta a `tbladmins` aconteça num ambiente que já está bloqueado.
     *
     * O CRM-3 constrói as mutações a partir do contexto devolvido.
     *
     * @throws CrmException
     */
    public function prepareWrite(string $oauthUsername, CrmCapability ...$capabilities): CrmWriteContext
    {
        $this->assertCapabilities(...$capabilities);
        $this->writeGate->assertWritable();

        return new CrmWriteContext(
            adminId: $this->resolveAuthorAdminId($oauthUsername),
            timestamp: gmdate(CrmInstant::MYSQL_FORMAT),
        );
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
