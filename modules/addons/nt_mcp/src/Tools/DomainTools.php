<?php
// src/Tools/DomainTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use PhpMcp\Server\Attributes\McpTool;

class DomainTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_list_domains', description: 'Lista domínios registrados no WHMCS')]
    public function listDomains(int $clientid = 0, string $status = ''): string
    {
        $params = [];
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($status !== '') $params['status'] = $status;
        return json_encode($this->api->call('GetClientsDomains', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_register_domain', description: 'Registra um novo domínio')]
    public function registerDomain(int $clientid, string $domain, int $regperiod = 1, string $nameserver1 = '', string $nameserver2 = ''): string
    {
        return json_encode($this->api->call('DomainRegister', compact('clientid', 'domain', 'regperiod', 'nameserver1', 'nameserver2')), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_renew_domain', description: 'Renova o registro de um domínio')]
    public function renewDomain(int $domainid, int $regperiod = 1): string
    {
        return json_encode($this->api->call('DomainRenew', compact('domainid', 'regperiod')), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_update_nameservers', description: 'Atualiza nameservers de um domínio')]
    public function updateNameservers(int $domainid, string $ns1, string $ns2, string $ns3 = '', string $ns4 = ''): string
    {
        return json_encode($this->api->call('DomainUpdateNameservers', array_filter(compact('domainid', 'ns1', 'ns2', 'ns3', 'ns4'))), JSON_PRETTY_PRINT);
    }
}
