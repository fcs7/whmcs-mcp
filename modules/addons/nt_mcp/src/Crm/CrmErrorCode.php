<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Enum FECHADO de falhas públicas do domínio CRM (D6/D12).
 *
 * Nenhum outro código pode sair da fronteira `MgCrmRepository`. O texto do
 * driver, o SQL, o nome da tabela e a mensagem do mgCRM2 morrem antes daqui:
 * o que atravessa é um destes sete tokens, escritos por nós.
 *
 * D12 fechou a lacuna que a revisão fria apontou. Antes, gate WRITE fechado
 * saía como `validation` (convidando automação a corrigir um campo que está
 * correto) e identidade OAuth determinística saía como `downstream`
 * (convidando retry infinito de uma recusa que nunca é transitória). Agora
 * ambas são `denied`, uma recusa estável e não-retentável, sem revelar QUAL
 * condição interna negou. A distinção operacional continua no contexto fechado
 * do diagnóstico e na correlação.
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

    /**
     * Recusa DETERMINÍSTICA (D12): gate WRITE fechado/inválido, ou username
     * OAuth que não resolve para exatamente um `tbladmins.id` ativo. Não expõe
     * qual das condições negou, e não é retentável.
     */
    case Denied = 'denied';

    /** Falha REAL e inesperada de banco/driver/metadata após as validações. */
    case Downstream = 'downstream';
}
