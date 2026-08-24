<?php
// src/Tools/DomainTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\ResponseRedactor;
use NtMcp\Whmcs\ToolJson;
use Mcp\Capability\Attribute\McpTool;

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
        return ToolJson::encode($this->api->call('GetClientsDomains', $params));
    }

    #[McpTool(name: 'whmcs_domain_get_nameservers', description: 'Obtém nameservers atuais de um domínio')]
    public function domainGetNameservers(int $domainid): string
    {
        return ToolJson::encode($this->api->call('DomainGetNameservers', ['domainid' => $domainid]));
    }

    #[McpTool(name: 'whmcs_domain_get_locking_status', description: 'Verifica status de bloqueio de transferência de um domínio')]
    public function domainGetLockingStatus(int $domainid): string
    {
        return ToolJson::encode($this->api->call('DomainGetLockingStatus', ['domainid' => $domainid]));
    }

    #[McpTool(name: 'whmcs_domain_get_whois_info', description: 'Obtém informações WHOIS de um domínio registrado')]
    public function domainGetWhoisInfo(int $domainid): string
    {
        return ToolJson::encode($this->api->call('DomainGetWhoisInfo', ['domainid' => $domainid]));
    }

    #[McpTool(name: 'whmcs_get_tld_pricing', description: 'Lista preços de TLDs disponíveis. Preço 0.00 no WHMCS significa não configurado e é omitido; veja years_available. grace_period/redemption ausentes têm not_configured=true.')]
    public function getTldPricing(int $currencyid = 0): string
    {
        $params = [];
        if ($currencyid > 0) $params['currencyid'] = $currencyid;
        $result = $this->api->call('GetTLDPricing', $params);
        ResponseRedactor::normalizeTldPricing($result);
        return ToolJson::encode($result);
    }
}
