<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Os quatro catálogos do mgCRM2 cujos IDs a superfície pública aceita.
 *
 * D11: nenhum ID seed é presumido e nenhum nome inglês (`Lead`, `New`,
 * `Pending`) é resolvido — os catálogos podem estar traduzidos ou editados. O
 * chamador escolhe um ID publicado por `whmcs_crm_get_kanban`, e este enum é o
 * único vocabulário que a fronteira aceita para dizer DE QUAL catálogo ele veio.
 */
enum CrmCatalog: string
{
    case ResourceType = 'resource_type';
    case ResourceStatus = 'resource_status';
    case FollowupType = 'followup_type';
    case FollowupStatus = 'followup_status';

    public function table(): string
    {
        return match ($this) {
            self::ResourceType => CrmSchema::TABLE_RESOURCE_TYPES,
            self::ResourceStatus => CrmSchema::TABLE_RESOURCE_STATUSES,
            self::FollowupType => CrmSchema::TABLE_FOLLOWUP_TYPES,
            self::FollowupStatus => CrmSchema::TABLE_FOLLOWUP_STATUSES,
        };
    }

    /**
     * Cada catálogo é uma capacidade PRÓPRIA. Agrupá-los fazia a ausência de
     * `crm_resources_statuses` recusar um tipo íntegro em
     * `crm_resources_types`, que é outra tabela e outra query.
     */
    public function capability(): CrmCapability
    {
        return match ($this) {
            self::ResourceType => CrmCapability::ResourceTypes,
            self::ResourceStatus => CrmCapability::ResourceStatuses,
            self::FollowupType => CrmCapability::FollowupTypes,
            self::FollowupStatus => CrmCapability::FollowupStatuses,
        };
    }

    /**
     * Capacidade da resolução HISTÓRICA de nome (D13), sem `active`.
     *
     * Só os catálogos de follow-up resolvem label hoje — é `list_followups`
     * que publica `type_name`/`status_name`. Pedir a capacidade de label de um
     * catálogo de recurso seria erro de programação, não estado do banco, e
     * por isso estoura em vez de devolver uma capacidade aproximada.
     */
    public function labelCapability(): CrmCapability
    {
        return match ($this) {
            self::FollowupType => CrmCapability::FollowupTypeLabels,
            self::FollowupStatus => CrmCapability::FollowupStatusLabels,
            self::ResourceType, self::ResourceStatus => throw new \LogicException(
                'CrmCatalog: resource catalogs do not resolve historical labels in this tranche.'
            ),
        };
    }

    /** Coluna do recurso/follow-up que recebe o ID deste catálogo. */
    public function foreignKey(): string
    {
        return match ($this) {
            self::ResourceType, self::FollowupType => CrmSchema::COLUMN_TYPE_ID,
            self::ResourceStatus, self::FollowupStatus => CrmSchema::COLUMN_STATUS_ID,
        };
    }
}
