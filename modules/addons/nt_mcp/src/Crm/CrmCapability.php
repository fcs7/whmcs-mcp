<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Unidades que o schema guard valida INDEPENDENTEMENTE.
 *
 * O recorte não é cosmético. Um conjunto mínimo único para "o CRM" faria uma
 * incerteza local derrubar a superfície inteira: se `crm_resources.admin_id`
 * tiver outro nome na instalação real, as quatro leituras — que não precisam
 * dessa coluna — passariam a responder `crm_schema_mismatch` sem motivo.
 *
 * Por isso cada capacidade declara o MENOR conjunto de tabelas/colunas que a
 * operação correspondente realmente exige, e cada operação exige só as suas.
 * Falhar fechado continua sendo a regra; o que muda é o raio do fechamento.
 */
enum CrmCapability: string
{
    /** Campos core de `crm_resources` — base das quatro leituras. */
    case Resources = 'resources';

    /**
     * Atribuição do recurso ao admin autor (`crm_resources.admin_id`).
     *
     * Separada de `Resources` porque é a coluna de nome menos comprovado do
     * contrato: o DDL empacotado prova `admin_id` em `crm_followups`, e a
     * atribuição do recurso é inferência da decisão de produto. T6 confirma.
     */
    case ResourceAssignment = 'resource_assignment';

    /** `crm_resources_types` + `crm_resources_statuses`. */
    case ResourceCatalogs = 'resource_catalogs';

    /** `crm_followups`. */
    case Followups = 'followups';

    /** `crm_followup_types` + `crm_followup_statuses`. */
    case FollowupCatalogs = 'followup_catalogs';

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
