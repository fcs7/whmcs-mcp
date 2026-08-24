<?php
// src/Whmcs/LocalApiClient.php
namespace NtMcp\Whmcs;

class LocalApiClient
{
    // ---------------------------------------------------------------
    // SECURITY FIX (F4 -- CVSS 9.1): Restrict callable WHMCS API
    // commands to only those used by the MCP tools of this surface.
    //
    // Before this fix, call() accepted ANY command string, meaning a
    // compromised or malicious MCP tool caller could invoke destructive
    // or data-exfiltrating API actions such as AddAdmin, EncryptPassword,
    // WhoAmI, DecryptPassword, CreateSsoToken, etc.
    //
    // T1: reduzido de 73 para os comandos efetivamente requeridos pela
    // superfície canônica (hoje 55 comandos para 64 tools — a contagem exata
    // é travada por teste, não por este comentário). Comandos de custo/provisionamento
    // (ModuleSuspend, UpgradeProduct, DomainRegister, AcceptOrder, AddOrder...),
    // de comunicação (SendEmail, SendQuote) e lookups auxiliares saíram do
    // allowlist — não foram apenas desligados por gate.
    // ---------------------------------------------------------------

    /** Commands whose API_CALL and API_OK audit entries are suppressed. */
    private const AUDIT_SILENT_COMMANDS = ['GetActivityLog'];

    /** Exhaustive allowlist of WHMCS API commands used by the addon tools. */
    private const ALLOWED_COMMANDS = [
        // ClientTools
        'GetClients',
        'GetClientsDetails',
        'AddClient',
        'UpdateClient',
        'GetClientsProducts',
        'GetClientsDomains',
        'GetContacts',
        'AddContact',
        'UpdateContact',
        'GetClientGroups',
        'GetClientsAddons',

        // BillingTools
        'GetInvoices',
        'GetInvoice',
        'GetTransactions',
        'GetCredits',
        'GetPayMethods',

        // TicketTools
        'GetTickets',
        'GetTicket',
        'OpenTicket',
        'AddTicketReply',
        'UpdateTicket',

        // OrderTools
        'GetOrders',
        'CancelOrder',
        'PendingOrder',
        'GetProducts',
        'GetOrderStatuses',
        'GetPromotions',

        // DomainTools
        'DomainGetNameservers',
        'DomainGetLockingStatus',
        'DomainGetWhoisInfo',
        'GetTLDPricing',

        // SystemTools
        'GetStats',
        'GetActivityLog',
        'GetAdminDetails',
        'GetToDoItems',
        'UpdateToDoItem',
        'GetCurrencies',

        // ProjectManagerTools
        'GetProjects',
        'GetProject',
        'CreateProject',
        'UpdateProject',
        'AddProjectTask',
        'UpdateProjectTask',
        'StartTaskTimer',
        'EndTaskTimer',
        'AddProjectMessage',

        // QuoteTools
        'GetQuotes',
        'CreateQuote',
        'UpdateQuote',
        'AcceptQuote',
        'UpdateInvoice',
        'DeleteQuote',

        // SupportInfoTools
        'GetSupportDepartments',
        'GetSupportStatuses',
        'GetTicketCounts',
    ];

    /**
     * Campos sensíveis por NOME.
     *
     * D7 aposentou esta lista como mecanismo de auditoria — uma denylist de
     * nomes nunca protege segredo colocado em campo de texto livre
     * (`proposal`, `notes`, `description`), e foi assim que um segredo inteiro
     * acabou no Activity Log. O que grava hoje é `AuditMetadata`, por
     * allowlist.
     *
     * A lista permanece porque `ResponseRedactor` e revisões de segurança ainda
     * a usam como referência do que é sensível na RESPOSTA.
     */
    private const REDACTED_PARAMS = [
        'password', 'password2', 'cardnum', 'cvv', 'expdate',
        'cardnumber', 'cvc', 'bankacct', 'bankcode',
        'securityqans', 'tax_id',
    ];

    /**
     * Classe de efeito colateral por comando. Ausência de classe ⇒ negar
     * (ver classOf()). TODO comando do allowlist tem entrada explícita aqui:
     * nenhuma ausência degrada para WRITE.
     */
    private const COMMAND_CLASS = [
        // READ
        'GetClients'=>'READ','GetClientsDetails'=>'READ','GetClientsProducts'=>'READ',
        'GetClientsDomains'=>'READ','GetContacts'=>'READ','GetClientGroups'=>'READ',
        'GetClientsAddons'=>'READ','GetInvoices'=>'READ','GetInvoice'=>'READ',
        'GetTransactions'=>'READ','GetCredits'=>'READ','GetPayMethods'=>'READ',
        'GetTickets'=>'READ','GetTicket'=>'READ','GetOrders'=>'READ',
        'DomainGetNameservers'=>'READ','DomainGetLockingStatus'=>'READ',
        'DomainGetWhoisInfo'=>'READ','GetTLDPricing'=>'READ',
        'GetStats'=>'READ','GetActivityLog'=>'READ','GetAdminDetails'=>'READ',
        'GetToDoItems'=>'READ','GetProjects'=>'READ','GetProject'=>'READ','GetQuotes'=>'READ',
        'GetSupportDepartments'=>'READ','GetSupportStatuses'=>'READ','GetTicketCounts'=>'READ',
        'GetProducts'=>'READ','GetCurrencies'=>'READ',
        'GetOrderStatuses'=>'READ','GetPromotions'=>'READ',
        // WRITE (mutação reversível)
        'AddClient'=>'WRITE','UpdateClient'=>'WRITE','AddContact'=>'WRITE','UpdateContact'=>'WRITE',
        'OpenTicket'=>'WRITE','AddTicketReply'=>'WRITE','UpdateTicket'=>'WRITE',
        'PendingOrder'=>'WRITE','UpdateToDoItem'=>'WRITE',
        'CreateProject'=>'WRITE','UpdateProject'=>'WRITE','AddProjectTask'=>'WRITE',
        'UpdateProjectTask'=>'WRITE','StartTaskTimer'=>'WRITE','EndTaskTimer'=>'WRITE',
        'AddProjectMessage'=>'WRITE','CreateQuote'=>'WRITE','UpdateQuote'=>'WRITE',
        // DESTRUCTIVE (irreversível) — as tools que os usam exigem também confirm=true.
        'CancelOrder'=>'DESTRUCTIVE','DeleteQuote'=>'DESTRUCTIVE',
        // FINANCIAL — AcceptQuote gera a fatura; UpdateInvoice ajusta a cobrança
        // gerada. Ambos são passos da mesma conversão e compartilham a classe.
        'AcceptQuote'=>'FINANCIAL','UpdateInvoice'=>'FINANCIAL',
        // COST e COMMS: nenhum comando da superfície canônica pertence a estas
        // classes como efeito PRIMÁRIO. COST saiu inteiramente do allowlist.
        // COMMS permanece como requisito ORTOGONAL — ver NOTIFYING_COMMANDS.
    ];

    /**
     * Comandos que notificam o cliente por e-mail salvo bloqueio explícito via
     * `noemail`. Nenhum deles é COMMS como classe primária (são WRITE), mas
     * pedir a notificação adiciona o gate COMMS como requisito.
     *
     * A verificação vive aqui — no ponto CENTRAL de autorização — e não na
     * camada da tool: assim uma chamada direta ao LocalApiClient (ou uma
     * refatoração futura das tools) não consegue contornar o gate.
     */
    private const NOTIFYING_COMMANDS = ['AddClient', 'OpenTicket', 'AddTicketReply'];

    /** @var callable|null Para injecao em testes */
    private $callable = null;

    // teste: ['write'=>bool,'destructive'=>bool,...,'readonly'=>bool,
    //         'allowlist_clientids'=>?int[], 'allowlist_ticketids'=>?int[]]
    private ?array $gatesOverride = null;

    /**
     * Gate por ALVO (#14): allowlists opcionais de ids de teste. Quando
     * configuradas, qualquer comando não-READ cujo alvo (clientid/userid,
     * ticketid) esteja fora da lista é negado com `write_target_not_allowed`.
     * Vazias/ausentes = sem restrição por alvo (comportamento anterior).
     */
    private const ALLOWLIST_CLIENT_KEY = 'nt_mcp_write_allowlist_clientids';
    private const ALLOWLIST_TICKET_KEY = 'nt_mcp_write_allowlist_ticketids';
    private const CLIENT_TARGET_PARAMS = ['clientid', 'userid'];
    private const TICKET_TARGET_PARAMS = ['ticketid'];
    /**
     * Alvos indiretos (#14, achado 38): param de id → comando READ que resolve
     * o dono, filtro usado, e caminho da lista no payload. O registro devolvido
     * precisa ter `id` igual ao pedido (filtro ignorado = irresolúvel = nega).
     */
    private const OWNER_LOOKUPS = [
        'orderid' => ['GetOrders', 'id',      ['orders', 'order']],
        'quoteid' => ['GetQuotes', 'quoteid', ['quotes', 'quote']],
    ];
    private const IMPERSONATION_COMMANDS = [
        'AddTicketReply','CreateProject','UpdateProject','AddProjectTask',
        'UpdateProjectTask','StartTaskTimer','EndTaskTimer','AddProjectMessage',
        'UpdateToDoItem',
    ];
    private array $adminIdCache = [];
    private $adminIdResolver = null; // teste: fn(string $username): ?int

    public function __construct(private readonly string $adminUser = 'admin') {}

    public function setCallable(callable $fn): void
    {
        $this->callable = $fn;
    }

    public function setGates(array $gates): void { $this->gatesOverride = $gates; }

    public function setAdminIdResolver(callable $fn): void { $this->adminIdResolver = $fn; }

    private function classOf(string $command): string
    {
        if (!array_key_exists($command, self::COMMAND_CLASS)) {
            throw new AuthorizationException(
                "LocalApiClient: command '{$command}' has no explicit security classification."
            );
        }

        return self::COMMAND_CLASS[$command];
    }

    /**
     * TODA classe não-READ tem default DESLIGADO (rollout read-only). Habilitar
     * WRITE é uma etapa operacional auditada; DESTRUCTIVE, FINANCIAL, COST e
     * COMMS exigem opt-in separado e independente.
     */
    private function gateEnabled(string $class): bool
    {
        if ($class === 'READ') return true;
        if ($this->isReadonly()) return false; // master switch (fail-closed)
        $key = match ($class) {
            'WRITE'       => 'nt_mcp_enable_write',
            'DESTRUCTIVE' => 'nt_mcp_enable_destructive',
            'FINANCIAL'   => 'nt_mcp_enable_financial',
            'COST'        => 'nt_mcp_enable_cost',
            'COMMS'       => 'nt_mcp_enable_comms',
            // Fail-closed: uma classe nova sem entrada aqui nunca é liberada.
            default       => null,
        };
        if ($key === null) return false;
        return $this->boolSetting($key, false, strtolower($class));
    }

    /**
     * readonly master switch — FAIL-CLOSED em três frentes: falha de leitura,
     * valor ausente segue o default decidido, e valor PRESENTE porém não
     * canônico (`'true'`, `'yes'`, `'garbage'`, `2`) bloqueia escrita e é
     * auditado. O gate futuro do domínio CRM usa o mesmo `ConfigFlag`.
     * O override de teste tem precedência.
     */
    private function isReadonly(): bool
    {
        if ($this->gatesOverride !== null) {
            return (bool) ($this->gatesOverride['readonly'] ?? false);
        }
        // Fora de um WHMCS bootstrapado (ex.: testes) não há config a proteger —
        // usa o default seguro. Sob WHMCS, uma falha de leitura cai no catch
        // abaixo e falha FECHADO (bloqueia escrita).
        if (!class_exists('\WHMCS\Config\Setting')) {
            return false;
        }
        try {
            $raw = \WHMCS\Config\Setting::getValue('nt_mcp_readonly');
        } catch (\Throwable $e) {
            // A mensagem NÃO é concatenada: uma PDOException aqui carrega DSN,
            // credencial e SQL, e este ramo grava nos dois sinks.
            self::auditConfig('LocalApiClient: readonly config read failed — failing closed', $e);
            return true;
        }

        return ConfigFlag::parse($raw)->resolve(
            default: false,   // ausente: rollout define read-only explicitamente
            failClosed: true, // presente e inválido: bloqueia mutação
            key: 'nt_mcp_readonly',
            auditor: self::auditConfig(...),
        );
    }

    /**
     * Lê config booleana de gate com override de teste. Ausente usa o default;
     * presente e inválido falha FECHADO (gate desligado) e é auditado.
     */
    private function boolSetting(string $key, bool $default, string $overrideKey): bool
    {
        if ($this->gatesOverride !== null) {
            return (bool) ($this->gatesOverride[$overrideKey] ?? $default);
        }
        try {
            $raw = \WHMCS\Config\Setting::getValue($key);
        } catch (\Throwable $e) {
            return $default;
        }

        return ConfigFlag::parse($raw)->resolve(
            default: $default,
            failClosed: false, // gate inválido nunca libera efeito
            key: $key,
            auditor: self::auditConfig(...),
        );
    }

    /**
     * Config corrompida é evento de segurança: vai para o Activity Log com
     * texto ESTÁVEL nosso, e o diagnóstico estrutural (classe + fingerprint)
     * para o error_log, correlacionados. A mensagem da exceção não é
     * concatenada em nenhum dos dois.
     */
    private static function auditConfig(string $_message, ?\Throwable $e = null): void
    {
        $correlationId = Diagnostics::report(Diagnostics::CATEGORY_CONFIG_READ, 'nt_mcp_config', $e);
        self::auditLog(ActivityEvent::CONFIG_INVALID, null, $correlationId);
    }

    private function assertModeAllows(string $command, array $params = []): void
    {
        $class = $this->classOf($command);
        if (!$this->gateEnabled($class)) {
            self::auditLog(ActivityEvent::API_BLOCKED_GATE, AuditMetadata::forParams($params), command: $command);
            throw new AuthorizationException(
                "LocalApiClient: command '{$command}' is blocked (class {$class} disabled by config)."
            );
        }

        // Requisito ORTOGONAL: notificar o cliente exige COMMS ALÉM da classe
        // primária. Vale para qualquer caminho que chegue até aqui, inclusive
        // uma chamada direta ao LocalApiClient que omita 'noemail'.
        if ($this->sendsNotification($command, $params) && !$this->gateEnabled('COMMS')) {
            self::auditLog(ActivityEvent::API_BLOCKED_COMMS, AuditMetadata::forParams($params), command: $command);
            throw new AuthorizationException(
                "LocalApiClient: command '{$command}' is blocked (client notification requires the COMMS gate)."
            );
        }

        if ($class !== 'READ') {
            $this->assertTargetAllowed($command, $params);
        }
    }

    /**
     * Gate por ALVO (#14). Cada allowlist configurada é aplicada de forma
     * independente (AND):
     *  - ticket allowlist: `ticketid` presente deve estar na lista;
     *  - client allowlist: `clientid`/`userid` presente deve estar na lista;
     *    sem id de cliente, o dono é resolvido ANTES do gate a partir de
     *    `ticketid` (GetTicket), `orderid` (GetOrders) ou `quoteid`
     *    (GetQuotes) — todos READ, auditados. Registro guest/órfão (userid 0)
     *    = sem cliente a checar (para cobrir guests, configure também a
     *    allowlist de tickets).
     * Comandos sem nenhum desses params (AddClient, projetos/tarefas) não têm
     * alvo checável e seguem só pelo gate de classe. Falha de leitura de
     * config, lista inválida ou alvo não resolvível = NEGA (fail-closed).
     */
    private function assertTargetAllowed(string $command, array $params): void
    {
        $clientList = $this->targetAllowlist(self::ALLOWLIST_CLIENT_KEY, 'allowlist_clientids');
        $ticketList = $this->targetAllowlist(self::ALLOWLIST_TICKET_KEY, 'allowlist_ticketids');
        if ($clientList === null && $ticketList === null) {
            return;
        }

        $ticketId = self::firstIntParam($params, self::TICKET_TARGET_PARAMS);
        $clientId = self::firstIntParam($params, self::CLIENT_TARGET_PARAMS);

        if ($ticketList !== null && $ticketId !== null && !in_array($ticketId, $ticketList, true)) {
            $this->denyTarget($command, $params, 'ticketid');
        }

        if ($clientList !== null) {
            if ($clientId === null) {
                [$clientId, $via] = $this->resolveIndirectClientId($params, $ticketId);
                if ($via !== null && $clientId === null) {
                    // Alvo indireto presente mas não resolvido (lookup falhou /
                    // registro inexistente / filtro ignorado): nega.
                    $this->denyTarget($command, $params, "{$via}→clientid");
                }
                if ($clientId === 0) {
                    $clientId = null; // guest/órfão: sem cliente a checar
                }
            }
            if ($clientId !== null && !in_array($clientId, $clientList, true)) {
                $this->denyTarget($command, $params, 'clientid');
            }
        }
    }

    /**
     * Resolve o dono a partir do primeiro alvo indireto presente
     * (ticketid, depois orderid/quoteid). @return array{?int, ?string} [clientId, via]
     */
    private function resolveIndirectClientId(array $params, ?int $ticketId): array
    {
        if ($ticketId !== null) {
            return [$this->resolveTicketClientId($ticketId), 'ticketid'];
        }
        foreach (self::OWNER_LOOKUPS as $param => [$lookupCmd, $filter, $path]) {
            $id = self::firstIntParam($params, [$param]);
            if ($id === null) continue;
            return [$this->resolveOwnerViaList($lookupCmd, $filter, $path, $id), $param];
        }
        return [null, null];
    }

    /**
     * Dono via comando de listagem filtrado por id. Exige exatamente UM
     * registro com `id` igual ao pedido; qualquer outra coisa = null.
     * @param string[] $path
     */
    private function resolveOwnerViaList(string $lookupCmd, string $filter, array $path, int $id): ?int
    {
        try {
            $res = $this->call($lookupCmd, [$filter => $id]);
        } catch (\Throwable) {
            return null;
        }
        if (($res['result'] ?? null) !== 'success') {
            return null;
        }
        $rows = $res;
        foreach ($path as $k) {
            $rows = is_array($rows) ? ($rows[$k] ?? null) : null;
        }
        if (!is_array($rows)) {
            return null;
        }
        $matches = array_values(array_filter($rows, fn($r) => is_array($r) && (int) ($r['id'] ?? 0) === $id));
        if (count($matches) !== 1) {
            return null;
        }
        $uid = $matches[0]['userid'] ?? $matches[0]['clientid'] ?? 0;
        return is_numeric($uid) ? (int) $uid : null;
    }

    private function denyTarget(string $command, array $params, string $target): never
    {
        self::auditLog(ActivityEvent::API_BLOCKED_TARGET, AuditMetadata::forParams($params), command: $command);
        throw new AuthorizationException(
            "LocalApiClient: command '{$command}' is blocked (write_target_not_allowed: {$target} fora da allowlist de escrita)."
        );
    }

    /** @return ?int null quando nenhum dos params está presente/é inteiro positivo */
    private static function firstIntParam(array $params, array $keys): ?int
    {
        foreach ($keys as $k) {
            if (!isset($params[$k])) continue;
            $v = $params[$k];
            if (is_int($v) && $v > 0) return $v;
            if (is_string($v) && ctype_digit($v) && (int) $v > 0) return (int) $v;
        }
        return null;
    }

    /**
     * `userid` do ticket via GetTicket (READ — passa pelo pipeline normal e é
     * auditado). 0 = guest; null = não resolvível.
     */
    private function resolveTicketClientId(int $ticketId): ?int
    {
        try {
            $ticket = $this->call('GetTicket', ['ticketid' => $ticketId]);
        } catch (\Throwable) {
            return null;
        }
        if (($ticket['result'] ?? null) !== 'success') {
            return null;
        }
        $uid = $ticket['userid'] ?? 0;
        return is_numeric($uid) ? (int) $uid : null;
    }

    /**
     * Lê uma allowlist CSV de ids. null = não configurada (sem restrição).
     * Lista presente com token inválido, ou falha de leitura, vira lista
     * VAZIA (nega todo alvo) e é auditada — config inválida nunca libera.
     *
     * @return ?int[]
     */
    private function targetAllowlist(string $key, string $overrideKey): ?array
    {
        if ($this->gatesOverride !== null) {
            $raw = $this->gatesOverride[$overrideKey] ?? null;
            if ($raw === null) return null;
            return is_array($raw) ? array_values(array_map('intval', $raw)) : self::parseIdCsv((string) $raw, $key);
        }
        if (!class_exists('\WHMCS\Config\Setting')) {
            return null;
        }
        try {
            $raw = \WHMCS\Config\Setting::getValue($key);
        } catch (\Throwable $e) {
            self::auditConfig('LocalApiClient: target allowlist read failed — failing closed', $e);
            return [];
        }
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        return self::parseIdCsv((string) $raw, $key);
    }

    /** @return int[] */
    private static function parseIdCsv(string $raw, string $key): array
    {
        $ids = [];
        foreach (explode(',', $raw) as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;
            if (!ctype_digit($tok) || (int) $tok <= 0) {
                self::auditConfig("LocalApiClient: invalid id in {$key} — failing closed");
                return [];
            }
            $ids[] = (int) $tok;
        }
        return $ids;
    }

    /**
     * true quando o comando enviará e-mail ao cliente com os params dados.
     *
     * Só as representações CANÔNICAS de `noemail` contam como supressão. A
     * documentação do WHMCS define `noemail` como boolean e não garante coerção
     * de strings como `'true'`; tratar `'true'` como supressão faria a decisão
     * de segurança depender de comportamento não documentado. Qualquer coisa
     * fora do canônico é lida como "vai notificar" ⇒ exige COMMS.
     */
    private function sendsNotification(string $command, array $params): bool
    {
        if (!in_array($command, self::NOTIFYING_COMMANDS, true)) {
            return false;
        }

        return !self::suppressesEmail($params['noemail'] ?? null);
    }

    /** Representações canônicas de "não envie e-mail". */
    private static function suppressesEmail(mixed $noemail): bool
    {
        return $noemail === true || $noemail === 1 || $noemail === '1';
    }

    /**
     * Normaliza `noemail` para boolean antes da LocalAPI, para que a supressão
     * nunca dependa de coerção textual do WHMCS. Um valor não canônico presente
     * já foi tratado como "notifica" por sendsNotification() (e portanto exigiu
     * COMMS); aqui ele vira `false` explícito em vez de seguir como string.
     */
    private static function normalizeNoEmail(string $command, array $params): array
    {
        if (!in_array($command, self::NOTIFYING_COMMANDS, true) || !array_key_exists('noemail', $params)) {
            return $params;
        }

        $params['noemail'] = self::suppressesEmail($params['noemail']);

        return $params;
    }

    private function clampImpersonation(string $command, array $params): array
    {
        if (!in_array($command, self::IMPERSONATION_COMMANDS, true)) return $params;

        if ($command === 'AddTicketReply') {
            $params['adminusername'] = $this->adminUser; // força o admin do token
            unset($params['adminid']);
            return $params;
        }
        // comandos baseados em adminid
        $id = $this->resolveAdminId($this->adminUser);
        if ($id === null) {
            throw new \RuntimeException(
                "LocalApiClient: cannot resolve admin id for '{$this->adminUser}'; refusing caller-supplied admin."
            );
        }
        $params['adminid'] = $id;
        unset($params['adminusername']);
        return $params;
    }

    private function resolveAdminId(string $username): ?int
    {
        if (array_key_exists($username, $this->adminIdCache)) return $this->adminIdCache[$username];
        if ($this->adminIdResolver !== null) {
            $id = ($this->adminIdResolver)($username);
            if ($id !== null) $this->adminIdCache[$username] = $id;
            return $id;
        }
        try {
            $row = \WHMCS\Database\Capsule::table('tbladmins')->where('username', $username)->first();
            $id = $row ? (int) $row->id : null;
            if ($id !== null) $this->adminIdCache[$username] = $id;
            return $id;
        } catch (\Throwable $e) {
            Diagnostics::report(Diagnostics::CATEGORY_ADMIN_LOOKUP, 'tbladmins', $e);
            return null;
        }
    }

    public function call(string $command, array $params = []): array
    {
        if (!in_array($command, self::ALLOWED_COMMANDS, true)) {
            // ---------------------------------------------------------------
            // SECURITY FIX (F8): Log blocked command attempts for forensics.
            // ---------------------------------------------------------------
            self::auditLog(ActivityEvent::API_BLOCKED_UNKNOWN, AuditMetadata::forParams($params));
            throw new AuthorizationException(
                "LocalApiClient: WHMCS API command '{$command}' is not in the allowed list."
            );
        }

        $this->assertModeAllows($command, $params);              // A
        $params = $this->clampImpersonation($command, $params);  // B
        $params = self::normalizeNoEmail($command, $params);     // B2 (m2)

        // ---------------------------------------------------------------
        // SECURITY FIX (F8 -- HIGH): Audit logging for every tool
        // invocation.  Writes to the WHMCS Activity Log with the client
        // IP, command name, and a redacted parameter summary so that
        // administrators have a forensic trail of all MCP operations.
        // ---------------------------------------------------------------
        // Identificador de correlação: liga a linha de início, a de desfecho e o
        // diagnóstico detalhado do error_log sem repetir dado nenhum entre eles.
        $audited = !in_array($command, self::AUDIT_SILENT_COMMANDS, true);
        $correlationId = $audited
            ? self::auditLog(ActivityEvent::API_CALL, AuditMetadata::forParams($params), command: $command)
            : null;

        // ---------------------------------------------------------------
        // m1.1: TODO desfecho é registrado — sucesso, erro em array, retorno
        // não-array e exceção. Antes, uma exceção do callable/localAPI deixava
        // apenas a linha de início, indistinguível de "morreu no meio".
        // Nenhuma dessas linhas repete os parâmetros: eles já foram gravados
        // (redigidos) no início, e repetir só infla o Activity Log.
        // ---------------------------------------------------------------
        try {
            if ($this->callable !== null) {
                $result = ($this->callable)($command, $params);
            } else {
                $result = localAPI($command, $params, $this->adminUser);
            }
        } catch (\Throwable $e) {
            // AuthorizationException é NOSSA e tem mensagem controlada: sobe
            // intacta para o chamador distinguir negação de falha.
            if ($e instanceof AuthorizationException) {
                throw $e;
            }

            if ($correlationId === null) {
                $correlationId = Diagnostics::newCorrelationId();
            }
            self::auditLog(ActivityEvent::API_EXCEPTION, null, $correlationId, $command);
            Diagnostics::log($correlationId, Diagnostics::CATEGORY_API_EXCEPTION, $command, $e);

            // O WHMCS não é consistente: para vários "não encontrado" ele LANÇA
            // em vez de devolver `result: error` (`GetInvoice` com id inexistente
            // é o caso observado). Tratar toda exceção como falha opaca fazia o
            // chamador receber `-32603 "Error while executing tool"` — sem
            // distinguir "esse id não existe" de "o WHMCS caiu", que pedem ações
            // opostas. A mensagem passa pelo MESMO classificador do ramo
            // `result === 'error'`: inspeção só em memória, e apenas o código do
            // enum sai daqui (regra 2 do docblock do ErrorClassifier).
            $classification = ErrorClassifier::classify($command, $e->getMessage(), $params);
            if ($classification['category'] !== ErrorClassifier::DOWNSTREAM) {
                return [
                    'result'         => 'error',
                    'error_code'     => $classification['code'],
                    'error_category' => $classification['category'],
                    'message'        => ErrorMessages::build($classification['code'], $command, $correlationId),
                    'correlation_id' => $correlationId,
                ];
            }

            // F2: a exceção original NÃO é relançada nem encadeada — o adapter
            // a converteria em "Tool execution failed: <mensagem crua>", e
            // `(string)$exception` inclui a cadeia anterior. Classe e
            // fingerprint da causa viajam como dado estruturado.
            throw new DownstreamFailureException(
                self::publicFailureMessage($command, $correlationId),
                $correlationId,
                Diagnostics::fingerprint($e->getMessage()),
                get_class($e)
            );
        }

        // ---------------------------------------------------------------
        // Só `result === 'success'` conta como sucesso. Qualquer outro array —
        // `[]`, `result` ausente, null ou desconhecido — é INDETERMINADO e
        // falha fechado. Antes, esses casos geravam um `OK` falso e devolviam a
        // resposta malformada como se tivesse dado certo.
        //
        // A API do WHMCS não é uniforme: a maioria dos comandos usa a chave
        // `result`, mas ao menos `GetInvoice` (confirmado no desenv, id
        // inexistente) devolve `status` em vez de `result` no mesmo formato
        // (`error`/`success`). `status` só é consultado quando `result`
        // ESTÁ AUSENTE — nunca sobrepõe um `result` presente — para não abrir
        // uma segunda leitura em comandos onde `status` já significa outra
        // coisa (ex.: status de ticket/pedido) e por acaso valeria a mesma
        // string. Antes desta correção, `GetInvoice` inexistente caía direto
        // no ramo INDETERMINADO abaixo e nunca era classificado — o chamador
        // MCP via só `-32603` genérico, mesmo com `ErrorClassifier` já tendo
        // `invoice_not_found` mapeado pra esse comando.
        // ---------------------------------------------------------------
        $outcome = is_array($result) ? ($result['result'] ?? $result['status'] ?? null) : null;

        // `GetProject` (confirmado no desenv, ids 1 e 2) não devolve `result`
        // NEM `status` no caso de sucesso — só `projectinfo` + `messages`. O
        // caso de erro ("project not found") segue o envelope padrão
        // `result: error`, então este fallback só cobre a lacuna do sucesso.
        if ($outcome === null && $command === 'GetProject' && is_array($result) && array_key_exists('projectinfo', $result)) {
            $outcome = 'success';
        }

        if ($outcome === 'success') {
            if ($audited) {
                self::auditLog(ActivityEvent::API_OK, null, $correlationId, $command);
            }
            // Pipeline ÚNICO de saída (objetos -> escalar, JSON-string -> array,
            // tipos inconsistentes, listas vazias omitidas, scrub por último).
            // A ORDEM é parte do contrato: ver ResponseRedactor::normalizeResponse().
            ResponseRedactor::normalizeResponse($result, $command);

            return $result;
        }

        if ($outcome === 'error') {
            // D6: a mensagem é inspecionada APENAS aqui, em memória, para
            // reduzir a um código do nosso enum. Nada derivado do texto sai
            // desta expressão — nem para o retorno, nem para o log.
            $downstreamMessage = is_array($result) ? (string) ($result['message'] ?? '') : '';
            // Os params entram só para detectar eco exato do input — nenhum
            // valor deles sai da classificação.
            $classification = ErrorClassifier::classify($command, $downstreamMessage, $params);

            if ($correlationId === null) {
                $correlationId = Diagnostics::newCorrelationId();
            }
            self::auditLog(ActivityEvent::API_ERROR, null, $correlationId, $command);
            Diagnostics::log(
                $correlationId,
                Diagnostics::CATEGORY_API_ERROR,
                $command,
                null,
                $downstreamMessage
            );

            // F2 + D6: o texto do WHMCS não atravessa; o chamador recebe um
            // código estável que permite ramificar (corrigir input, buscar o
            // existente, desistir) mais a correlação para o operador.
            return [
                'result' => 'error',
                'error_code' => $classification['code'],
                'error_category' => $classification['category'],
                'message' => ErrorMessages::build($classification['code'], $command, $correlationId),
                'correlation_id' => $correlationId,
            ];
        }

        // Indeterminado: não-array, ou array sem `result` canônico.
        //
        // Continua FALHANDO FECHADO — o chamador recebe erro, nunca um `OK`
        // falso —, mas em forma ESTRUTURADA em vez de exceção. Lançar aqui
        // fazia o SDK devolver `-32603 "Error while executing tool"`, sem código
        // nem correlação: `whmcs_get_project` de um projeto existente era
        // indistinguível de "o WHMCS caiu". O formato é o mesmo dos outros dois
        // ramos de erro, então quem já ramifica em `result === 'error'` (as
        // tools de cotação, por exemplo) não muda.
        if ($correlationId === null) {
            $correlationId = Diagnostics::newCorrelationId();
        }
        self::auditLog(ActivityEvent::API_MALFORMED, null, $correlationId, $command);
        Diagnostics::log($correlationId, Diagnostics::CATEGORY_API_MALFORMED, $command);

        return [
            'result'         => 'error',
            'error_code'     => 'downstream_malformed',
            'error_category' => ErrorClassifier::DOWNSTREAM,
            'message'        => ErrorMessages::build('downstream_malformed', $command, $correlationId),
            'correlation_id' => $correlationId,
        ];
    }

    /**
     * Mensagem pública ESTÁVEL do ramo de exceção downstream — nada aqui vem de
     * fora. Os ramos classificáveis usam `ErrorMessages::build()`, que escolhe a
     * frase pelo código do enum.
     */
    private static function publicFailureMessage(string $command, string $correlationId): string
    {
        return ErrorMessages::build('downstream_error', $command, $correlationId);
    }

    // ---------------------------------------------------------------
    // Audit helpers
    // ---------------------------------------------------------------

    /**
     * Write an entry to the WHMCS Activity Log (tblactivitylog).
     *
     * Toda entrada carrega um identificador de correlação, para que a linha de
     * início, a de desfecho e o diagnóstico detalhado do error_log possam ser
     * ligadas sem repetir dado nenhum entre elas.
     *
     * @param ActivityEvent      $event         evento fechado; mensagem livre é impossível
     * @param AuditMetadata|null $metadata      D7: só o value object; array arbitrário é impossível por tipo
     * @param string|null        $correlationId Reusa uma correlação existente; gera uma nova se null
     * @return string                           a correlação usada
     */
    public static function auditLog(
        ActivityEvent $event,
        ?AuditMetadata $metadata = null,
        ?string $correlationId = null,
        ?string $command = null,
    ): string
    {
        return ActivityLog::record($event, $metadata, $correlationId, $command);
    }

    public static function isAllowedCommand(string $command): bool
    {
        return in_array($command, self::ALLOWED_COMMANDS, true);
    }
}
