<?php
namespace NtMcp\Whmcs;

/** Redação central de respostas de tools antes de devolver ao chamador MCP. */
final class ResponseRedactor
{
    /** Teto de profundidade de recursão, compartilhado por todas as passadas. */
    private const MAX_DEPTH = 8;

    /** Allowlist fechada da view lite de cliente: nenhum dado de identidade. */
    public const CLIENT_LITE_KEEP = [
        'result', 'clientid', 'status', 'groupid', 'currency', 'currency_code',
        'datecreated', 'stats',
    ];

    /** Metadados não identificadores mantidos em cada linha de GetClients. */
    private const CLIENT_LIST_LITE_KEEP = ['id', 'datecreated', 'groupid', 'status'];

    /** Dados financeiros/operacionais mantidos em cada linha de GetInvoices. */
    private const INVOICE_LIST_LITE_KEEP = [
        'id', 'userid', 'invoicenum', 'date', 'duedate', 'datepaid',
        'last_capture_attempt', 'lastcaptureattempt', 'date_refunded', 'date_cancelled',
        'subtotal', 'credit', 'tax', 'tax2', 'total', 'taxrate', 'taxrate2',
        'status', 'paymentmethod', 'paymethodid', 'created_at', 'updated_at',
        'currencycode', 'currencyprefix', 'currencysuffix',
    ];

    /** Dados necessários para triar uma fila sem identidade direta do solicitante. */
    private const TICKET_LIST_LITE_KEEP = [
        'id', 'tid', 'display_id', 'deptid', 'userid', 'date', 'subject',
        'status', 'priority', 'lastreply', 'flag', 'service',
    ];

    /** PII direta presente no nível do pedido, mas não nos seus line items. */
    private const ORDER_LITE_DROP = [
        'name', 'firstname', 'lastname', 'fullname', 'companyname', 'email',
        'address1', 'address2', 'city', 'state', 'fullstate', 'postcode',
        'country', 'phonenumber', 'phonenumberformatted', 'phonecc', 'tax_id',
        'client',
    ];

    /** PII direta presente no nível da cotação. */
    private const QUOTE_LITE_DROP = [
        'firstname', 'lastname', 'fullname', 'companyname', 'email',
        'address1', 'address2', 'city', 'state', 'fullstate', 'postcode',
        'country', 'phonenumber', 'phonenumberformatted', 'phonecc', 'tax_id',
    ];

    /** Campos não identificadores permitidos no cliente embutido da cotação. */
    private const QUOTE_CLIENT_LITE_KEEP = ['id', 'datecreated', 'groupid', 'status'];

    /** Chave de configuração WHMCS para lista de field IDs visíveis em view full. */
    private const CUSTOMFIELDS_VISIBLE_SETTING = 'nt_mcp_client_customfields_visible';

    /**
     * Chaves nunca legítimas numa resposta MCP — removidas em qualquer
     * profundidade. `ipaddress` é PII de cliente (confirmado em GetOrders).
     * `c` é o código de acesso público a um ticket em
     * `GetTickets`/`GetTicket` (dispensa autenticação — mesma classe de
     * `password`); nome curto, mas WHMCS não usa `c` como chave em nenhum
     * outro payload conhecido do addon.
     */
    private const ALWAYS_STRIP = ['password', 'password2', 'securityqans', 'c', 'ipaddress', 'transfersecret'];

    /**
     * Chaves cujo valor vazio o WHMCS serializa como `""` (string) quando a
     * lista está vazia, em vez de `[]`/`array()`. ALLOWLIST fechada — chave
     * fora daqui nunca é convertida, porque `""` também é o valor legítimo de
     * campo de TEXTO vazio (ex.: `notes`), e converter esses pra `[]` mudaria
     * o tipo do campo pra quem já lê como string.
     */
    private const EMPTY_STRING_MEANS_EMPTY_LIST = [
        'transactions', 'nameservers', 'renewals', 'frauddata', 'validationdata',
        'domains', 'services', 'addons', 'attachments', 'products',
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

    /** `replyid=0` significa que a mensagem não possui resposta pai. */
    private const ZERO_MEANS_NULL_ID = ['replyid'];

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
            if (in_array($name, self::ZERO_MEANS_NULL_ID, true)
                && ($value === '' || $value === '0' || $value === 0)) {
                $value = null;
                continue;
            }
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
     *     texto plano com `; ` no lugar de `<br>`, e depois truncado no
     *     primeiro `; ` (mantém só a data); se o formato mudar e não
     *     bater no padrão esperado, o valor ORIGINAL é preservado — nunca
     *     inventa dado.
     *  3. `customfields1`..`customfields15` são a MESMA informação de
     *     `customfields` (array `{id, value}`), só que achatada em chaves
     *     numeradas sem id — confirmado campo a campo contra o mesmo
     *     cliente. `customfields` continua, agora filtrado aos ids visíveis
     *     (via allowlist de configuração ou explícito), e rotulado por `name`
     *     (ver `labelCustomFields()`).
     *  4. `cclastfour` e `cardlastfour` são removidos sempre, mesmo em full
     *     (redundante com `cclastfour` do campo de card).
     *
     * @param array &$result Resposta do WHMCS, modificada in-place.
     * @param CustomFieldDirectory|null $fields Diretório de labels (default: novo).
     * @param array<int>|null $visibleIds IDs de custom fields visíveis (default: carrega de setting).
     */
    public static function stripClientDetails(array &$result, ?CustomFieldDirectory $fields = null, ?array $visibleIds = null): void
    {
        self::scrubSensitive($result);
        unset($result['client']);
        for ($i = 1; $i <= 15; $i++) {
            unset($result['customfields' . $i]);
        }
        if (isset($result['lastlogin']) && is_string($result['lastlogin']) && $result['lastlogin'] !== '') {
            $plain = trim(str_ireplace('<br>', '; ', $result['lastlogin']), '; ');
            // Trunca no primeiro '; ' para manter só a data
            if (strpos($plain, '; ') !== false) {
                $plain = explode('; ', $plain)[0];
            }
            if ($plain !== '') {
                $result['lastlogin'] = $plain;
            }
        }
        // Remove campos de cartão em qualquer view
        unset($result['cclastfour'], $result['cardlastfour']);

        // Filtra custom fields aos ids visíveis
        if ($visibleIds === null) {
            $visibleIds = self::visibleCustomFieldIds();
        }
        self::filterCustomFields($result, $visibleIds);

        self::labelCustomFields($result, $fields ?? new CustomFieldDirectory());
    }

    /**
     * Carrega os IDs de custom fields que devem ficar visíveis em view full,
     * a partir da configuração WHMCS. Se a configuração não existe ou falha,
     * retorna array vazio (nenhum campo visível além do que cair no lite).
     *
     * @return array<int>
     */
    private static function visibleCustomFieldIds(): array
    {
        try {
            $raw = \WHMCS\Config\Setting::getValue(self::CUSTOMFIELDS_VISIBLE_SETTING) ?? '';
        } catch (\Throwable) {
            // Classe não existe ou leitura falhou — fail-safe: nenhum visível
            return [];
        }

        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $ids = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Filtra `customfields` para manter apenas os IDs na allowlist.
     * Lista vazia mantém `customfields` como `[]`.
     *
     * @param array &$result
     * @param array<int> $visibleIds
     */
    private static function filterCustomFields(array &$result, array $visibleIds): void
    {
        if (!isset($result['customfields']) || !is_array($result['customfields'])) {
            return;
        }

        $filtered = [];
        foreach ($result['customfields'] as $field) {
            if (!is_array($field) || !isset($field['id'])) {
                continue;
            }
            $id = (int) ($field['id'] ?? 0);
            if ($id > 0 && in_array($id, $visibleIds, true)) {
                $filtered[] = $field;
            }
        }

        $result['customfields'] = $filtered;
    }

    /**
     * Projeta a resposta de sucesso na allowlist estrita da view lite.
     * Chamada APÓS stripClientDetails (que faz o scrub comum). Respostas de
     * erro não devem passar por esta função, para preservar seu diagnóstico.
     */
    public static function clientLiteView(array &$result): void
    {
        $result = self::keepKeys($result, self::CLIENT_LITE_KEEP);
    }

    /** Projeta as linhas de GetClients para metadados sem identidade direta. */
    public static function clientListLiteView(array &$result): void
    {
        self::projectListRows($result, ['clients', 'client'], self::CLIENT_LIST_LITE_KEEP);
    }

    /** Projeta as linhas de GetInvoices sem identidade direta nem notas livres. */
    public static function invoiceListLiteView(array &$result): void
    {
        self::projectListRows($result, ['invoices', 'invoice'], self::INVOICE_LIST_LITE_KEEP);
    }

    /** Projeta a fila de tickets sem identidade direta ou anexos. */
    public static function ticketListLiteView(array &$result): void
    {
        self::projectListRows($result, ['tickets', 'ticket'], self::TICKET_LIST_LITE_KEEP);
    }

    /** Remove PII direta de pedidos, preservando integralmente os line items. */
    public static function orderLiteView(array &$result): void
    {
        if (isset($result['orders']['order']) && is_array($result['orders']['order'])) {
            foreach ($result['orders']['order'] as &$order) {
                if (is_array($order)) {
                    self::dropKeys($order, self::ORDER_LITE_DROP);
                }
            }
            unset($order);
            return;
        }

        self::dropKeys($result, self::ORDER_LITE_DROP);
    }

    /** Remove PII direta de cotações e reduz o cliente embutido a metadados. */
    public static function quoteLiteView(array &$result): void
    {
        if (!isset($result['quotes']['quote']) || !is_array($result['quotes']['quote'])) {
            return;
        }

        foreach ($result['quotes']['quote'] as &$quote) {
            if (!is_array($quote)) {
                continue;
            }
            self::dropKeys($quote, self::QUOTE_LITE_DROP);
            if (isset($quote['client']) && is_array($quote['client'])) {
                $quote['client'] = self::keepKeys($quote['client'], self::QUOTE_CLIENT_LITE_KEEP);
            }
        }
        unset($quote);
    }

    /** @param array<int, string> $keys */
    private static function dropKeys(array &$data, array $keys): void
    {
        foreach ($keys as $key) {
            unset($data[$key]);
        }
    }

    /** @param array<int, string> $keys */
    private static function keepKeys(array $data, array $keys): array
    {
        $kept = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $kept[$key] = $data[$key];
            }
        }

        return $kept;
    }

    /**
     * @param array<int, string> $path
     * @param array<int, string> $keys
     */
    private static function projectListRows(array &$result, array $path, array $keys): void
    {
        $rows =& $result;
        foreach ($path as $segment) {
            if (!isset($rows[$segment]) || !is_array($rows[$segment])) {
                return;
            }
            $rows =& $rows[$segment];
        }

        foreach ($rows as &$row) {
            if (is_array($row)) {
                $row = self::keepKeys($row, $keys);
            }
        }
        unset($row, $rows);
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

    /** Chaves de ciclo/taxa em `pricing.{moeda}` do GetProducts (o resto é prefix/suffix). */
    private const PRICING_CYCLE_KEYS = [
        'msetupfee', 'qsetupfee', 'ssetupfee', 'asetupfee', 'bsetupfee', 'tsetupfee',
        'monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially',
    ];

    /**
     * Remove ciclos de preço com valor negativo. Estrutura real do WHMCS:
     * `pricing.{moeda}.{ciclo}` (ex.: `pricing.BRL.monthly = "-1.00"`), com
     * `prefix`/`suffix` ao lado — por isso só as chaves de ciclo/taxa são
     * avaliadas. `-1.00` = ciclo desativado; nunca deve aparecer como preço.
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
            foreach ($product['pricing'] as &$currency) {
                if (!is_array($currency)) {
                    continue;
                }
                foreach (self::PRICING_CYCLE_KEYS as $cycle) {
                    if (array_key_exists($cycle, $currency) && is_numeric($currency[$cycle]) && (float) $currency[$cycle] < 0) {
                        unset($currency[$cycle]);
                    }
                }
            }
            unset($currency);
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
     * Remove scheme e autoridade de `product_url`, preservando somente uma
     * referência origin-relative (`/path?query#fragment`). O host do WHMCS é
     * contexto operacional e não deve viajar escondido em cada produto.
     * Valores inválidos ou esquemas não web são removidos fail-closed.
     */
    public static function relativizeProductUrls(array &$result): void
    {
        if (!isset($result['products']['product']) || !is_array($result['products']['product'])) {
            return;
        }

        foreach ($result['products']['product'] as &$product) {
            if (!is_array($product) || !array_key_exists('product_url', $product)) {
                continue;
            }

            $relative = self::relativeWebUrl($product['product_url']);
            if ($relative === null) {
                unset($product['product_url']);
                continue;
            }

            $product['product_url'] = $relative;
        }
        unset($product);
    }

    private static function relativeWebUrl(mixed $url): ?string
    {
        if (!is_string($url) || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));
        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if ($scheme !== '' && empty($parts['host'])) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            $path = '/';
        } elseif ($path[0] !== '/') {
            $path = '/' . $path;
        }

        if (array_key_exists('query', $parts)) {
            $path .= '?' . $parts['query'];
        }
        if (array_key_exists('fragment', $parts)) {
            $path .= '#' . $parts['fragment'];
        }

        return $path;
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

    /**
     * Normaliza GetTLDPricing removendo anos com preço 0 (não configurado) e
     * adicionando metadados sobre configuração de grace_period/redemption.
     *
     * Estrutura de entrada:
     *   pricing.{tld}.{register|transfer|renew}.{anos} = string com preço
     *   pricing.{tld}.grace_period.{days|price}
     *   pricing.{tld}.redemption.{days|price}
     *
     * Transformações:
     *   - Remove anos com preço numérico == 0 em register/transfer/renew
     *   - Adiciona years_available.{register|transfer|renew} = lista de anos restantes
     *   - grace_period.price {amount: 0} ou == 0 vira null + not_configured=true
     *   - Idem para redemption.price
     */
    public static function normalizeTldPricing(array &$result): void
    {
        if (!isset($result['pricing']) || !is_array($result['pricing'])) {
            return;
        }

        foreach ($result['pricing'] as $tld => &$tldData) {
            if (!is_array($tldData)) {
                continue;
            }

            $years_available = [];

            foreach (['register', 'transfer', 'renew'] as $op) {
                if (isset($tldData[$op]) && is_array($tldData[$op])) {
                    $filtered = [];
                    foreach ($tldData[$op] as $years => $price) {
                        $numPrice = is_numeric($price) ? (float)$price : 0;
                        if ($numPrice > 0) {
                            $filtered[$years] = $price;
                        }
                    }
                    $tldData[$op] = $filtered;
                    $years_available[$op] = array_keys($filtered);
                } else {
                    $years_available[$op] = [];
                }
            }

            $tldData['years_available'] = $years_available;

            self::normalizeTldPricingField($tldData, 'grace_period');
            self::normalizeTldPricingField($tldData, 'redemption');
        }
        unset($tldData);
    }

    /**
     * Helper para normalizar grace_period.price ou redemption.price:
     * se for {amount: 0} ou == 0, vira null + not_configured=true.
     */
    private static function normalizeTldPricingField(array &$tldData, string $field): void
    {
        if (!isset($tldData[$field]) || !is_array($tldData[$field])) {
            return;
        }

        $fieldData = &$tldData[$field];

        if (isset($fieldData['price'])) {
            $price = $fieldData['price'];

            // Se é array com amount
            if (is_array($price) && isset($price['amount'])) {
                $amount = (float)$price['amount'];
                if ($amount == 0) {
                    $fieldData['price'] = null;
                    $fieldData['not_configured'] = true;
                }
            } elseif (is_numeric($price)) {
                $numPrice = (float)$price;
                if ($numPrice == 0) {
                    $fieldData['price'] = null;
                    $fieldData['not_configured'] = true;
                }
            }
        }
    }
}
