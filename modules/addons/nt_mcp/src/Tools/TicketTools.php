<?php
// src/Tools/TicketTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use PhpMcp\Server\Attributes\McpTool;

class TicketTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_list_tickets', description: 'Lista tickets de suporte')]
    public function listTickets(int $clientid = 0, string $status = 'Open', int $limitnum = 25): string
    {
        $params = ['limitnum' => $limitnum, 'status' => $status];
        if ($clientid > 0) $params['clientid'] = $clientid;
        return json_encode($this->api->call('GetTickets', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_ticket', description: 'Obtém detalhes e histórico de um ticket')]
    public function getTicket(int $ticketid): string
    {
        return json_encode($this->api->call('GetTicket', ['ticketid' => $ticketid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_open_ticket', description: 'Abre um novo ticket de suporte')]
    public function openTicket(int $clientid, int $deptid, string $subject, string $message, string $priority = 'Medium'): string
    {
        return json_encode($this->api->call('OpenTicket', compact('clientid', 'deptid', 'subject', 'message', 'priority')), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_reply_ticket', description: 'Adiciona resposta a um ticket existente')]
    public function replyTicket(int $ticketid, string $message, string $status = 'Customer-Reply'): string
    {
        return json_encode($this->api->call('AddTicketReply', compact('ticketid', 'message', 'status')), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_update_ticket', description: 'Atualiza status, prioridade ou departamento de um ticket')]
    public function updateTicket(int $ticketid, string $status = '', string $priority = '', int $deptid = 0): string
    {
        $params = ['ticketid' => $ticketid];
        if ($status !== '') $params['status'] = $status;
        if ($priority !== '') $params['priority'] = $priority;
        if ($deptid > 0) $params['deptid'] = $deptid;
        return json_encode($this->api->call('UpdateTicket', $params), JSON_PRETTY_PRINT);
    }
}
