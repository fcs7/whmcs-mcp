<?php
// src/Tools/SystemTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\DateNormalizer;
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

    #[McpTool(name: 'whmcs_get_activity_log', description: 'Obtém log de atividades do sistema')]
    public function getActivityLog(int $limitnum = 25, int $limitstart = 0, string $user = '', string $description = '', string $date = ''): string
    {
        $params = compact('limitnum', 'limitstart');
        if ($user !== '') $params['user'] = $user;
        if ($description !== '') $params['description'] = $description;
        if ($date !== '') $params['date'] = DateNormalizer::toWhmcsDate($date, 'date');
        return json_encode($this->api->call('GetActivityLog', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_admin_details', description: 'Obtém detalhes do administrador autenticado')]
    public function getAdminDetails(): string
    {
        return json_encode($this->api->call('GetAdminDetails', []), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_todo_items', description: 'Lista itens de tarefas administrativas (To-Do)')]
    public function getToDoItems(string $status = '', int $limitstart = 0, int $limitnum = 25): string
    {
        $params = ['limitstart' => $limitstart, 'limitnum' => $limitnum];
        if ($status !== '') $params['status'] = $status;
        return json_encode($this->api->call('GetToDoItems', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_update_todo_item', description: 'Atualiza um item To-Do existente')]
    public function updateToDoItem(int $itemid, string $status = '', string $title = '', string $description = '', string $duedate = '', int $adminid = 0): string
    {
        $params = ['itemid' => $itemid];
        if ($status !== '') $params['status'] = $status;
        if ($title !== '') $params['title'] = $title;
        if ($description !== '') $params['description'] = $description;
        if ($duedate !== '') $params['duedate'] = DateNormalizer::toWhmcsDate($duedate, 'duedate');
        if ($adminid > 0) $params['adminid'] = $adminid;
        return json_encode($this->api->call('UpdateToDoItem', $params), JSON_PRETTY_PRINT);
    }
}
