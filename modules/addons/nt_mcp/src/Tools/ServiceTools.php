<?php
// src/Tools/ServiceTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\ResponseRedactor;
use PhpMcp\Server\Attributes\McpTool;

class ServiceTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_list_services', description: 'Lista serviços/produtos de um cliente (requer clientid)')]
    public function listServices(int $clientid, string $status = '', int $limitnum = 25, int $limitstart = 0, int $pid = 0): string
    {
        $params = ['clientid' => $clientid, 'limitnum' => $limitnum];
        if ($status !== '') $params['status'] = $status;
        if ($limitstart > 0) $params['limitstart'] = $limitstart;
        if ($pid > 0) $params['pid'] = $pid;
        $result = $this->api->call('GetClientsProducts', $params);
        ResponseRedactor::stripProductPasswords($result);
        return json_encode($result, JSON_PRETTY_PRINT);
    }

}
