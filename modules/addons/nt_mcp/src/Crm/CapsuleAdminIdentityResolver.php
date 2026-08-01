<?php

declare(strict_types=1);

namespace NtMcp\Crm;

use NtMcp\Whmcs\Diagnostics;

/**
 * Resolução real, sobre `tbladmins`, com quatro recusas explícitas:
 *
 *  - username vazio/só espaço  → sem identidade, sem efeito;
 *  - nenhuma linha ativa       → admin ausente ou desabilitado;
 *  - mais de uma linha         → AMBIGUIDADE. Escolher a primeira atribuiria
 *                                autoria a um admin arbitrário;
 *  - falha do driver           → fail-closed, com diagnóstico estrutural.
 *
 * Não existe fallback: nem para o admin global do addon, nem para o superadmin
 * `admin`, nem para um id seed. É a mesma postura do WO-7 na camada de auth,
 * aplicada à autoria do CRM.
 *
 * A consulta passa pelo mesmo `CrmQueryPort` fechado das demais: projeção
 * explícita (`id`), filtro por igualdade e `LIMIT 2` — o segundo registro
 * existe só para PROVAR a ambiguidade, nunca para ser usado.
 */
final class CapsuleAdminIdentityResolver implements AdminIdentityResolver
{
    /** @var array<string, int> */
    private array $cache = [];

    public function __construct(
        private readonly CrmSchemaGuard $guard,
        private readonly CrmQueryPort $port,
    ) {
    }

    public function resolveActiveAdminId(string $username): int
    {
        if (isset($this->cache[$username])) {
            return $this->cache[$username];
        }

        if (trim($username) === '') {
            throw self::refuse('empty_username');
        }

        $this->guard->assert(CrmCapability::AdminIdentity);

        $rows = $this->port->selectRows(new CrmSelect(
            table: CrmSchema::TABLE_ADMINS,
            columns: [CrmSchema::COLUMN_ID],
            conditions: ['username' => $username, 'disabled' => 0],
            order: [[CrmSchema::COLUMN_ID, 'asc']],
            limit: 2,
        ));

        if (count($rows) !== 1) {
            throw self::refuse($rows === [] ? 'admin_not_active' : 'admin_ambiguous');
        }

        $id = $rows[0][CrmSchema::COLUMN_ID] ?? null;

        if (!is_int($id) && !(is_string($id) && preg_match('/^[1-9]\d{0,17}\z/', $id) === 1)) {
            throw self::refuse('admin_id_malformed');
        }

        $id = (int) $id;
        if ($id < 1) {
            throw self::refuse('admin_id_malformed');
        }

        return $this->cache[$username] = $id;
    }

    /**
     * O contexto é literal nosso e vai só para o diagnóstico; o chamador MCP
     * recebe apenas `downstream` e a correlação. O conjunto de códigos públicos
     * do CRM é fechado e não tem entrada para identidade administrativa — ver a
     * nota de `CrmErrorCode`.
     */
    private static function refuse(string $context): CrmException
    {
        $correlationId = Diagnostics::report(
            Diagnostics::CATEGORY_ADMIN_LOOKUP,
            'crm_admin_identity_' . $context
        );

        return CrmException::downstream($correlationId);
    }
}
