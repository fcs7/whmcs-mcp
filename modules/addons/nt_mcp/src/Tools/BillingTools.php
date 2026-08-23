<?php
// src/Tools/BillingTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\ResponseRedactor;
use NtMcp\Whmcs\ToolJson;
use Mcp\Capability\Attribute\McpTool;

class BillingTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_list_invoices', description: 'Lista faturas com filtros. invoicenum vazio = numeração sequencial desligada; datepaid é o dado gravado no WHMCS (pode ser incoerente em bases de teste).')]
    public function listInvoices(int $clientid = 0, string $status = '', int $limitstart = 0, int $limitnum = 25): string
    {
        $params = ['limitstart' => $limitstart, 'limitnum' => $limitnum];
        if ($clientid > 0) $params['userid'] = $clientid;
        if ($status !== '') $params['status'] = $status;
        return ToolJson::encode($this->api->call('GetInvoices', $params));
    }

    #[McpTool(name: 'whmcs_get_invoice', description: 'Obtém detalhes de uma fatura. invoicenum vazio = numeração sequencial desligada; datepaid é o dado gravado no WHMCS (pode ser incoerente em bases de teste).')]
    public function getInvoice(int $invoiceid): string
    {
        return ToolJson::encode($this->api->call('GetInvoice', ['invoiceid' => $invoiceid]));
    }

    #[McpTool(name: 'whmcs_get_transactions', description: 'Lista transações financeiras')]
    public function getTransactions(int $clientid = 0, string $transid = ''): string
    {
        $params = [];
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($transid !== '') $params['transid'] = $transid;
        return ToolJson::encode($this->api->call('GetTransactions', $params));
    }

    #[McpTool(name: 'whmcs_get_credits', description: 'Lista créditos de um cliente')]
    public function getCredits(int $clientid): string
    {
        return ToolJson::encode($this->api->call('GetCredits', ['clientid' => $clientid]));
    }

    #[McpTool(name: 'whmcs_get_pay_methods', description: 'Lista métodos de pagamento salvos de um cliente')]
    public function getPayMethods(int $clientid): string
    {
        $result = $this->api->call('GetPayMethods', ['clientid' => $clientid]);
        ResponseRedactor::stripPayMethods($result);
        return ToolJson::encode($result);
    }
}
