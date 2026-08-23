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
 *  - id malformado             → a linha não prova identidade utilizável.
 *
 * As quatro são recusas DETERMINÍSTICAS e saem como `denied` (D12), com a mesma
 * mensagem: o chamador não descobre qual delas ocorreu. Uma falha REAL do
 * driver durante a consulta continua sendo `downstream`, propagada pelo port.
 *
 * Não existe fallback: nem para o admin global do addon, nem para o superadmin
 * `admin`, nem para um id seed. É a mesma postura do WO-7 na camada de auth,
 * aplicada à autoria do CRM.
 *
 * SEM CACHE POSITIVO
 * ------------------
 * A versão anterior memorizava o id resolvido. A revisão reproduziu o efeito:
 * a mesma instância continuava devolvendo `9` depois de o admin ser
 * desabilitado, executando zero queries. Como a interface promete "um admin
 * ATIVO", e não "um admin que estava ativo em algum momento desta request",
 * cada chamada revalida `disabled = 0`. O custo é um SELECT projetado em `id`
 * por operação de escrita — desprezível diante de autorizar autoria revogada.
 *
 * A consulta passa pelo mesmo `CrmQueryPort` fechado das demais: projeção
 * explícita (`id`), filtro por igualdade e `LIMIT 2` — o segundo registro
 * existe só para PROVAR a ambiguidade, nunca para ser usado.
 */
final class CapsuleAdminIdentityResolver implements AdminIdentityResolver
{
    public function __construct(
        private readonly CrmSchemaGuard $guard,
        private readonly CrmQueryPort $port,
    ) {
    }

    public function resolveActiveAdminId(string $username): int
    {
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

        return $id;
    }

    /**
     * O contexto é literal nosso e vai só para o diagnóstico; o chamador MCP
     * recebe `denied` e a correlação, sem saber qual condição negou.
     */
    private static function refuse(string $context): CrmException
    {
        return CrmException::denied(Diagnostics::report(
            Diagnostics::CATEGORY_ADMIN_LOOKUP,
            'crm_admin_identity_' . $context
        ));
    }
}
