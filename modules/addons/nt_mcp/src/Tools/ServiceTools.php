<?php
// src/Tools/ServiceTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
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
        if (isset($result['products']['product'])) {
            foreach ($result['products']['product'] as &$p) {
                unset($p['password']);
            }
            unset($p);
        }
        return json_encode($result, JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_suspend_service', description: 'Suspende um serviço de hospedagem/servidor')]
    public function suspendService(int $serviceid, string $reason = ''): string
    {
        return json_encode($this->api->call('ModuleSuspend', ['serviceid' => $serviceid, 'suspendreason' => $reason]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_unsuspend_service', description: 'Reativa um serviço suspenso')]
    public function unsuspendService(int $serviceid): string
    {
        return json_encode($this->api->call('ModuleUnsuspend', ['serviceid' => $serviceid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_terminate_service', description: 'Termina/cancela definitivamente um serviço')]
    public function terminateService(int $serviceid): string
    {
        return json_encode($this->api->call('ModuleTerminate', ['serviceid' => $serviceid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_upgrade_service', description: 'Faz upgrade de plano de um serviço')]
    public function upgradeService(
        int $serviceid,
        int $newproductid,
        string $paymentmethod,
        string $newproductbillingcycle = 'monthly',
        string $type = 'product'
    ): string {
        return json_encode($this->api->call('UpgradeProduct', [
            'serviceid' => $serviceid,
            'type' => $type,
            'newproductid' => $newproductid,
            'newproductbillingcycle' => $newproductbillingcycle,
            'paymentmethod' => $paymentmethod,
        ]), JSON_PRETTY_PRINT);
    }
}
