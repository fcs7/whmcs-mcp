<?php
// src/Tools/SystemTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use PhpMcp\Server\Attributes\McpTool;

class SystemTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_get_stats', description: 'Retorna estatísticas gerais do WHMCS (receita, clientes, tickets)')]
    public function getStats(): string
    {
        return json_encode($this->api->call('GetStats', []), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_send_email', description: 'Envia um email usando template do WHMCS')]
    public function sendEmail(int $id, string $messagename, string $customtype = 'general', string $customsubject = '', string $customvars = ''): string
    {
        $params = compact('id', 'messagename', 'customtype');
        if ($customsubject !== '') $params['customsubject'] = $customsubject;
        if ($customvars !== '') $params['customvars'] = $customvars;
        return json_encode($this->api->call('SendEmail', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_activity_log', description: 'Obtém log de atividades do sistema')]
    public function getActivityLog(int $limitnum = 25, int $limitstart = 0, string $user = '', string $description = '', string $date = ''): string
    {
        $params = compact('limitnum', 'limitstart');
        if ($user !== '') $params['user'] = $user;
        if ($description !== '') $params['description'] = $description;
        if ($date !== '') $params['date'] = $date;
        return json_encode($this->api->call('GetActivityLog', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_admin_details', description: 'Obtém detalhes do administrador autenticado')]
    public function getAdminDetails(): string
    {
        return json_encode($this->api->call('GetAdminDetails', []), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_currencies', description: 'Lista moedas configuradas no WHMCS')]
    public function getCurrencies(): string
    {
        return json_encode($this->api->call('GetCurrencies', []), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_email_templates', description: 'Lista templates de email disponíveis')]
    public function getEmailTemplates(string $type = '', string $language = ''): string
    {
        $params = [];
        if ($type !== '') $params['type'] = $type;
        if ($language !== '') $params['language'] = $language;
        return json_encode($this->api->call('GetEmailTemplates', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_payment_methods', description: 'Lista gateways de pagamento ativos')]
    public function getPaymentMethods(): string
    {
        return json_encode($this->api->call('GetPaymentMethods', []), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_todo_items', description: 'Lista itens de tarefas administrativas (To-Do)')]
    public function getToDoItems(string $status = '', int $limitstart = 0, int $limitnum = 25): string
    {
        $params = ['limitstart' => $limitstart, 'limitnum' => $limitnum];
        if ($status !== '') $params['status'] = $status;
        return json_encode($this->api->call('GetToDoItems', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_todo_statuses', description: 'Lista status disponíveis para To-Do items')]
    public function getToDoStatuses(): string
    {
        return json_encode($this->api->call('GetToDoItemStatuses', []), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_update_todo_item', description: 'Atualiza um item To-Do existente')]
    public function updateToDoItem(int $itemid, string $status = '', string $title = '', string $description = '', string $duedate = '', int $adminid = 0): string
    {
        $params = ['itemid' => $itemid];
        if ($status !== '') $params['status'] = $status;
        if ($title !== '') $params['title'] = $title;
        if ($description !== '') $params['description'] = $description;
        if ($duedate !== '') $params['duedate'] = $duedate;
        if ($adminid > 0) $params['adminid'] = $adminid;
        return json_encode($this->api->call('UpdateToDoItem', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_log_activity', description: 'Registra uma entrada no log de atividades')]
    public function logActivity(string $description, int $userid = 0): string
    {
        $params = ['description' => '[MCP-USER] ' . $description];
        if ($userid > 0) $params['userid'] = $userid;
        return json_encode($this->api->call('LogActivity', $params), JSON_PRETTY_PRINT);
    }
}
