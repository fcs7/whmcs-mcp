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
            'sendemail' => $sendEmail ? 'true' : 'false',
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
            'sendemail' => $sendEmail ? 'true' : 'false',
        ]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_delete_order', description: 'Deleta permanentemente um pedido cancelado')]
    public function deleteOrder(int $orderid): string
    {
        return json_encode($this->api->call('DeleteOrder', ['orderid' => $orderid]), JSON_PRETTY_PRINT);
    }
}
