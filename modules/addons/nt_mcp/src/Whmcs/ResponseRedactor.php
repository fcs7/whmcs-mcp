<?php
namespace NtMcp\Whmcs;

/** Redação central de respostas de tools antes de devolver ao chamador MCP. */
final class ResponseRedactor
{
    /**
     * Chaves nunca legítimas numa resposta — removidas em qualquer
     * profundidade. `c` é o código de acesso público a um ticket em
     * `GetTickets`/`GetTicket` (dispensa autenticação — mesma classe de
     * `password`); nome curto, mas WHMCS não usa `c` como chave em nenhum
     * outro payload conhecido do addon.
     */
    private const ALWAYS_STRIP = ['password', 'password2', 'securityqans', 'c'];

    /**
     * Chaves cujo valor vazio o WHMCS serializa como `""` (string) quando a
     * lista está vazia, em vez de `[]`/`array()`. ALLOWLIST fechada — chave
     * fora daqui nunca é convertida, porque `""` também é o valor legítimo de
     * campo de TEXTO vazio (ex.: `notes`), e converter esses pra `[]` mudaria
     * o tipo do campo pra quem já lê como string.
     */
    private const EMPTY_STRING_MEANS_EMPTY_LIST = [
        'transactions', 'nameservers', 'renewals', 'frauddata', 'validationdata',
        'domains', 'services', 'addons', 'attachments',
    ];

    /** `0000-00-00[ 00:00:00]` — sentinela de "data nunca setada" do MySQL/WHMCS. */
    private const ZERO_DATE_PATTERN = '/^0000-00-00(?: 00:00:00)?\z/';

    /** Remove recursivamente chaves sensíveis (defense-in-depth). */
    public static function scrubSensitive(array &$data, int $depth = 0): void
    {
        if ($depth > 8) return;
        foreach ($data as $key => &$value) {
            if (in_array(strtolower((string) $key), self::ALWAYS_STRIP, true)) {
                unset($data[$key]);
                continue;
            }
            if (is_array($value)) {
                self::scrubSensitive($value, $depth + 1);
            }
        }
        unset($value);
    }

    /**
     * Corrige dois tipos que o WHMCS serializa de forma inconsistente e que
     * obrigam todo consumidor MCP a adivinhar: lista vazia como `""` (só nas
     * chaves da allowlist) e data nunca setada como `"0000-00-00..."` (em
     * QUALQUER chave — o padrão é inequívoco, não existe campo de texto livre
     * legítimo com esse valor exato).
     */
    public static function normalizeTypes(array &$data, int $depth = 0): void
    {
        if ($depth > 8) return;
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                self::normalizeTypes($value, $depth + 1);
                continue;
            }
            if ($value === '' && in_array(strtolower((string) $key), self::EMPTY_STRING_MEANS_EMPTY_LIST, true)) {
                $value = [];
                continue;
            }
            if (is_string($value) && preg_match(self::ZERO_DATE_PATTERN, $value) === 1) {
                $value = null;
            }
        }
        unset($value);
    }

    /**
     * `GetClientsDetails` empilha três problemas no mesmo payload:
     *
     *  1. A chave `client` repete TODOS os campos do nível raiz — mesmos
     *     valores, sem informação nova (medido: 66 chaves, zero diferença).
     *     É metade dos ~8,9 KB do payload. Removida.
     *  2. `lastlogin` vem pré-formatado em HTML pelo WHMCS
     *     (`"Date: 17/08/2026 10:19<br>IP Address: 1.2.3.4<br>Host: x"`),
     *     útil pra renderizar numa página, ruim pra um agente ler. Vira
     *     texto plano com `; ` no lugar de `<br>`; se o formato mudar e não
     *     bater no padrão esperado, o valor ORIGINAL é preservado — nunca
     *     inventa dado.
     *  3. `customfields1`..`customfields15` são a MESMA informação de
     *     `customfields` (array `{id, value}`), só que achatada em chaves
     *     numeradas sem id — confirmado campo a campo contra o mesmo
     *     cliente. `customfields` continua (rotular por `id` é trabalho
     *     separado, fora deste escopo).
     */
    public static function stripClientDetails(array &$result): void
    {
        self::scrubSensitive($result);
        unset($result['client']);
        for ($i = 1; $i <= 15; $i++) {
            unset($result['customfields' . $i]);
        }
        if (isset($result['lastlogin']) && is_string($result['lastlogin']) && $result['lastlogin'] !== '') {
            $plain = trim(str_ireplace('<br>', '; ', $result['lastlogin']), '; ');
            if ($plain !== '') {
                $result['lastlogin'] = $plain;
            }
        }
    }

    /** Remove password de products[].product[] (substitui os dois strip duplicados). */
    public static function stripProductPasswords(array &$result): void
    {
        if (isset($result['products']['product']) && is_array($result['products']['product'])) {
            foreach ($result['products']['product'] as &$p) {
                unset($p['password']);
            }
            unset($p);
        }
    }

    /**
     * Pay methods: ALLOWLIST alinhada ao payload real de GetPayMethods (WHMCS).
     * Qualquer campo fora desta lista é descartado — inclui remote_token,
     * card_number, cvv e tokens de gateway, que nunca são devolvidos.
     * card_last_four já é mascarado pelo próprio WHMCS (só 4 dígitos).
     */
    private const PAYMETHOD_SAFE_KEYS = [
        'id', 'type', 'description', 'gateway_name',
        'contact_type', 'contact_id', 'card_last_four', 'expiry_date',
        'start_date', 'issue_number', 'card_type', 'last_updated',
    ];
    public static function stripPayMethods(array &$result): void
    {
        if (!isset($result['paymethods']) || !is_array($result['paymethods'])) return;
        foreach ($result['paymethods'] as &$pm) {
            if (!is_array($pm)) continue;
            $safe = [];
            foreach (self::PAYMETHOD_SAFE_KEYS as $k) {
                if (array_key_exists($k, $pm)) $safe[$k] = $pm[$k];
            }
            $pm = $safe;
        }
        unset($pm);
    }

    /**
     * `GetOrders` embute `fraudoutput`: o JSON cru devolvido pelo módulo de
     * fraude (MaxMind), com geolocalização/distância/ISP do cliente — 3+ KB
     * por pedido, e nada disso é decisão do MCP tomar (o WHMCS já decidiu e
     * expõe o resultado em `fraudmodule`/`status`). Mantém o sinal, descarta
     * o dump. Cobre as duas formas de payload de `GetOrders`: lista
     * (`orders.order[]`, usada por `whmcs_list_orders`) e item único
     * (campos no topo, quando filtrado por `id` — `whmcs_get_order`).
     */
    public static function stripOrderFraudDump(array &$result): void
    {
        if (isset($result['orders']['order']) && is_array($result['orders']['order'])) {
            foreach ($result['orders']['order'] as &$order) {
                if (is_array($order)) unset($order['fraudoutput']);
            }
            unset($order);
            return;
        }
        unset($result['fraudoutput']);
    }
}
