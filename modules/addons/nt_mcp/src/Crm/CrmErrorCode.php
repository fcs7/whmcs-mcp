<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Enum FECHADO de falhas públicas do domínio CRM (D6).
 *
 * Nenhum outro código pode sair da fronteira `MgCrmRepository`. O texto do
 * driver, o SQL, o nome da tabela e a mensagem do mgCRM2 morrem antes daqui:
 * o que atravessa é um destes seis tokens, escritos por nós.
 *
 * A ausência de um código próprio para "admin não resolvido" é DELIBERADA. A
 * decisão de requisitos fixou exatamente esta lista, e ampliá-la por conta
 * própria reabriria a superfície de erro sem revisão. Falha de identidade
 * administrativa é registrada com contexto de diagnóstico próprio
 * (`crm_admin_identity`) e sai como `downstream` — o operador distingue pelo
 * correlation id, o chamador MCP não ganha um código novo.
 */
enum CrmErrorCode: string
{
    /** Addon ausente, ou tabela mínima de uma capacidade não existe. */
    case Unavailable = 'crm_unavailable';

    /** Tabela existe, mas as colunas exigidas pelo contrato não correspondem. */
    case SchemaMismatch = 'crm_schema_mismatch';

    /** `resource_id` inexistente ou soft-deleted. */
    case ResourceNotFound = 'crm_resource_not_found';

    /** Tipo/status ausente, inativo, soft-deleted ou de catálogo incompatível. */
    case CatalogInvalid = 'crm_catalog_invalid';

    /** Input inválido, vazio ou data fora da gramática. */
    case Validation = 'validation';

    /** Falha inesperada de banco/driver/identidade após as validações. */
    case Downstream = 'downstream';
}
