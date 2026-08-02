<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Unidades que o schema guard valida INDEPENDENTEMENTE.
 *
 * O recorte é MÍNIMO POR OPERAÇÃO, não por tabela. A versão anterior agrupava
 * "recursos" e "catálogos de recurso" em duas capacidades grandes, e a revisão
 * reproduziu o custo disso: com `crm_resources.short_description` ausente,
 * `requireResource(42)` — que consulta apenas `id` e `deleted_at` — respondia
 * `crm_schema_mismatch`; e com `crm_resources_statuses` ausente, um tipo
 * íntegro era recusado. Drift localizado derrubava operação alheia.
 *
 * Agora cada método prova somente as tabelas/colunas que a sua própria query
 * usa. Operações que realmente leem o conjunto (kanban, em CRM-2) compõem
 * várias capacidades explicitamente.
 */
enum CrmCapability: string
{
    /** `crm_resources.id` + soft-delete — o mínimo para existir/validar um recurso. */
    case ResourceIdentity = 'resource_identity';

    /** Projeção core de `crm_resources` — só quem lê os campos precisa disto. */
    case ResourceCore = 'resource_core';

    /**
     * Atribuição do recurso ao admin autor (`crm_resources.admin_id`).
     *
     * Separada porque é a coluna de nome menos comprovado do contrato: o DDL
     * empacotado prova `admin_id` em `crm_followups`, e a atribuição do recurso
     * é inferência da decisão de produto. T6 confirma.
     */
    case ResourceAssignment = 'resource_assignment';

    /** `crm_resources_types`. */
    case ResourceTypes = 'resource_types';

    /** `crm_resources_statuses`. */
    case ResourceStatuses = 'resource_statuses';

    /**
     * `crm_followups` para ESCRITA — inclui `admin_id`, que é a coluna de
     * autoria que CRM-3 preenche a partir do OAuth.
     */
    case Followups = 'followups';

    /**
     * `crm_followups` para LEITURA — o mesmo recorte SEM `admin_id`.
     *
     * Separada pelo motivo que a revisão fria de CRM-1 fixou: cada operação
     * prova somente as colunas que a sua query usa. A listagem pública de
     * follow-ups não projeta nem filtra `admin_id` (o id interno do staff não
     * atravessa a superfície MCP), então um drift nessa coluna não pode
     * derrubar a leitura.
     */
    case FollowupsRead = 'followups_read';

    /** `crm_followup_types` — catálogo ATIVO publicado e validado em filtros. */
    case FollowupTypes = 'followup_types';

    /** `crm_followup_statuses` — catálogo ATIVO publicado e validado em filtros. */
    case FollowupStatuses = 'followup_statuses';

    /**
     * Resolução HISTÓRICA de nome de tipo/status de follow-up (D13).
     *
     * Separada dos catálogos ativos porque a query é outra: resolve por id,
     * exige `deleted_at IS NULL` e NÃO olha `active` — um follow-up antigo
     * apontando para um tipo desativado continua com o nome resolvido. Como a
     * coluna `active` não participa, um drift nela não pode derrubar
     * `list_followups`.
     */
    case FollowupTypeLabels = 'followup_type_labels';
    case FollowupStatusLabels = 'followup_status_labels';

    /** `crm_notes`. */
    case Notes = 'notes';

    /**
     * Custom fields READ-ONLY.
     *
     * Os nomes físicos das duas tabelas são os menos comprovados de todo o
     * contrato — a evidência disponível diz apenas `crm_fields*`. A capacidade
     * é isolada de propósito: se os nomes estiverem errados, ela responde
     * `crm_unavailable` e NADA mais da superfície é afetado. Não há fallback de
     * nome; T6 confirma por metadata read-only antes de CRM-2 usá-la.
     */
    case CustomFields = 'custom_fields';

    /** `tbladmins` — identidade do admin ativo vinculado ao OAuth. */
    case AdminIdentity = 'admin_identity';
}
