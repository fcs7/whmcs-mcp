<?php
// src/Tools/SupportInfoTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\ToolJson;
use Mcp\Capability\Attribute\McpTool;

class SupportInfoTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_get_support_departments', description: 'Lista departamentos de suporte')]
    public function getSupportDepartments(bool $ignore_dept_assignments = false): string
    {
        $params = [];
        if ($ignore_dept_assignments) $params['ignore_dept_assignments'] = true;
        return ToolJson::encode($this->api->call('GetSupportDepartments', $params));
    }

    #[McpTool(name: 'whmcs_get_support_statuses', description: 'Lista status de tickets disponíveis')]
    public function getSupportStatuses(int $deptid = 0): string
    {
        $params = [];
        if ($deptid > 0) $params['deptid'] = $deptid;
        return ToolJson::encode($this->api->call('GetSupportStatuses', $params));
    }

    #[McpTool(name: 'whmcs_get_ticket_counts', description: 'Obtém contagem de tickets por status')]
    public function getTicketCounts(bool $includeCountsByStatus = true): string
    {
        $params = [];
        if ($includeCountsByStatus) $params['includeCountsByStatus'] = true;
        return ToolJson::encode($this->api->call('GetTicketCounts', $params));
    }
}
