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

    #[McpTool(name: 'whmcs_create_invoice', description: 'Cria uma nova fatura manualmente')]
    public function createInvoice(
        int $userid,
        string $date,
        array $itemdescription,
        array $itemamount,
        string $status = '',
        string $duedate = '',
        string $paymentmethod = '',
        bool $sendinvoice = false,
        string $notes = '',
        bool $draft = false,
        array $itemtaxed = []
    ): string {
        $params = ['userid' => $userid, 'date' => $date];
        if (count($itemdescription) !== count($itemamount)) {
            throw new \InvalidArgumentException('itemdescription and itemamount must have the same number of elements');
        }
        if (!empty($itemtaxed) && count($itemtaxed) !== count($itemdescription)) {
            throw new \InvalidArgumentException('itemtaxed must have the same number of elements as itemdescription');
        }
        foreach ($itemdescription as $i => $desc) {
            $params["itemdescription[{$i}]"] = (string) $desc;
        }
        foreach ($itemamount as $i => $amount) {
            $params["itemamount[{$i}]"] = (float) $amount;
        }
        foreach ($itemtaxed as $i => $taxed) {
            $params["itemtaxed[{$i}]"] = $taxed ? 1 : 0;
        }
        if ($status !== '') $params['status'] = $status;
        if ($duedate !== '') $params['duedate'] = $duedate;
        if ($paymentmethod !== '') $params['paymentmethod'] = $paymentmethod;
        if ($sendinvoice) $params['sendinvoice'] = true;
        if ($notes !== '') $params['notes'] = $notes;
        if ($draft) $params['draft'] = true;
        return json_encode($this->api->call('CreateInvoice', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_add_payment', description: 'Registra pagamento em uma fatura')]
    public function addPayment(
        int $invoiceid,
        string $transid,
        float $amount,
        string $gateway,
        string $date = '',
        ?float $fees = null,
        bool $noemail = false
    ): string {
        $params = compact('invoiceid', 'transid', 'amount', 'gateway');
        if ($date !== '') $params['date'] = $date;
        if ($fees !== null) $params['fees'] = $fees;
        if ($noemail) $params['noemail'] = true;
        return json_encode($this->api->call('AddInvoicePayment', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_update_invoice', description: 'Atualiza status ou dados de uma fatura')]
    public function updateInvoice(
        int $invoiceid,
        string $status = '',
        string $duedate = '',
        string $paymentmethod = '',
        string $notes = '',
        string $date = '',
        ?float $credit = null,
        bool $publish = false,
        bool $publishandsendemail = false
    ): string {
        $params = ['invoiceid' => $invoiceid];
        if ($status !== '') $params['status'] = $status;
        if ($duedate !== '') $params['duedate'] = $duedate;
        if ($paymentmethod !== '') $params['paymentmethod'] = $paymentmethod;
        if ($notes !== '') $params['notes'] = $notes;
        if ($date !== '') $params['date'] = $date;
        if ($credit !== null) $params['credit'] = $credit;
        if ($publish) $params['publish'] = true;
        if ($publishandsendemail) $params['publishandsendemail'] = true;
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

    #[McpTool(name: 'whmcs_add_credit', description: 'Adiciona crédito à conta de um cliente')]
    public function addCredit(int $clientid, float $amount, string $description = ''): string
    {
        if ($amount <= 0.0) {
            throw new \InvalidArgumentException('Credit amount must be greater than zero');
        }
        $params = ['clientid' => $clientid, 'amount' => $amount];
        if ($description !== '') $params['description'] = $description;
        return json_encode($this->api->call('AddCredit', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_credits', description: 'Lista créditos de um cliente')]
    public function getCredits(int $clientid): string
    {
        return json_encode($this->api->call('GetCredits', ['clientid' => $clientid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_add_transaction', description: 'Registra uma transação financeira')]
    public function addTransaction(
        string $paymentmethod,
        ?float $amountin = null,
        ?float $amountout = null,
        int $userid = 0,
        int $invoiceid = 0,
        string $description = '',
        string $date = '',
        string $transid = '',
        ?float $fees = null,
        ?float $rate = null
    ): string {
        $params = ['paymentmethod' => $paymentmethod];
        if ($amountin !== null) $params['amountin'] = $amountin;
        if ($amountout !== null) $params['amountout'] = $amountout;
        if ($userid > 0) $params['userid'] = $userid;
        if ($invoiceid > 0) $params['invoiceid'] = $invoiceid;
        if ($description !== '') $params['description'] = $description;
        if ($date !== '') $params['date'] = $date;
        if ($transid !== '') $params['transid'] = $transid;
        if ($fees !== null) $params['fees'] = $fees;
        if ($rate !== null) $params['rate'] = $rate;
        return json_encode($this->api->call('AddTransaction', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_update_transaction', description: 'Atualiza uma transação existente')]
    public function updateTransaction(
        int $transactionid,
        string $paymentmethod = '',
        ?float $amountin = null,
        ?float $amountout = null,
        int $userid = 0,
        int $invoiceid = 0,
        string $description = '',
        string $date = '',
        string $transid = '',
        ?float $fees = null,
        ?float $rate = null
    ): string {
        $params = ['transactionid' => $transactionid];
        if ($paymentmethod !== '') $params['paymentmethod'] = $paymentmethod;
        if ($amountin !== null) $params['amountin'] = $amountin;
        if ($amountout !== null) $params['amountout'] = $amountout;
        if ($userid > 0) $params['userid'] = $userid;
        if ($invoiceid > 0) $params['invoiceid'] = $invoiceid;
        if ($description !== '') $params['description'] = $description;
        if ($date !== '') $params['date'] = $date;
        if ($transid !== '') $params['transid'] = $transid;
        if ($fees !== null) $params['fees'] = $fees;
        if ($rate !== null) $params['rate'] = $rate;
        return json_encode($this->api->call('UpdateTransaction', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_add_billable_item', description: 'Adiciona item faturável a um cliente')]
    public function addBillableItem(
        int $clientid,
        string $description,
        float $amount,
        string $invoiceaction = 'noinvoice',
        string $duedate = '',
        bool $recur = false,
        int $recurcycle = 0,
        string $recurfor = ''
    ): string {
        if (!in_array($invoiceaction, ['noinvoice', 'nextcron', 'nextinvoice', 'recur'], true)) {
            throw new \InvalidArgumentException('invoiceaction must be one of: noinvoice, nextcron, nextinvoice, recur');
        }
        $params = ['clientid' => $clientid, 'description' => $description, 'amount' => $amount, 'invoiceaction' => $invoiceaction];
        if ($duedate !== '') $params['duedate'] = $duedate;
        if ($recur) $params['recur'] = 1;
        if ($recurcycle > 0) $params['recurcycle'] = $recurcycle;
        if ($recurfor !== '') $params['recurfor'] = $recurfor;
        return json_encode($this->api->call('AddBillableItem', $params), JSON_PRETTY_PRINT);
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
