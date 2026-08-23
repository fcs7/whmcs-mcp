<?php
namespace NtMcp\Whmcs;

/** Redação central de respostas de tools antes de devolver ao chamador MCP. */
final class ResponseRedactor
{
    /** Teto de profundidade de recursão, compartilhado por todas as passadas. */
    private const MAX_DEPTH = 8;

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

    /**
     * Chaves de IDENTIFICADOR que o WHMCS emite como `""` quando não há valor
     * (`transid` de transação sem id de gateway, `domainid`/`serviceid`/`pid`
     * de linha que não referencia produto). `""` num campo de id não é "id
     * vazio", é ausência — e obriga todo consumidor a tratar dois valores
     * falsy diferentes. ALLOWLIST fechada de propósito: heurística de sufixo
     * `id` pegaria campo de texto livre cujo nome por acaso termina em "id".
     */
    private const EMPTY_STRING_MEANS_NULL_ID = [
        'transid', 'domainid', 'serviceid', 'pid', 'orderid', 'invoiceid',
        'clientid', 'userid', 'contactid', 'addonid', 'ticketid', 'quoteid',
        'projectid', 'taskid', 'relid', 'gatewayid',
    ];

    /**
     * Chaves cujo valor é um JSON SERIALIZADO dentro do payload (string), e não
     * o dado em si — `orderdata` chega como `"[]"`. Decodificar aqui evita que
     * cada consumidor tenha que adivinhar quais campos precisam de um segundo
     * `json_decode`. ALLOWLIST fechada; decodificação que falhe PRESERVA o
     * valor original (nunca inventa dado).
     *
     * `fraudoutput` fica fora de propósito: é o dump do MaxMind que
     * `stripOrderFraudDump()` descarta — expandi-lo seria trabalho jogado fora.
     */
    private const JSON_STRING_KEYS = ['orderdata'];

    /**
     * Sentinelas de "data nunca setada". Além do `0000-00-00[ 00:00:00]` cru do
     * MySQL, o WHMCS devolve várias datas já FORMATADAS no locale da instalação
     * (é o caso de `validuntil`/`datecreated`/`datesent` em `GetQuotes`), e aí a
     * data-zero vira `00/00/0000`. Todas as formas são inequívocas — não existe
     * texto livre legítimo com esse valor exato.
     */
    private const ZERO_DATE_PATTERN =
        '/^(?:0000-00-00(?:[ T]00:00:00(?:\.0+)?Z?)?|00\/00\/0000|00-00-0000|00\.00\.0000)\z/';

    /**
     * Chaves de coleção que o WHMCS OMITE da resposta quando o resultado é
     * vazio, em vez de devolver lista vazia. Um consumidor que faça
     * `response.contacts` recebe "undefined" e não consegue distinguir "sem
     * contatos" de "campo que não existe nessa versão".
     *
     * Mapa por COMANDO e allowlist fechada: inserir uma chave com nome errado
     * criaria um campo fantasma — pior que a ausência. Estender só com nome
     * confirmado no payload real.
     *
     * @var array<string, array<int, string>>
     */
    private const GUARANTEED_LIST_KEYS = [
        'GetContacts'      => ['contacts'],
        'GetToDoItems'     => ['todoitems'],
        'GetClientGroups'  => ['groups'],
        'GetClientsAddons' => ['addons'],
        'GetPromotions'    => ['promotions'],
    ];

    /**
     * Pipeline ÚNICO de saída, aplicado a toda resposta de sucesso da LocalAPI.
     *
     * A ORDEM é parte do contrato de segurança:
     *
     *  1. `materialize()` — objetos viram array/escalar e JSON-string vira
     *     array. Esta passada CRIA dados que ainda não existiam como array.
     *  2. `normalizeTypes()` — corrige os tipos inconsistentes do WHMCS.
     *  3. `ensureListKeys()` — devolve as coleções vazias que o WHMCS omite.
     *  4. `scrubSensitive()` — SEMPRE por último.
     *
     * O scrub tem que ver o payload já materializado: antes desta ordem ele
     * rodava primeiro e só descia em `is_array`, então um `password` guardado
     * dentro de um objeto (ou dentro de um campo JSON-string) nunca era
     * visitado e atravessava intacto.
     */
    public static function normalizeResponse(array &$data, string $command = ''): void
    {
        $data = self::materialize($data);
        self::normalizeTypes($data);
        if ($command !== '') {
            self::ensureListKeys($data, $command);
        }
        self::scrubSensitive($data);
    }

    /** Remove recursivamente chaves sensíveis (defense-in-depth). */
    public static function scrubSensitive(array &$data, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) return;
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
     * Converte o que não é array/escalar em algo que `json_encode` representa
     * de forma útil.
     *
     * Motivo: `json_encode` de um objeto SEM propriedade pública produz `{}`.
     * Vários campos monetários do WHMCS (`GetStats.income_*`, `stats.*` de
     * `GetClientsDetails`, `amount` de line item, `grace_period.price` de
     * `GetTLDPricing`) são objetos formatadores com estado protegido — o
     * chamador recebia `{}` no lugar do valor e não tinha como recuperá-lo.
     *
     * @return array<mixed>
     */
    public static function materialize(array $data, int $depth = 0, ?\SplObjectStorage $seen = null): array
    {
        $seen ??= new \SplObjectStorage();
        if ($depth > self::MAX_DEPTH) {
            return $data;
        }

        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = self::materializeValue($key, $value, $depth, $seen);
        }

        return $out;
    }

    private static function materializeValue(int|string $key, mixed $value, int $depth, \SplObjectStorage $seen): mixed
    {
        if (is_array($value)) {
            return self::materialize($value, $depth + 1, $seen);
        }
        if (is_object($value)) {
            return self::flattenObject($value, $depth, $seen);
        }
        if (is_string($value) && in_array(strtolower((string) $key), self::JSON_STRING_KEYS, true)) {
            return self::decodeJsonString($value, $depth, $seen);
        }

        return $value;
    }

    /**
     * Objeto → representação serializável, na ordem de especificidade. O
     * fallback é `null`, NUNCA `{}`: um objeto sem método utilizável e sem
     * propriedade pública não tem informação a entregar, e `null` diz isso de
     * forma que o chamador entende.
     *
     * Cada chamada a método do objeto é protegida: `method_exists()` é true
     * também para método privado ou que exija argumento, e um `Error` aqui
     * derrubaria a resposta inteira por causa de um único campo.
     */
    private static function flattenObject(object $object, int $depth, \SplObjectStorage $seen): mixed
    {
        if ($depth > self::MAX_DEPTH || $seen->contains($object)) {
            return null; // profundidade estourada ou ciclo de referência
        }
        $seen->attach($object);

        try {
            if ($object instanceof \JsonSerializable) {
                $data = self::callSafely($object, 'jsonSerialize');
                if (is_array($data)) {
                    return self::materialize($data, $depth + 1, $seen);
                }
                if ($data !== null && !is_object($data)) {
                    return $data;
                }
            }

            if ($object instanceof \DateTimeInterface) {
                return $object->format(\DATE_ATOM);
            }

            $money = self::asMoney($object);
            if ($money !== null) {
                return $money;
            }

            if (method_exists($object, '__toString')) {
                $text = self::callSafely($object, '__toString');
                if (is_string($text)) {
                    return $text;
                }
            }

            $vars = get_object_vars($object);

            return $vars === [] ? null : self::materialize($vars, $depth + 1, $seen);
        } finally {
            $seen->detach($object);
        }
    }

    /**
     * Formatadores monetários do WHMCS expõem o valor por método, não por
     * propriedade. O shape de saída carrega os DOIS usos: `amount` para conta,
     * `formatted` para leitura humana (com símbolo e separadores do locale).
     *
     * Detecção por `method_exists` e não por nome de classe: a classe concreta
     * é ionCube e pode mudar entre versões do WHMCS; o contrato de método é o
     * que a documentação expõe.
     *
     * @return array{amount:float, formatted:string|null}|null
     */
    private static function asMoney(object $object): ?array
    {
        if (!method_exists($object, 'toNumeric')) {
            return null;
        }

        $amount = self::callSafely($object, 'toNumeric');
        if (!is_int($amount) && !is_float($amount) && !(is_string($amount) && is_numeric($amount))) {
            return null;
        }

        $formatted = null;
        foreach (['toPrefixed', 'toSuffixed', '__toString'] as $method) {
            if (!method_exists($object, $method)) {
                continue;
            }
            $value = self::callSafely($object, $method);
            if (is_string($value) && $value !== '') {
                $formatted = $value;
                break;
            }
        }

        return ['amount' => (float) $amount, 'formatted' => $formatted];
    }

    /** Chamada de método sem argumentos que nunca propaga falha do objeto. */
    private static function callSafely(object $object, string $method): mixed
    {
        try {
            return $object->{$method}();
        } catch (\Throwable) {
            return null; // privado, exige argumento, ou falhou por conta própria
        }
    }

    /**
     * JSON serializado dentro do payload → array. Valor que não decodifica para
     * array é PRESERVADO como veio: preferir o dado cru do WHMCS a uma
     * interpretação inventada.
     */
    private static function decodeJsonString(string $value, int $depth, \SplObjectStorage $seen): mixed
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $value;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return $value;
        }

        return self::materialize($decoded, $depth + 1, $seen);
    }

    /**
     * Corrige os tipos que o WHMCS serializa de forma inconsistente e que
     * obrigam todo consumidor MCP a adivinhar: lista vazia como `""` (só nas
     * chaves da allowlist), id ausente como `""` (idem) e data nunca setada
     * como `"0000-00-00"`/`"00/00/0000"` (em QUALQUER chave — o padrão é
     * inequívoco, não existe campo de texto livre legítimo com esse valor).
     */
    public static function normalizeTypes(array &$data, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) return;
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                self::normalizeTypes($value, $depth + 1);
                continue;
            }
            $name = strtolower((string) $key);
            if ($value === '' && in_array($name, self::EMPTY_STRING_MEANS_EMPTY_LIST, true)) {
                $value = [];
                continue;
            }
            if ($value === '' && in_array($name, self::EMPTY_STRING_MEANS_NULL_ID, true)) {
                $value = null;
                continue;
            }
            if (is_string($value) && preg_match(self::ZERO_DATE_PATTERN, $value) === 1) {
                $value = null;
            }
        }
        unset($value);
    }

    /**
     * Reintroduz como `[]` as coleções que o WHMCS omite quando vazias. Chave
     * já presente NUNCA é tocada — inclusive quando o valor é `""`, que a
     * passada anterior já converteu.
     */
    public static function ensureListKeys(array &$data, string $command): void
    {
        foreach (self::GUARANTEED_LIST_KEYS[$command] ?? [] as $key) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = [];
            }
        }
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
     *     cliente. `customfields` continua, agora rotulado por `name`
     *     (ver `labelCustomFields()`).
     */
    public static function stripClientDetails(array &$result, ?CustomFieldDirectory $fields = null): void
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
        self::labelCustomFields($result, $fields ?? new CustomFieldDirectory());
    }

    /**
     * `customfields` chega como lista de `{id, value}` — sem o nome do campo,
     * um `{"id":134,"value":"12345678900"}` não diz NADA a quem lê. O nome vive
     * em `tblcustomfields`; `CustomFieldDirectory` faz a leitura estreita.
     *
     * Campo cujo `id` não resolve fica exatamente como veio: rótulo inventado
     * seria pior que rótulo ausente.
     */
    private static function labelCustomFields(array &$result, CustomFieldDirectory $fields): void
    {
        if (!isset($result['customfields']) || !is_array($result['customfields'])) {
            return;
        }

        foreach ($result['customfields'] as &$field) {
            if (!is_array($field) || !isset($field['id']) || isset($field['name'])) {
                continue;
            }
            $label = $fields->labelFor((int) $field['id']);
            if ($label !== null) {
                $field['name'] = $label['name'];
                $field['type'] = $label['type'];
            }
        }
        unset($field);
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
     * Prefixos de linha de Activity Log que são ruído de DEPURAÇÃO, não
     * atividade administrativa. Com o "Hooks Debug" ligado, o WHMCS grava uma
     * linha por hook disparado: o log do desenv chegou a 905.222 linhas e as
     * três primeiras páginas eram só isso, tornando a tool inútil para o que
     * ela existe (ver o que os admins fizeram).
     */
    private const ACTIVITY_LOG_NOISE_PREFIXES = ['Hooks Debug'];

    /**
     * Remove as linhas de ruído da resposta de `GetActivityLog` e devolve
     * quantas saíram.
     *
     * O filtro é CLIENT-SIDE por necessidade: `GetActivityLog` só oferece
     * `description` como filtro POSITIVO (LIKE), não há como pedir "tudo menos
     * isto". Consequência que o chamador precisa saber: a paginação do WHMCS
     * acontece ANTES do filtro, então uma página pode voltar com menos itens
     * que `limitnum` — e `totalresults` continua contando o ruído.
     */
    public static function filterActivityLogNoise(array &$result): int
    {
        if (!isset($result['activity']['entry']) || !is_array($result['activity']['entry'])) {
            return 0;
        }

        $kept = [];
        $removed = 0;
        foreach ($result['activity']['entry'] as $entry) {
            if (is_array($entry) && self::isActivityNoise((string) ($entry['description'] ?? ''))) {
                $removed++;
                continue;
            }
            $kept[] = $entry;
        }

        $result['activity']['entry'] = $kept;

        return $removed;
    }

    private static function isActivityNoise(string $description): bool
    {
        $text = ltrim($description);
        foreach (self::ACTIVITY_LOG_NOISE_PREFIXES as $prefix) {
            if (stripos($text, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Teto do resumo de descrição de produto, em caracteres. */
    public const DESCRIPTION_SUMMARY_LIMIT = 300;

    /** Chaves mantidas em produtos no modo lite. */
    private const PRODUCT_LITE_KEEP = ['pid', 'gid', 'name', 'type', 'module', 'paytype', 'description_plain', 'pricing'];

    /**
     * `GetProducts` devolve o `description` de cada produto como HTML COMPLETO
     * de landing page. Medido no catálogo real: 54 produtos, ~126 KB de
     * resposta, quase toda em markup que nenhum agente precisa ler para decidir
     * qual produto é qual.
     *
     * O default troca `description` por `description_plain`: tags removidas,
     * entidades decodificadas, whitespace colapsado, truncado em
     * DESCRIPTION_SUMMARY_LIMIT caracteres, com `description_truncated: true`
     * quando houve corte — para o chamador saber que existe mais e pedir
     * `full_description=true`.
     */
    public static function summariseProductDescriptions(array &$result, int $limit = self::DESCRIPTION_SUMMARY_LIMIT): void
    {
        if (!isset($result['products']['product']) || !is_array($result['products']['product'])) {
            return;
        }

        foreach ($result['products']['product'] as &$product) {
            if (!is_array($product) || !isset($product['description']) || !is_string($product['description'])) {
                continue;
            }
            $plain = self::toPlainText($product['description']);
            unset($product['description']);
            if (mb_strlen($plain, 'UTF-8') > $limit) {
                $plain = rtrim(mb_substr($plain, 0, $limit, 'UTF-8')) . '…';
                $product['description_truncated'] = true;
            }
            $product['description_plain'] = $plain;
        }
        unset($product);
    }

    /** HTML → texto corrido legível, sem perder as quebras entre blocos. */
    private static function toPlainText(string $html): string
    {
        $text = preg_replace('/<(?:br\s*\/?|\/p|\/div|\/li|\/h[1-6])>/i', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
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

    /**
     * Remove ciclos de preço com valor negativo (WHMCS usa -1.00 = ciclo
     * desativado). Aplicável a GetProducts em ambos os modos (lite e full).
     */
    public static function removeNegativePrices(array &$result): void
    {
        if (!isset($result['products']['product']) || !is_array($result['products']['product'])) {
            return;
        }

        foreach ($result['products']['product'] as &$product) {
            if (!is_array($product) || !isset($product['pricing']) || !is_array($product['pricing'])) {
                continue;
            }

            foreach ($product['pricing'] as &$pricing) {
                if (!is_array($pricing)) {
                    continue;
                }

                foreach ($pricing as $currency => &$cycles) {
                    if (!is_array($cycles)) {
                        continue;
                    }

                    $filtered = [];
                    foreach ($cycles as $cycle => $price) {
                        $numPrice = is_numeric($price) ? (float)$price : null;
                        if ($numPrice !== null && $numPrice >= 0) {
                            $filtered[$cycle] = $price;
                        }
                    }
                    $cycles = $filtered;
                }
                unset($cycles);
            }
            unset($pricing);
        }
        unset($product);
    }

    /**
     * Reduz produtos ao modo lite: mantém apenas chaves essenciais
     * (pid, gid, name, type, module, paytype, description_plain, pricing).
     * Remove customfields, configoptions, product_url e outras chaves volumosas.
     */
    public static function productLiteView(array &$result): void
    {
        if (!isset($result['products']['product']) || !is_array($result['products']['product'])) {
            return;
        }

        foreach ($result['products']['product'] as &$product) {
            if (!is_array($product)) {
                continue;
            }

            $lite = [];
            foreach (self::PRODUCT_LITE_KEEP as $key) {
                if (array_key_exists($key, $product)) {
                    $lite[$key] = $product[$key];
                }
            }
            $product = $lite;
        }
        unset($product);
    }

    /**
     * Pagina produtos localmente: corta `products.product[]` na faixa
     * [$limitstart, $limit], adiciona `totalresults` (contagem antes do corte),
     * `limit` e `limitstart` no topo. Quando nenhum filtro (gid=0, pid=0,
     * module=''), adiciona `warning` alertando para reduzir contexto.
     */
    public static function paginateProducts(array &$result, int $limit, int $limitstart, bool $noFilter): void
    {
        if (!isset($result['products']['product']) || !is_array($result['products']['product'])) {
            $result['totalresults'] = 0;
            $result['limit'] = $limit;
            $result['limitstart'] = $limitstart;
            if ($noFilter) {
                $result['warning'] = 'catálogo inteiro; filtre por gid/pid para reduzir contexto';
            }
            return;
        }

        $products = &$result['products']['product'];
        if (!array_is_list($products)) {
            $products = [$products];
        }

        $total = count($products);
        $result['totalresults'] = $total;
        $result['limit'] = $limit;
        $result['limitstart'] = $limitstart;

        if ($noFilter) {
            $result['warning'] = 'catálogo inteiro; filtre por gid/pid para reduzir contexto';
        }

        $products = array_slice($products, $limitstart, $limit);
    }
}
