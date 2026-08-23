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
    /** 39 KB deixa margem para o framing HTTP/JSON-RPC e garante corpo < 40 KB. */
    private const PRODUCT_LITE_MCP_BUDGET_BYTES = 39000;

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
     * `product_url` só é retornado quando `include_urls=true` (e `fields=full`),
     * sempre origin-relative; por padrão é removido. No modo lite, a página
     * pode devolver menos itens que `limit` para manter o envelope MCP abaixo
     * de 40 KB; `numreturned` e `next_limitstart` permitem continuar sem gaps.
     */
    #[McpTool(name: 'whmcs_get_products', description: 'Lista o catálogo de produtos/serviços com paginação local e modo lite. O default traz description_plain e limita o envelope MCP a 40 KB, podendo retornar menos itens que limit; continue por next_limitstart. Use full_description=true para HTML completo. product_url só aparece com fields=full e include_urls=true, sempre como URL relativa.')]
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
        } else {
            ResponseRedactor::relativizeProductUrls($result);
        }
        ResponseRedactor::paginateProducts($result, $limit, $limitstart, $gid === 0 && $pid === 0 && $module === '');

        if ($fields === 'lite') {
            return $this->encodeLiteProductsWithinBudget($result);
        }

        return ToolJson::encode($result);
    }

    /**
     * O SDK envolve o JSON retornado pela tool como string em
     * `result.content[0].text`; portanto quotes/unicode são escapados uma
     * segunda vez. Mede exatamente esse envelope, usando id inteiro máximo
     * como margem, e encurta a página pelo fim até caber.
     */
    private function encodeLiteProductsWithinBudget(array &$result): string
    {
        $canTrim = isset($result['products']['product'])
            && is_array($result['products']['product'])
            && array_is_list($result['products']['product']);
        $capped = false;

        while (true) {
            $this->syncProductPageMetadata($result);
            if ($capped) {
                $result['payload_capped'] = true;
            } else {
                unset($result['payload_capped']);
            }

            $json = ToolJson::encodeCompact($result);
            if (self::estimatedMcpBodyBytes($json) <= self::PRODUCT_LITE_MCP_BUDGET_BYTES) {
                return $json;
            }

            if ($canTrim && count($result['products']['product']) > 1) {
                array_pop($result['products']['product']);
                $capped = true;
                continue;
            }

            // Caso patológico: um único pricing multimoeda ainda estoura o
            // teto. Mantém o produto e sinaliza a única informação omitida.
            if ($canTrim && count($result['products']['product']) === 1 && isset($result['products']['product'][0]['pricing'])) {
                unset($result['products']['product'][0]['pricing']);
                $result['products']['product'][0]['pricing_omitted_for_size'] = true;
                $capped = true;
                continue;
            }

            // Strings anormais fora dos campos já limitados não podem furar o
            // contrato de tamanho. Full+pid continua disponível como opt-in.
            return ToolJson::encodeCompact([
                'result' => 'error',
                'error_code' => 'product_payload_too_large',
                'message' => 'Produto excede o limite do modo lite; filtre por pid e use fields=full.',
            ]);
        }
    }

    private function syncProductPageMetadata(array &$result): void
    {
        $products = $result['products']['product'] ?? [];
        $returned = is_array($products) && array_is_list($products) ? count($products) : 0;
        $start = max(0, (int) ($result['limitstart'] ?? 0));
        $total = max(0, (int) ($result['totalresults'] ?? 0));

        $result['numreturned'] = $returned;
        if ($start + $returned < $total) {
            $result['next_limitstart'] = $start + $returned;
        } else {
            unset($result['next_limitstart']);
        }
    }

    private static function estimatedMcpBodyBytes(string $toolJson): int
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => PHP_INT_MAX,
            'result' => [
                'content' => [['type' => 'text', 'text' => $toolJson]],
                'isError' => false,
            ],
        ], JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($body) ? strlen($body) : PHP_INT_MAX;
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
