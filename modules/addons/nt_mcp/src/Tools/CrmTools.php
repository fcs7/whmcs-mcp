<?php
// src/Tools/CrmTools.php
namespace NtMcp\Tools;

use NtMcp\Crm\CrmException;
use NtMcp\Crm\MgCrmRepository;
use NtMcp\Whmcs\CapsuleClient;
use NtMcp\Whmcs\DateNormalizer;
use NtMcp\Whmcs\Diagnostics;
use PhpMcp\Server\Attributes\McpTool;
use WHMCS\Database\Capsule;

/**
 * As oito tools de CRM, em MIGRAÇÃO.
 *
 * Estado desta tranche (CRM-2): as QUATRO leituras já operam sobre o schema
 * real do mgCRM2 (`crm_*`), através de `MgCrmRepository`. As QUATRO escritas
 * continuam byte a byte como estavam, sobre o `CapsuleClient` e as tabelas
 * fictícias `mod_mgcrm_*` — elas seguem não-funcionais contra a instalação
 * real, e a sua migração é CRM-3. A remoção do legado é CRM-4.
 *
 * As duas rotas convivem de propósito: migrar leitura e escrita no mesmo
 * changeset misturaria uma mudança de contrato público (nomes de parâmetro,
 * forma da resposta) com uma mudança de efeito colateral, e nenhuma das duas
 * poderia ser revisada isoladamente.
 */
class CrmTools
{
    // ---------------------------------------------------------------
    // LEGADO — usado SOMENTE pelas quatro escritas, até CRM-3.
    //
    // Estes nomes não existem na instalação real (D3): o addon é o mgCRM2 e o
    // schema é `crm_resources`/`crm_followups`/`crm_notes`. Nenhuma LEITURA
    // desta classe os toca — a varredura mecânica da suíte prova isso método a
    // método.
    // ---------------------------------------------------------------
    private const TABLE_CONTACTS = 'mod_mgcrm_contacts';
    private const TABLE_FOLLOWUPS = 'mod_mgcrm_followups';
    private const TABLE_NOTES = 'mod_mgcrm_notes';

    private static ?bool $crmAvailable = null;

    public function __construct(
        private readonly CapsuleClient $capsule,
        private readonly MgCrmRepository $crm,
    ) {}

    private function ensureCrmAvailable(): void
    {
        if (self::$crmAvailable === null) {
            self::$crmAvailable = Capsule::schema()->hasTable(self::TABLE_CONTACTS);
        }
        if (!self::$crmAvailable) {
            throw new \RuntimeException(
                'CRM ModulesGarden module is not installed. '
                . 'Table "' . self::TABLE_CONTACTS . '" does not exist. '
                . 'Install the module or remove CRM tools from the MCP server.'
            );
        }
    }

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
     * incidente correlacionado no log do operador. Quem marca a resposta como
     * `isError: true` é `CrmReadBoundary`, no adapter.
     */
    private function read(callable $operation): string
    {
        try {
            return json_encode($operation(), JSON_PRETTY_PRINT);
        } catch (CrmException $e) {
            return json_encode($e->toPublicArray(), JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            $correlationId = Diagnostics::report(Diagnostics::CATEGORY_UNHANDLED, 'crm_read', $e);

            return json_encode(
                CrmException::downstream(
                    $correlationId,
                    Diagnostics::fingerprint($e->getMessage()),
                    get_class($e)
                )->toPublicArray(),
                JSON_PRETTY_PRINT
            );
        }
    }

    #[McpTool(
        name: 'whmcs_crm_list_contacts',
        description: 'Lista recursos (contatos/leads) do CRM mgCRM2, com paginacao. '
            . 'Filtros opcionais type_id e status_id vem dos catalogos ativos publicados por '
            . 'whmcs_crm_get_kanban; um id inexistente ou inativo devolve crm_catalog_invalid. '
            . 'Retorna items (projecao core), count (total sob o mesmo filtro), limit, offset e has_more. '
            . 'Registros removidos nao aparecem. Somente a ausencia do filtro (ou null) significa '
            . 'sem filtro: 0 e negativos sao recusados. limit maximo 100.'
    )]
    public function listContacts(
        ?int $type_id = null,
        ?int $status_id = null,
        int $limit = 25,
        int $offset = 0
    ): string {
        return $this->read(fn(): array => $this->crm->listResources($type_id, $status_id, $limit, $offset));
    }

    #[McpTool(
        name: 'whmcs_crm_get_contact',
        description: 'Obtem um recurso (contato/lead) do CRM mgCRM2 por resource_id. '
            . 'Retorna a projecao core em resource e os custom_fields normalizados '
            . '({field_id, name, value}), somente leitura, no maximo 100 campos. '
            . 'Recurso inexistente ou removido devolve crm_resource_not_found.'
    )]
    public function getContact(int $resource_id): string
    {
        return $this->read(fn(): array => $this->crm->getResource($resource_id));
    }

    #[McpTool(name: 'whmcs_crm_create_lead', description: 'Cria um novo lead no CRM ModulesGarden')]
    public function createLead(
        string $name,
        string $email,
        string $phone = '',
        string $company = '',
        string $notes = ''
    ): string {
        $this->ensureCrmAvailable();
        $id = $this->capsule->insert(self::TABLE_CONTACTS, [
            'type'    => 'lead',
            'name'    => $name,
            'email'   => $email,
            'phone'   => $phone,
            'company' => $company,
            'notes'   => $notes,
            'created' => date('Y-m-d H:i:s'),
        ]);
        return json_encode(['result' => 'success', 'id' => $id], JSON_PRETTY_PRINT);
    }

    /**
     * SECURITY FIX (F5 -- CVSS 8.1): Replace open-ended array $fields with
     * explicit named parameters.  The previous signature allowed callers to
     * write to arbitrary database columns (e.g. id, created, type) that
     * should not be mutable through the MCP interface.
     */
    #[McpTool(name: 'whmcs_crm_update_contact', description: 'Atualiza dados de um contato CRM')]
    public function updateContact(
        int $id,
        string $name = '',
        string $email = '',
        string $phone = '',
        string $company = '',
        string $notes = '',
        string $status = '',
        string $stage = ''
    ): string {
        $this->ensureCrmAvailable();
        $data = [];
        foreach (['name', 'email', 'phone', 'company', 'notes', 'status', 'stage'] as $field) {
            if ($$field !== '') {
                $data[$field] = $$field;
            }
        }

        if ($data === []) {
            return json_encode(['result' => 'error', 'message' => 'No fields provided for update.'], JSON_PRETTY_PRINT);
        }

        $count = $this->capsule->update(self::TABLE_CONTACTS, ['id' => $id], $data);
        return json_encode(['result' => 'success', 'rows_affected' => $count], JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_crm_add_followup', description: 'Adiciona um follow-up a um contato CRM')]
    public function addFollowup(int $contactId, string $note, string $duedate): string
    {
        $this->ensureCrmAvailable();
        // DÍVIDA CONHECIDA (T1 segue ABERTO por causa disto): a introspecção do
        // WHMCS de desenvolvimento provou que o addon CRM instalado é o mgCRM2 e
        // que seu schema real é `crm_resources`/`crm_followups`/`crm_notes` — as
        // tabelas `mod_mgcrm_*` usadas aqui NÃO existem, e `crm_followups` não
        // tem coluna `duedate` (tem `resource_id`, `type_id`, `status_id`,
        // `admin_id`, `description`, `date`). Este INSERT, portanto, não é
        // funcional contra a instalação real.
        //
        // O comportamento é preservado EXATAMENTE como estava, por decisão de
        // escopo: o realinhamento das 8 tools de CRM para o schema `crm_*` tem
        // ticket próprio. Não trate esta linha como corrigida.
        $id = $this->capsule->insert(self::TABLE_FOLLOWUPS, [
            'contact_id' => $contactId,
            'note'       => $note,
            'duedate'    => DateNormalizer::toWhmcsDate($duedate, 'duedate'),
            'created'    => date('Y-m-d H:i:s'),
        ]);
        return json_encode(['result' => 'success', 'id' => $id], JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_crm_add_note', description: 'Adiciona uma nota a um contato CRM')]
    public function addNote(int $contactId, string $note): string
    {
        $this->ensureCrmAvailable();
        $id = $this->capsule->insert(self::TABLE_NOTES, [
            'contact_id' => $contactId,
            'note'       => $note,
            'created'    => date('Y-m-d H:i:s'),
        ]);
        return json_encode(['result' => 'success', 'id' => $id], JSON_PRETTY_PRINT);
    }

    #[McpTool(
        name: 'whmcs_crm_list_followups',
        description: 'Lista follow-ups de um recurso do CRM mgCRM2, com paginacao. '
            . 'resource_id e obrigatorio; filtros opcionais type_id e status_id vem dos catalogos '
            . 'de follow-up publicados por whmcs_crm_get_kanban. Cada item traz type_name e '
            . 'status_name sempre resolvidos: um tipo/status desativado mantem o nome historico, '
            . 'e referencia removida ou ausente falha como downstream de integridade. '
            . 'Retorna items, count, limit, offset e has_more. limit maximo 100.'
    )]
    public function listFollowups(
        int $resource_id,
        ?int $type_id = null,
        ?int $status_id = null,
        int $limit = 25,
        int $offset = 0
    ): string {
        return $this->read(fn(): array => $this->crm->listFollowups(
            $resource_id,
            $type_id,
            $status_id,
            $limit,
            $offset
        ));
    }

    #[McpTool(
        name: 'whmcs_crm_get_kanban',
        description: 'Visao Kanban do CRM mgCRM2 e FONTE DOS CATALOGOS de id. '
            . 'Publica em catalogs os quatro catalogos ativos (resource_types, resource_statuses, '
            . 'followup_types, followup_statuses) mesmo quando nao ha nenhum recurso, e em lanes '
            . 'uma raia para CADA status de recurso ativo, com total exato, items limitados e '
            . 'has_more. Os catalogos sao completos, sem corte. '
            . 'Filtro opcional type_id restringe as raias a um tipo de recurso. '
            . 'limit_per_status maximo 25.'
    )]
    public function getKanban(?int $type_id = null, int $limit_per_status = 25): string
    {
        return $this->read(fn(): array => $this->crm->getKanban($type_id, $limit_per_status));
    }
}
