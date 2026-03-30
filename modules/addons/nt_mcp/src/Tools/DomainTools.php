<?php
// src/Tools/DomainTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use PhpMcp\Server\Attributes\McpTool;

class DomainTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_list_domains', description: 'Lista domínios registrados no WHMCS')]
    public function listDomains(int $clientid = 0, string $status = '', int $limitnum = 25, int $limitstart = 0, int $domainid = 0): string
    {
        $params = ['limitnum' => $limitnum];
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($status !== '') $params['status'] = $status;
        if ($limitstart > 0) $params['limitstart'] = $limitstart;
        if ($domainid > 0) $params['domainid'] = $domainid;
        return json_encode($this->api->call('GetClientsDomains', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_register_domain', description: 'Registra um novo domínio')]
    public function registerDomain(int $clientid, string $domain, int $regperiod = 1, string $nameserver1 = '', string $nameserver2 = '', int $domainid = 0): string
    {
        $params = ['regperiod' => $regperiod];
        if ($domain !== '') $params['domain'] = $domain;
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($domainid > 0) $params['domainid'] = $domainid;
        if ($nameserver1 !== '') $params['nameserver1'] = $nameserver1;
        if ($nameserver2 !== '') $params['nameserver2'] = $nameserver2;
        return json_encode($this->api->call('DomainRegister', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_renew_domain', description: 'Renova o registro de um domínio')]
    public function renewDomain(int $domainid, int $regperiod = 1): string
    {
        return json_encode($this->api->call('DomainRenew', compact('domainid', 'regperiod')), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_update_nameservers', description: 'Atualiza nameservers de um domínio')]
    public function updateNameservers(int $domainid, string $ns1, string $ns2, string $ns3 = '', string $ns4 = ''): string
    {
        $params = ['domainid' => $domainid, 'ns1' => $ns1, 'ns2' => $ns2];
        if ($ns3 !== '') $params['ns3'] = $ns3;
        if ($ns4 !== '') $params['ns4'] = $ns4;
        return json_encode($this->api->call('DomainUpdateNameservers', $params), JSON_PRETTY_PRINT);
    }
}
