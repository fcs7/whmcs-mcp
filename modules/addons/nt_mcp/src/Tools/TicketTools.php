<?php
// src/Tools/TicketTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\ToolJson;
use Mcp\Capability\Attribute\McpTool;

class TicketTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_list_tickets', description: 'Lista tickets de suporte. O campo userid de cada ticket é o clientid de whmcs_get_client (userid=0 = guest).')]
    public function listTickets(int $clientid = 0, string $status = 'Open', int $limitnum = 25, int $deptid = 0, int $limitstart = 0, string $subject = ''): string
    {
        $params = ['limitnum' => $limitnum, 'status' => $status];
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($deptid > 0) $params['deptid'] = $deptid;
        if ($limitstart > 0) $params['limitstart'] = $limitstart;
        if ($subject !== '') $params['subject'] = $subject;
        $result = $this->api->call('GetTickets', $params);
        $this->addDisplayIds($result);
        return ToolJson::encode($result);
    }

    #[McpTool(name: 'whmcs_get_ticket', description: 'Obtém detalhes e histórico de um ticket. Use ticketid (id interno, ex.: 30) OU tid (número exibido, ex.: 084535) — informe exatamente um.')]
    public function getTicket(int $ticketid = 0, string $tid = ''): string
    {
        if (($ticketid > 0) === ($tid !== '')) {
            throw new \InvalidArgumentException('Informe exatamente um de: ticketid (id interno) ou tid (número exibido)');
        }
        $params = $ticketid > 0 ? ['ticketid' => $ticketid] : ['ticketnum' => $tid];
        $result = $this->api->call('GetTicket', $params);
        $this->addDisplayIds($result);
        return ToolJson::encode($result);
    }

    /**
     * A classe primária é WRITE. `notify_client` é um efeito ORTOGONAL: o
     * default é NÃO notificar (`noemail=true`); pedir `notify_client=true`
     * exige, além do gate WRITE, o gate COMMS — verificado centralmente em
     * LocalApiClient, não aqui, para que nenhuma refatoração (ou chamada
     * direta ao cliente local) contorne a autorização.
     */
    #[McpTool(name: 'whmcs_open_ticket', description: 'Abre um novo ticket de suporte. notify_client=true envia e-mail ao cliente e exige o gate COMMS; o default não notifica.')]
    public function openTicket(int $deptid, string $subject, string $message, int $clientid = 0, string $name = '', string $email = '', string $priority = 'Medium', int $serviceid = 0, int $domainid = 0, bool $markdown = false, bool $notify_client = false): string
    {
        $params = ['deptid' => $deptid, 'subject' => $subject, 'message' => $message, 'priority' => $priority];
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($name !== '') $params['name'] = $name;
        if ($email !== '') $params['email'] = $email;
        if ($serviceid > 0) $params['serviceid'] = $serviceid;
        if ($domainid > 0) $params['domainid'] = $domainid;
        if ($markdown) $params['markdown'] = true;
        if (!$notify_client) $params['noemail'] = true;
        return ToolJson::encode($this->api->call('OpenTicket', $params));
    }

    /** Mesma política de notificação de openTicket — ver nota acima. */
    #[McpTool(name: 'whmcs_reply_ticket', description: 'Adiciona resposta a um ticket existente. ticketid = id interno (campo id/ticketid da lista), NÃO o número #NNNNNN (tid). notify_client=true envia e-mail ao cliente e exige o gate COMMS; o default não notifica.')]
    public function replyTicket(int $ticketid, string $message, string $status = '', int $adminid = 0, string $adminusername = '', string $name = '', string $email = '', int $clientid = 0, bool $markdown = false, bool $notify_client = false): string
    {
        $params = compact('ticketid', 'message');
        if ($status !== '') $params['status'] = $status;
        if ($adminid > 0) $params['adminid'] = $adminid;
        if ($adminusername !== '') $params['adminusername'] = $adminusername;
        if ($name !== '') $params['name'] = $name;
        if ($email !== '') $params['email'] = $email;
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($markdown) $params['markdown'] = true;
        if (!$notify_client) $params['noemail'] = true;
        return ToolJson::encode($this->api->call('AddTicketReply', $params));
    }

    #[McpTool(name: 'whmcs_update_ticket', description: 'Atualiza status, prioridade ou departamento de um ticket. ticketid = id interno (campo id/ticketid da lista), NÃO o número #NNNNNN (tid).')]
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
        return ToolJson::encode($this->api->call('UpdateTicket', $params));
    }

    /** Adiciona display_id (valor de tid) quando disponível em tickets individuais ou em listas. */
    private function addDisplayIds(array &$result): void
    {
        // Resposta de listTickets: tickets.ticket é uma lista
        if (isset($result['tickets']['ticket']) && is_array($result['tickets']['ticket'])) {
            foreach ($result['tickets']['ticket'] as &$ticket) {
                if (isset($ticket['tid']) && $ticket['tid'] !== '') {
                    $ticket['display_id'] = $ticket['tid'];
                }
            }
        }
        // Resposta de getTicket: tid na raiz
        if (isset($result['tid']) && $result['tid'] !== '') {
            $result['display_id'] = $result['tid'];
        }
    }
}
