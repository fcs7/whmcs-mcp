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

    #[McpTool(name: 'whmcs_domain_get_nameservers', description: 'Obtém nameservers atuais de um domínio')]
    public function domainGetNameservers(int $domainid): string
    {
        return json_encode($this->api->call('DomainGetNameservers', ['domainid' => $domainid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_domain_get_locking_status', description: 'Verifica status de bloqueio de transferência de um domínio')]
    public function domainGetLockingStatus(int $domainid): string
    {
        return json_encode($this->api->call('DomainGetLockingStatus', ['domainid' => $domainid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_domain_get_whois_info', description: 'Obtém informações WHOIS de um domínio registrado')]
    public function domainGetWhoisInfo(int $domainid): string
    {
        return json_encode($this->api->call('DomainGetWhoisInfo', ['domainid' => $domainid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_tld_pricing', description: 'Lista preços de TLDs disponíveis para registro')]
    public function getTldPricing(int $currencyid = 0): string
    {
        $params = [];
        if ($currencyid > 0) $params['currencyid'] = $currencyid;
        return json_encode($this->api->call('GetTLDPricing', $params), JSON_PRETTY_PRINT);
    }

}
