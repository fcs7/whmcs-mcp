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

    #[McpTool(name: 'whmcs_cancel_order', description: 'Cancela um pedido')]
    public function cancelOrder(int $orderid, bool $sendEmail = false): string
    {
        return json_encode($this->api->call('CancelOrder', [
            'orderid' => $orderid,
            'sendemail' => $sendEmail,
        ]), JSON_PRETTY_PRINT);
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
