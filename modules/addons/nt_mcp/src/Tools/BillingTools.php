<?php
// src/Tools/BillingTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use PhpMcp\Server\Attributes\McpTool;

class BillingTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_list_invoices', description: 'Lista faturas com filtros')]
    public function listInvoices(int $clientid = 0, string $status = '', int $limitnum = 25): string
    {
        $params = ['limitnum' => $limitnum];
        if ($clientid > 0) $params['userid'] = $clientid;
        if ($status !== '') $params['status'] = $status;
        return json_encode($this->api->call('GetInvoices', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_invoice', description: 'Obtém detalhes de uma fatura')]
    public function getInvoice(int $invoiceid): string
    {
        return json_encode($this->api->call('GetInvoice', ['invoiceid' => $invoiceid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_create_invoice', description: 'Cria uma nova fatura manualmente')]
    public function createInvoice(int $userid, string $date, array $itemdescription, array $itemamount): string
    {
        return json_encode($this->api->call('CreateInvoice', [
            'userid' => $userid,
            'date'   => $date,
            'itemdescription' => $itemdescription,
            'itemamount'      => $itemamount,
        ]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_add_payment', description: 'Registra pagamento em uma fatura')]
    public function addPayment(int $invoiceid, string $transid, float $amount, string $gateway): string
    {
        return json_encode($this->api->call('AddInvoicePayment', compact('invoiceid', 'transid', 'amount', 'gateway')), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_update_invoice', description: 'Atualiza status ou dados de uma fatura')]
    public function updateInvoice(int $invoiceid, string $status = '', string $duedate = ''): string
    {
        $params = ['invoiceid' => $invoiceid];
        if ($status !== '') $params['status'] = $status;
        if ($duedate !== '') $params['duedate'] = $duedate;
        return json_encode($this->api->call('UpdateInvoice', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_transactions', description: 'Lista transações financeiras')]
    public function getTransactions(int $clientid = 0, string $transid = ''): string
    {
        $params = [];
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($transid !== '') $params['transid'] = $transid;
        return json_encode($this->api->call('GetTransactions', $params), JSON_PRETTY_PRINT);
    }
}
