<?php
// src/Tools/CrmTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\CapsuleClient;
use PhpMcp\Server\Attributes\McpTool;
use WHMCS\Database\Capsule;

class CrmTools
{
    // Confirmar nomes das tabelas no banco de dados do WHMCS
    private const TABLE_CONTACTS = 'mod_mgcrm_contacts';
    private const TABLE_FOLLOWUPS = 'mod_mgcrm_followups';
    private const TABLE_NOTES = 'mod_mgcrm_notes';

    private static ?bool $crmAvailable = null;

    public function __construct(private readonly CapsuleClient $capsule) {}

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

    #[McpTool(name: 'whmcs_crm_list_contacts', description: 'Lista contatos/leads do CRM ModulesGarden')]
    public function listContacts(string $type = '', int $limit = 25, int $offset = 0): string
    {
        $this->ensureCrmAvailable();
        $where = $type !== '' ? ['type' => $type] : [];
        return json_encode($this->capsule->select(self::TABLE_CONTACTS, $where, ['*'], $limit, $offset), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_crm_get_contact', description: 'Obtém detalhes de um contato CRM')]
    public function getContact(int $id): string
    {
        $this->ensureCrmAvailable();
        $contacts = $this->capsule->select(self::TABLE_CONTACTS, ['id' => $id], ['*'], 1);
        return json_encode($contacts[0] ?? null, JSON_PRETTY_PRINT);
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
        $id = $this->capsule->insert(self::TABLE_FOLLOWUPS, [
            'contact_id' => $contactId,
            'note'       => $note,
            'duedate'    => $duedate,
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

    #[McpTool(name: 'whmcs_crm_list_followups', description: 'Lista follow-ups de um contato CRM')]
    public function listFollowups(int $contactId, int $limit = 25): string
    {
        $this->ensureCrmAvailable();
        return json_encode($this->capsule->select(self::TABLE_FOLLOWUPS, ['contact_id' => $contactId], ['*'], $limit), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_crm_get_kanban', description: 'Retorna visão Kanban dos contatos agrupados por estágio')]
    public function getKanban(): string
    {
        $this->ensureCrmAvailable();
        $contacts = $this->capsule->select(self::TABLE_CONTACTS, [], ['*'], 500);
        $kanban = [];
        foreach ($contacts as $contact) {
            $stage = $contact['status'] ?? $contact['stage'] ?? 'Unknown';
            $kanban[$stage][] = $contact;
        }
        return json_encode($kanban, JSON_PRETTY_PRINT);
    }
}
