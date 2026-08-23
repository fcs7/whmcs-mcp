<?php
// src/Tools/OrderTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\AuditMetadata;
use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\ResponseRedactor;
use Mcp\Capability\Attribute\McpTool;

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
        $result = $this->api->call('GetOrders', $params);
        ResponseRedactor::stripOrderFraudDump($result);
        return json_encode($result, JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_order', description: 'Obtém detalhes de um pedido específico')]
    public function getOrder(int $orderid): string
    {
        $result = $this->api->call('GetOrders', ['id' => $orderid]);
        ResponseRedactor::stripOrderFraudDump($result);
        return json_encode($result, JSON_PRETTY_PRINT);
    }

    /**
     * DESTRUCTIVE. O cancelamento é irreversível pela API, portanto exige o gate
     * DESTRUCTIVE (desligado por padrão) E `confirm=true` como defesa adicional.
     * A tool não expõe `cancelsub` (cancelar a assinatura no gateway é um efeito
     * separado que não cabe nesta superfície) e força `noemail=true`: nenhuma
     * notificação ao cliente sai deste caminho.
     */
    #[McpTool(name: 'whmcs_cancel_order', description: 'Cancela um pedido. Irreversível: exige confirm=true e o gate DESTRUCTIVE. Não notifica o cliente.')]
    public function cancelOrder(int $orderid, bool $confirm): string
    {
        if ($confirm !== true) {
            // m1: esta recusa retorna ANTES do cliente central, então precisa
            // auditar aqui — senão a tentativa não deixa rastro nenhum.
            LocalApiClient::auditLog(
                ActivityEvent::CONFIRM_REQUIRED,
                AuditMetadata::ids(['orderid' => $orderid])
            );

            return json_encode([
                'result' => 'error',
                'orderid' => $orderid,
                'message' => 'Cancellation requires confirm=true',
            ], JSON_PRETTY_PRINT);
        }

        $response = $this->api->call('CancelOrder', [
            'orderid' => $orderid,
            'noemail' => true,
        ]);
        if (!isset($response['orderid'])) {
            $response['orderid'] = $orderid;
        }

        return json_encode($response, JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_pending_order', description: 'Coloca um pedido em status pendente')]
    public function pendingOrder(int $orderid): string
    {
        return json_encode($this->api->call('PendingOrder', ['orderid' => $orderid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_products', description: 'Lista o catálogo de produtos/serviços do WHMCS, opcionalmente filtrado por grupo')]
    public function getProducts(int $gid = 0, int $pid = 0, string $module = ''): string
    {
        $params = [];
        if ($gid > 0) $params['gid'] = $gid;
        if ($pid > 0) $params['pid'] = $pid;
        if ($module !== '') $params['module'] = $module;
        $result = $this->api->call('GetProducts', $params);
        ResponseRedactor::stripProductPasswords($result);
        return json_encode($result, JSON_PRETTY_PRINT);
    }
}
