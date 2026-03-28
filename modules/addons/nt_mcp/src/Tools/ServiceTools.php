<?php
// src/Tools/ServiceTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use PhpMcp\Server\Attributes\McpTool;

class ServiceTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_list_services', description: 'Lista serviços/produtos ativos de um cliente')]
    public function listServices(int $clientid = 0, string $status = ''): string
    {
        $params = [];
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($status !== '') $params['status'] = $status;
        return json_encode($this->api->call('GetClientsProducts', $params), JSON_PRETTY_PRINT);
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
