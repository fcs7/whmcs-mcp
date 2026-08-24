<?php
// src/Tools/CrmTools.php
namespace NtMcp\Tools;

use NtMcp\Crm\CrmException;
use NtMcp\Crm\MgCrmRepository;
use NtMcp\Whmcs\Diagnostics;
use NtMcp\Whmcs\ToolJson;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/** Quatro leituras do schema real do ModulesGarden CRM (mgCRM2). */
class CrmTools
{
    public function __construct(private readonly MgCrmRepository $crm) {}

    /**
     * Fronteira de erro das leituras — nada sai daqui sem ser canônico.
     *
     * A convenção do addon é NÃO usar try/catch nas tools, porque o framework
     * converte exceção em erro MCP. Para as READ de CRM isso é exatamente o
     * que NÃO pode acontecer: o formatter da SDK monta o texto com
     * `'Tool execution failed: ' . $e->getMessage() . ' (Type: ...)'`, e a
     * revisão fria reproduziu SQLSTATE, senha, path e e-mail chegando ao
     * chamador por esse caminho.
     *
     * Então a captura é total e deliberada:
     *
     *  - `CrmException` já É o contrato público fechado (D6/D12) e vira o
     *    envelope com `error_code` e correlação;
     *  - qualquer outro `Throwable` é registrado no diagnóstico fechado
     *    (categoria, classe e fingerprint, nunca a mensagem) e vira
     *    `downstream` — o chamador recebe só a correlação.
     *
     * Um bug de programação, portanto, também não vaza texto: ele vira um
     * incidente correlacionado no log do operador. A marcação `isError: true`
     * é feita aqui mesmo, via `CallToolResult::error()` (nativo do SDK).
     */
    private function read(callable $operation): string|CallToolResult
    {
        try {
            return ToolJson::encode($operation());
        } catch (CrmException $e) {
            return self::errorResult($e->toPublicArray());
        } catch (\Throwable $e) {
            $correlationId = Diagnostics::report(Diagnostics::CATEGORY_UNHANDLED, 'crm_read', $e);

            return self::errorResult(
                CrmException::downstream(
                    $correlationId,
                    Diagnostics::fingerprint($e->getMessage()),
                    get_class($e)
                )->toPublicArray()
            );
        }
    }

    /**
     * Envelope canônico de erro como `CallToolResult` com `isError: true`.
     * O texto é o MESMO JSON de antes; só a marcação muda — o SDK publica o
     * resultado sem tocar no conteúdo, então nenhuma mensagem crua entra aqui.
     */
    private static function errorResult(array $envelope): CallToolResult
    {
        return CallToolResult::error([new TextContent(ToolJson::encode($envelope))]);
    }

    #[McpTool(
        name: 'whmcs_crm_list_contacts',
        description: 'Requer ModulesGarden CRM (mgCRM). Lista recursos (contatos/leads) do CRM mgCRM2, com paginacao. '
            . 'Filtros opcionais type_id e status_id vem dos catalogos ativos publicados por '
            . 'whmcs_crm_get_kanban; um id inexistente ou inativo devolve crm_catalog_invalid. '
            . 'Retorna items (projecao core), count (total sob o mesmo filtro), limit, offset e has_more. '
            . 'Registros removidos nao aparecem. Somente a ausencia do filtro (ou null) significa '
            . 'sem filtro: 0 e negativos sao recusados. limit maximo 100. '
            . 'limitnum e aceito como sinonimo de limit (compat com as demais tools).'
    )]
    #[Schema(additionalProperties: false)]
    public function listContacts(
        #[Schema(minimum: 1)] ?int $type_id = null,
        #[Schema(minimum: 1)] ?int $status_id = null,
        #[Schema(minimum: 1)] int $limit = 25,
        #[Schema(minimum: 0)] int $offset = 0,
        #[Schema(minimum: 1)] ?int $limitnum = null
    ): string|CallToolResult {
        // Alias de `limit`: as tools LocalAPI usam `limitnum` (nome real do
        // parametro do WHMCS) e o cliente as vezes manda o mesmo nome aqui —
        // `additionalProperties: false` rejeitaria isso como schema invalido
        // sem nenhuma pista do porque. Aceito como sinonimo em vez de recusar.
        return $this->read(fn(): array => $this->crm->listResources($type_id, $status_id, $limitnum ?? $limit, $offset));
    }

    #[McpTool(
        name: 'whmcs_crm_get_contact',
        description: 'Requer ModulesGarden CRM (mgCRM). Obtem um recurso (contato/lead) do CRM mgCRM2 por resource_id. '
            . 'Retorna a projecao core em resource e os custom_fields normalizados '
            . '({field_id, name, value}), somente leitura e completos: todos os valores do '
            . 'recurso sao devolvidos, e acima de 5000 valores a leitura falha fechado em vez '
            . 'de truncar. '
            . 'Recurso inexistente ou removido devolve crm_resource_not_found.'
    )]
    #[Schema(additionalProperties: false)]
    public function getContact(#[Schema(minimum: 1)] int $resource_id): string|CallToolResult
    {
        return $this->read(fn(): array => $this->crm->getResource($resource_id));
    }

    #[McpTool(
        name: 'whmcs_crm_list_followups',
        description: 'Requer ModulesGarden CRM (mgCRM). Lista follow-ups de um recurso do CRM mgCRM2, com paginacao. '
            . 'resource_id e obrigatorio; filtros opcionais type_id e status_id vem dos catalogos '
            . 'de follow-up publicados por whmcs_crm_get_kanban. Cada item traz type_name e '
            . 'status_name sempre resolvidos: um tipo/status desativado mantem o nome historico, '
            . 'e referencia removida ou ausente falha como downstream de integridade. '
            . 'Retorna items, count, limit, offset e has_more. limit maximo 100. '
            . 'limitnum e aceito como sinonimo de limit (compat com as demais tools).'
    )]
    #[Schema(additionalProperties: false)]
    public function listFollowups(
        #[Schema(minimum: 1)] int $resource_id,
        #[Schema(minimum: 1)] ?int $type_id = null,
        #[Schema(minimum: 1)] ?int $status_id = null,
        #[Schema(minimum: 1)] int $limit = 25,
        #[Schema(minimum: 0)] int $offset = 0,
        #[Schema(minimum: 1)] ?int $limitnum = null
    ): string|CallToolResult {
        // Alias de `limit` — ver comentario em listContacts().
        return $this->read(fn(): array => $this->crm->listFollowups(
            $resource_id,
            $type_id,
            $status_id,
            $limitnum ?? $limit,
            $offset
        ));
    }

    #[McpTool(
        name: 'whmcs_crm_get_kanban',
        description: 'Requer ModulesGarden CRM (mgCRM). Visao Kanban do CRM mgCRM2 e FONTE DOS CATALOGOS de id. '
            . 'Publica em catalogs os quatro catalogos ativos (resource_types, resource_statuses, '
            . 'followup_types, followup_statuses) mesmo quando nao ha nenhum recurso, e em lanes '
            . 'a PAGINA de raias pedida, cada uma com total exato, items limitados e has_more. '
            . 'Os quatro catalogos sao sempre completos; somente lanes e paginado, por '
            . 'status_limit (1..25, default 25) e status_offset (default 0). A resposta traz '
            . 'status_count, status_limit, status_offset e status_has_more, entao todas as raias '
            . 'sao alcancaveis avancando o offset. '
            . 'Filtro opcional type_id restringe as raias a um tipo de recurso. '
            . 'limit_per_status controla apenas os items dentro de cada raia; maximo 25.'
    )]
    #[Schema(additionalProperties: false)]
    public function getKanban(
        #[Schema(minimum: 1)] ?int $type_id = null,
        #[Schema(minimum: 1)] int $limit_per_status = 25,
        #[Schema(minimum: 1, maximum: 25)] int $status_limit = 25,
        #[Schema(minimum: 0)] int $status_offset = 0
    ): string|CallToolResult {
        return $this->read(fn(): array => $this->crm->getKanban(
            $type_id,
            $limit_per_status,
            $status_limit,
            $status_offset
        ));
    }
}
