<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Resolve o username do admin vinculado ao token OAuth para um `tbladmins.id`
 * ATIVO. Injetável — o repositório nunca fala com `tbladmins` diretamente.
 *
 * D11: `admin_id` jamais vem do chamador MCP. Esta é a única origem possível da
 * autoria de notas/follow-ups e da atribuição inicial do recurso.
 */
interface AdminIdentityResolver
{
    /**
     * @return int id do admin ativo
     * @throws CrmException quando ausente, inativo, ambíguo ou irresolúvel
     */
    public function resolveActiveAdminId(string $username): int;
}
