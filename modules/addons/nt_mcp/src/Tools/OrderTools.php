<?php
// src/Tools/OrderTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use PhpMcp\Server\Attributes\McpTool;

class OrderTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_list_orders', description: 'Lista pedidos com filtros opcionais')]
    public function listOrders(string $status = '', int $clientid = 0, int $limitnum = 25, int $limitstart = 0): string
    {
        $params = ['limitnum' => $limitnum];
        if ($limitstart > 0) $params['limitstart'] = $limitstart;
        if ($status !== '') $params['status'] = $status;
        if ($clientid > 0) $params['userid'] = $clientid;
        return json_encode($this->api->call('GetOrders', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_order', description: 'Obtém detalhes de um pedido específico')]
    public function getOrder(int $orderid): string
    {
        return json_encode($this->api->call('GetOrders', ['id' => $orderid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_accept_order', description: 'Aceita um pedido pendente e provisiona os serviços')]
    public function acceptOrder(int $orderid, bool $sendEmail = true, bool $autosetup = false, int $serverid = 0): string
    {
        $params = [
            'orderid' => $orderid,
            'sendemail' => $sendEmail,
        ];
        if ($autosetup) $params['autosetup'] = true;
        if ($serverid > 0) $params['serverid'] = $serverid;
        return json_encode($this->api->call('AcceptOrder', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_cancel_order', description: 'Cancela um pedido')]
    public function cancelOrder(int $orderid, bool $sendEmail = false): string
    {
        return json_encode($this->api->call('CancelOrder', [
            'orderid' => $orderid,
            'sendemail' => $sendEmail,
        ]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_delete_order', description: 'Deleta permanentemente um pedido cancelado')]
    public function deleteOrder(int $orderid): string
    {
        return json_encode($this->api->call('DeleteOrder', ['orderid' => $orderid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_add_order', description: 'Cria um novo pedido para um cliente')]
    public function addOrder(
        int $clientid,
        string $paymentmethod,
        array $pid = [],
        array $domain = [],
        array $billingcycle = [],
        string $promocode = '',
        bool $noinvoice = false,
        bool $noinvoiceemail = false,
        bool $noemail = false
    ): string {
        $params = ['clientid' => $clientid, 'paymentmethod' => $paymentmethod];
        if (empty($pid) && empty($domain)) {
            throw new \InvalidArgumentException('At least one product (pid) or domain is required');
        }
        foreach ($pid as $i => $p) {
            $params["pid[{$i}]"] = (int) $p;
        }
        foreach ($domain as $i => $d) {
            $params["domain[{$i}]"] = (string) $d;
        }
        foreach ($billingcycle as $i => $b) {
            $params["billingcycle[{$i}]"] = (string) $b;
        }
        if ($promocode !== '') $params['promocode'] = $promocode;
        if ($noinvoice) $params['noinvoice'] = true;
        if ($noinvoiceemail) $params['noinvoiceemail'] = true;
        if ($noemail) $params['noemail'] = true;
        return json_encode($this->api->call('AddOrder', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_order_statuses', description: 'Lista os status de pedidos disponíveis')]
    public function getOrderStatuses(): string
    {
        return json_encode($this->api->call('GetOrderStatuses', []), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_products', description: 'Lista produtos/serviços disponíveis no catálogo')]
    public function getProducts(int $pid = 0, int $gid = 0, string $module = ''): string
    {
        $params = [];
        if ($pid > 0) $params['pid'] = $pid;
        if ($gid > 0) $params['gid'] = $gid;
        if ($module !== '') $params['module'] = $module;
        return json_encode($this->api->call('GetProducts', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_promotions', description: 'Lista promoções/cupons disponíveis')]
    public function getPromotions(string $code = '', int $id = 0): string
    {
        $params = [];
        if ($code !== '') $params['code'] = $code;
        if ($id > 0) $params['id'] = $id;
        return json_encode($this->api->call('GetPromotions', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_pending_order', description: 'Coloca um pedido em status pendente')]
    public function pendingOrder(int $orderid): string
    {
        return json_encode($this->api->call('PendingOrder', ['orderid' => $orderid]), JSON_PRETTY_PRINT);
    }
}
