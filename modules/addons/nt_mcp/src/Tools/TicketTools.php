<?php
// src/Tools/TicketTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use PhpMcp\Server\Attributes\McpTool;

class TicketTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_list_tickets', description: 'Lista tickets de suporte')]
    public function listTickets(int $clientid = 0, string $status = 'Open', int $limitnum = 25, int $deptid = 0, int $limitstart = 0, string $subject = ''): string
    {
        $params = ['limitnum' => $limitnum, 'status' => $status];
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($deptid > 0) $params['deptid'] = $deptid;
        if ($limitstart > 0) $params['limitstart'] = $limitstart;
        if ($subject !== '') $params['subject'] = $subject;
        return json_encode($this->api->call('GetTickets', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_ticket', description: 'Obtém detalhes e histórico de um ticket')]
    public function getTicket(int $ticketid): string
    {
        return json_encode($this->api->call('GetTicket', ['ticketid' => $ticketid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_open_ticket', description: 'Abre um novo ticket de suporte')]
    public function openTicket(int $deptid, string $subject, string $message, int $clientid = 0, string $name = '', string $email = '', string $priority = 'Medium', int $serviceid = 0, int $domainid = 0, bool $markdown = false, bool $noemail = false): string
    {
        $params = ['deptid' => $deptid, 'subject' => $subject, 'message' => $message, 'priority' => $priority];
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($name !== '') $params['name'] = $name;
        if ($email !== '') $params['email'] = $email;
        if ($serviceid > 0) $params['serviceid'] = $serviceid;
        if ($domainid > 0) $params['domainid'] = $domainid;
        if ($markdown) $params['markdown'] = true;
        if ($noemail) $params['noemail'] = true;
        return json_encode($this->api->call('OpenTicket', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_reply_ticket', description: 'Adiciona resposta a um ticket existente')]
    public function replyTicket(int $ticketid, string $message, string $status = '', int $adminid = 0, string $adminusername = '', string $name = '', string $email = '', int $clientid = 0, bool $markdown = false, bool $noemail = false): string
    {
        $params = compact('ticketid', 'message');
        if ($status !== '') $params['status'] = $status;
        if ($adminid > 0) $params['adminid'] = $adminid;
        if ($adminusername !== '') $params['adminusername'] = $adminusername;
        if ($name !== '') $params['name'] = $name;
        if ($email !== '') $params['email'] = $email;
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($markdown) $params['markdown'] = true;
        if ($noemail) $params['noemail'] = true;
        return json_encode($this->api->call('AddTicketReply', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_update_ticket', description: 'Atualiza status, prioridade ou departamento de um ticket')]
    public function updateTicket(int $ticketid, string $status = '', string $priority = '', int $deptid = 0, string $subject = '', ?int $flag = null, string $cc = '', string $message = ''): string
    {
        $params = ['ticketid' => $ticketid];
        if ($status !== '') $params['status'] = $status;
        if ($priority !== '') $params['priority'] = $priority;
        if ($deptid > 0) $params['deptid'] = $deptid;
        if ($subject !== '') $params['subject'] = $subject;
        if ($flag !== null) $params['flag'] = $flag;
        if ($cc !== '') $params['cc'] = $cc;
        if ($message !== '') $params['message'] = $message;
        return json_encode($this->api->call('UpdateTicket', $params), JSON_PRETTY_PRINT);
    }
}
