<?php
// src/Tools/SupportInfoTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use PhpMcp\Server\Attributes\McpTool;

class SupportInfoTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_get_support_departments', description: 'Lista departamentos de suporte')]
    public function getSupportDepartments(bool $ignore_dept_assignments = false): string
    {
        $params = [];
        if ($ignore_dept_assignments) $params['ignore_dept_assignments'] = true;
        return json_encode($this->api->call('GetSupportDepartments', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_support_statuses', description: 'Lista status de tickets disponíveis')]
    public function getSupportStatuses(int $deptid = 0): string
    {
        $params = [];
        if ($deptid > 0) $params['deptid'] = $deptid;
        return json_encode($this->api->call('GetSupportStatuses', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_ticket_counts', description: 'Obtém contagem de tickets por status')]
    public function getTicketCounts(bool $includeCountsByStatus = true): string
    {
        $params = [];
        if ($includeCountsByStatus) $params['includeCountsByStatus'] = true;
        return json_encode($this->api->call('GetTicketCounts', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_ticket_notes', description: 'Obtém notas internas de um ticket')]
    public function getTicketNotes(int $ticketid): string
    {
        return json_encode($this->api->call('GetTicketNotes', ['ticketid' => $ticketid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_ticket_predefined_cats', description: 'Lista categorias de respostas predefinidas')]
    public function getTicketPredefinedCats(): string
    {
        return json_encode($this->api->call('GetTicketPredefinedCats', []), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_ticket_predefined_replies', description: 'Lista respostas predefinidas de tickets')]
    public function getTicketPredefinedReplies(int $catid = 0): string
    {
        $params = [];
        if ($catid > 0) $params['catid'] = $catid;
        return json_encode($this->api->call('GetTicketPredefinedReplies', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_ticket_attachment', description: 'Obtém anexo de ticket (type: 0=ticket, 1=reply, 2=note)')]
    public function getTicketAttachment(int $relatedid, int $type = 0, int $index = 0): string
    {
        return json_encode($this->api->call('GetTicketAttachment', [
            'relatedid' => $relatedid,
            'type' => $type,
            'index' => $index,
        ]), JSON_PRETTY_PRINT);
    }
}
