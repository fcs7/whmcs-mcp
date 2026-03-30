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
}
