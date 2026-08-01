<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

use NtMcp\Crm\CrmSchema;

/**
 * A instalação mgCRM2 "saudável" usada pelos testes.
 *
 * As colunas são declaradas EXPLICITAMENTE, e não derivadas de
 * `CrmSchema::columnsOf()`: um fixture que se deriva do próprio contrato
 * testaria a si mesmo. Se alguém remover uma coluna exigida do `CrmSchema` sem
 * pensar, os testes de schema guard aqui continuam falando da instalação real
 * esperada e o desvio aparece.
 */
final class CrmSchemaFixture
{
    /** @return array<string, array<int, string>> */
    public static function completeInstallation(): array
    {
        return [
            CrmSchema::TABLE_RESOURCES => [
                'id', 'type_id', 'status_id', 'admin_id', 'name', 'lastname', 'email',
                'phone', 'country', 'short_description', 'description',
                'created_at', 'updated_at', 'deleted_at',
            ],
            CrmSchema::TABLE_RESOURCE_TYPES => ['id', 'name', 'active', 'deleted_at'],
            CrmSchema::TABLE_RESOURCE_STATUSES => ['id', 'name', 'active', 'deleted_at'],
            CrmSchema::TABLE_FOLLOWUPS => [
                'id', 'resource_id', 'type_id', 'status_id', 'admin_id',
                'description', 'date', 'created_at', 'updated_at', 'deleted_at',
            ],
            CrmSchema::TABLE_FOLLOWUP_TYPES => ['id', 'name', 'active', 'deleted_at'],
            CrmSchema::TABLE_FOLLOWUP_STATUSES => ['id', 'name', 'active', 'deleted_at'],
            CrmSchema::TABLE_NOTES => [
                'id', 'resource_id', 'admin_id', 'content',
                'created_at', 'updated_at', 'deleted_at',
            ],
            CrmSchema::TABLE_FIELDS => ['id', 'name', 'deleted_at'],
            CrmSchema::TABLE_FIELD_VALUES => ['id', 'field_id', 'resource_id', 'value'],
            CrmSchema::TABLE_ADMINS => ['id', 'username', 'disabled'],
        ];
    }

    /**
     * Instalação sem a coluna de atividade nos catálogos — o cenário em que
     * "ativo" significa apenas "não soft-deleted".
     *
     * @return array<string, array<int, string>>
     */
    public static function withoutCatalogActiveColumn(): array
    {
        $tables = self::completeInstallation();

        foreach ([
            CrmSchema::TABLE_RESOURCE_TYPES,
            CrmSchema::TABLE_RESOURCE_STATUSES,
            CrmSchema::TABLE_FOLLOWUP_TYPES,
            CrmSchema::TABLE_FOLLOWUP_STATUSES,
        ] as $table) {
            $tables[$table] = array_values(array_filter(
                $tables[$table],
                static fn(string $column): bool => $column !== 'active'
            ));
        }

        return $tables;
    }
}
