<?php
// src/Tools/OrderTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\AuditMetadata;
use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\ResponseRedactor;
use NtMcp\Whmcs\ToolJson;
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
        return ToolJson::encode($result);
    }

    #[McpTool(name: 'whmcs_get_order', description: 'Obtém detalhes de um pedido específico')]
    public function getOrder(int $orderid): string
    {
        $result = $this->api->call('GetOrders', ['id' => $orderid]);
        ResponseRedactor::stripOrderFraudDump($result);
        return ToolJson::encode($result);
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

            return ToolJson::encode([
                'result' => 'error',
                'orderid' => $orderid,
                'message' => 'Cancellation requires confirm=true',
            ]);
        }

        $response = $this->api->call('CancelOrder', [
            'orderid' => $orderid,
            'noemail' => true,
        ]);
        if (!isset($response['orderid'])) {
            $response['orderid'] = $orderid;
        }

        return ToolJson::encode($response);
    }

    #[McpTool(name: 'whmcs_pending_order', description: 'Coloca um pedido em status pendente')]
    public function pendingOrder(int $orderid): string
    {
        return ToolJson::encode($this->api->call('PendingOrder', ['orderid' => $orderid]));
    }

    /**
     * O `description` de cada produto é HTML de landing page — o catálogo real
     * devolvia ~126 KB por chamada, quase tudo markup. O default entrega
     * `description_plain` (texto corrido truncado); `full_description=true`
     * devolve o HTML original intacto, para quem realmente precisa dele.
     *
     * `fields=lite` reduz o payload removendo customfields, configoptions, product_url
     * e mantendo apenas chaves essenciais (pid, gid, name, type, module, paytype,
     * description_plain, pricing reduzido). Ciclos com preço negativo são sempre
     * removidos (ambos os modes).
     *
     * `product_url` só é retornado quando `include_urls=true` (e `fields=full`);
     * por padrão é removido para reduzir tamanho do payload.
     */
    #[McpTool(name: 'whmcs_get_products', description: 'Lista o catálogo de produtos/serviços com opções de filtro, paginação local e modo lite. Por padrão a descrição vem como texto truncado em description_plain; use full_description=true para o HTML completo. Use fields=lite para reduzir payload (sem customfields, configoptions, product_url). product_url só aparece com fields=full e include_urls=true.')]
    public function getProducts(
        int $gid = 0,
        int $pid = 0,
        string $module = '',
        bool $full_description = false,
        string $fields = 'lite',
        int $limit = 20,
        int $limitstart = 0,
        bool $include_urls = false
    ): string {
        if (!in_array($fields, ['lite', 'full'], true)) {
            throw new \InvalidArgumentException("fields deve ser 'lite' ou 'full', recebido: " . var_export($fields, true));
        }
        // Pedir o HTML completo só faz sentido com o produto inteiro.
        if ($full_description) {
            $fields = 'full';
        }
        if ($limit < 1 || $limit > 100) {
            $limit = \max(1, \min(100, $limit));
        }
        if ($limitstart < 0) {
            $limitstart = 0;
        }

        $params = [];
        if ($gid > 0) $params['gid'] = $gid;
        if ($pid > 0) $params['pid'] = $pid;
        if ($module !== '') $params['module'] = $module;
        $result = $this->api->call('GetProducts', $params);
        ResponseRedactor::stripProductPasswords($result);
        if (!$full_description) {
            ResponseRedactor::summariseProductDescriptions($result);
        }
        ResponseRedactor::removeNegativePrices($result);
        if ($fields === 'lite') {
            ResponseRedactor::productLiteView($result);
        } elseif (!$include_urls) {
            $this->stripProductUrls($result);
        }
        ResponseRedactor::paginateProducts($result, $limit, $limitstart, $gid === 0 && $pid === 0 && $module === '');
        return ToolJson::encode($result);
    }

    /**
     * Remove product_url de cada produto do resultado (reduz payload).
     */
    private function stripProductUrls(array &$result): void
    {
        if (!isset($result['products']['product']) || !is_array($result['products']['product'])) {
            return;
        }

        foreach ($result['products']['product'] as $key => &$product) {
            if (is_array($product)) {
                unset($product['product_url']);
            }
        }
        unset($product);
    }

    #[McpTool(name: 'whmcs_get_order_statuses', description: 'Lista os status de pedido configurados no WHMCS, com a contagem de pedidos em cada um')]
    public function getOrderStatuses(): string
    {
        return ToolJson::encode($this->api->call('GetOrderStatuses', []));
    }

    /**
     * `GetPromotions` sem filtro devolve o catálogo inteiro de promoções; o
     * filtro por código existe para conferir uma promoção específica sem trazer
     * o resto.
     */
    #[McpTool(name: 'whmcs_get_promotions', description: 'Lista as promoções/cupons configurados no WHMCS, opcionalmente filtrando por código')]
    public function getPromotions(string $code = ''): string
    {
        $params = [];
        if ($code !== '') $params['code'] = $code;
        return ToolJson::encode($this->api->call('GetPromotions', $params));
    }
}
