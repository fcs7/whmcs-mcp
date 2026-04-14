<?php
// src/Tools/BillingTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use PhpMcp\Server\Attributes\McpTool;

class BillingTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_list_invoices', description: 'Lista faturas com filtros')]
    public function listInvoices(int $clientid = 0, string $status = '', int $limitstart = 0, int $limitnum = 25): string
    {
        $params = ['limitstart' => $limitstart, 'limitnum' => $limitnum];
        if ($clientid > 0) $params['userid'] = $clientid;
        if ($status !== '') $params['status'] = $status;
        return json_encode($this->api->call('GetInvoices', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_invoice', description: 'Obtém detalhes de uma fatura')]
    public function getInvoice(int $invoiceid): string
    {
        return json_encode($this->api->call('GetInvoice', ['invoiceid' => $invoiceid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_transactions', description: 'Lista transações financeiras')]
    public function getTransactions(int $clientid = 0, string $transid = ''): string
    {
        $params = [];
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($transid !== '') $params['transid'] = $transid;
        return json_encode($this->api->call('GetTransactions', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_credits', description: 'Lista créditos de um cliente')]
    public function getCredits(int $clientid): string
    {
        return json_encode($this->api->call('GetCredits', ['clientid' => $clientid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_pay_methods', description: 'Lista métodos de pagamento salvos de um cliente')]
    public function getPayMethods(int $clientid): string
    {
        $result = $this->api->call('GetPayMethods', ['clientid' => $clientid]);
        if (isset($result['paymethods'])) {
            foreach ($result['paymethods'] as &$pm) {
                unset($pm['gateway_customer_id'], $pm['token'], $pm['card_number']);
            }
            unset($pm);
        }
        return json_encode($result, JSON_PRETTY_PRINT);
    }
}
