<?php
// src/Whmcs/LocalApiClient.php
namespace NtMcp\Whmcs;

use NtMcp\Http\IpResolver;

class LocalApiClient
{
    // ---------------------------------------------------------------
    // SECURITY FIX (F4 -- CVSS 9.1): Restrict callable WHMCS API
    // commands to only those used by the 64 MCP tools.
    //
    // Before this fix, call() accepted ANY command string, meaning a
    // compromised or malicious MCP tool caller could invoke destructive
    // or data-exfiltrating API actions such as AddAdmin, EncryptPassword,
    // WhoAmI, DecryptPassword, CreateSsoToken, etc.
    //
    // T1: reduzido de 73 para os 51 comandos efetivamente requeridos pela
    // superfície canônica de 64 tools. Comandos de custo/provisionamento
    // (ModuleSuspend, UpgradeProduct, DomainRegister, AcceptOrder, AddOrder...),
    // de comunicação (SendEmail, SendQuote) e lookups auxiliares saíram do
    // allowlist — não foram apenas desligados por gate.
    // ---------------------------------------------------------------

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
        // READ (38 tools de leitura mapeiam nestes comandos)
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

    private ?array $gatesOverride = null; // teste: ['write'=>bool,'destructive'=>bool,...,'readonly'=>bool]
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
     * auditado. Espelhado em CapsuleClient::isReadonly() pelo mesmo ConfigFlag.
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
    private static function auditConfig(string $message, ?\Throwable $e = null): void
    {
        $correlationId = Diagnostics::report(Diagnostics::CATEGORY_CONFIG_READ, 'nt_mcp_config', $e);
        self::auditLog($message, [], $correlationId);
    }

    private function assertModeAllows(string $command, array $params = []): void
    {
        $class = $this->classOf($command);
        if (!$this->gateEnabled($class)) {
            self::auditLog("MCP BLOCKED {$class} '{$command}' (gate disabled)", AuditMetadata::forParams($params));
            throw new AuthorizationException(
                "LocalApiClient: command '{$command}' is blocked (class {$class} disabled by config)."
            );
        }

        // Requisito ORTOGONAL: notificar o cliente exige COMMS ALÉM da classe
        // primária. Vale para qualquer caminho que chegue até aqui, inclusive
        // uma chamada direta ao LocalApiClient que omita 'noemail'.
        if ($this->sendsNotification($command, $params) && !$this->gateEnabled('COMMS')) {
            self::auditLog("MCP BLOCKED COMMS '{$command}' (notification requested, comms gate disabled)", AuditMetadata::forParams($params));
            throw new AuthorizationException(
                "LocalApiClient: command '{$command}' is blocked (client notification requires the COMMS gate)."
            );
        }
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
            self::auditLog("MCP BLOCKED command '{$command}' (not in allowlist)", AuditMetadata::forParams($params));
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
        $correlationId = self::auditLog("MCP API call: {$command}", AuditMetadata::forParams($params));

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

            self::auditLog("MCP API EXCEPTION {$command}", [], $correlationId);
            Diagnostics::log($correlationId, Diagnostics::CATEGORY_API_EXCEPTION, $command, $e);

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
        // ---------------------------------------------------------------
        $outcome = is_array($result) ? ($result['result'] ?? null) : null;

        if ($outcome === 'success') {
            self::auditLog("MCP API OK {$command}", [], $correlationId);
            ResponseRedactor::scrubSensitive($result);  // D defense-in-depth

            return $result;
        }

        if ($outcome === 'error') {
            // D6: a mensagem é inspecionada APENAS aqui, em memória, para
            // reduzir a um código do nosso enum. Nada derivado do texto sai
            // desta expressão — nem para o retorno, nem para o log.
            $downstreamMessage = is_array($result) ? (string) ($result['message'] ?? '') : '';
            $classification = ErrorClassifier::classify($command, $downstreamMessage);

            self::auditLog(
                "MCP API ERROR {$command} ({$classification['code']})",
                [],
                $correlationId
            );
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
                'message' => self::publicFailureMessage($command, $correlationId),
                'correlation_id' => $correlationId,
            ];
        }

        // Indeterminado: não-array, ou array sem `result` canônico.
        $type = is_array($result) ? 'array without canonical result' : gettype($result);
        self::auditLog("MCP API ERROR {$command} (malformed response: {$type})", [], $correlationId);
        Diagnostics::log($correlationId, Diagnostics::CATEGORY_API_MALFORMED, $command);

        throw new DownstreamFailureException(
            self::publicFailureMessage($command, $correlationId),
            $correlationId
        );
    }

    /** Mensagem pública ESTÁVEL — nada aqui vem de fora. */
    private static function publicFailureMessage(string $command, string $correlationId): string
    {
        return "The WHMCS API call '{$command}' did not complete successfully. "
            . "Details were recorded in the operator log under correlation id {$correlationId}.";
    }

    /** Correlação curta e sem valor semântico — não deriva de dado da chamada. */
    private static function newCorrelationId(): string
    {
        try {
            return bin2hex(random_bytes(4));
        } catch (\Throwable) {
            return str_pad(dechex(mt_rand(0, 0xFFFFFFFF)), 8, '0', STR_PAD_LEFT);
        }
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
     * @param string      $message       Human-readable description (estável, sem texto downstream)
     * @param array       $metadata      METADADOS já seguros (ver AuditMetadata) — nunca params crus
     * @param string|null $correlationId Reusa uma correlação existente; gera uma nova se null
     * @return string                    a correlação usada
     */
    public static function auditLog(string $message, array $metadata = [], ?string $correlationId = null): string
    {
        $correlationId ??= self::newCorrelationId();
        // SECURITY FIX (F3 -- audit, revised H1): Use IpResolver directly.
        // Behind Plesk reverse proxy, REMOTE_ADDR is 127.0.0.1 — useless for
        // forensics. IpResolver respects nt_mcp_trusted_proxies and walks XFF.
        $ip = IpResolver::resolve();

        // D7: o que chega aqui já deveria ser metadado de allowlist. Esta é a
        // última barreira — qualquer string fora de token curto é descartada,
        // para que um call-site futuro não reintroduza dump de valores.
        $summary = json_encode(self::sanitizeMetadata($metadata), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (strlen($summary) > 1024) {
            $summary = substr($summary, 0, 1021) . '...';
        }

        $entry = "[NT-MCP] [{$ip}] [corr:{$correlationId}] {$message} | meta: {$summary}";

        try {
            if (function_exists('logActivity')) {
                logActivity($entry);
            }
        } catch (\Throwable $e) {
            // Logging must never break the request flow, but we must never lose
            // forensic visibility silently either.
            //
            // O que NÃO pode acontecer aqui: concatenar `$entry` (que carrega o
            // dump de params) nem a mensagem da exceção. O sink que falhou é o
            // que redige — cair para o error_log com o texto cru transformaria
            // uma falha de log num vazamento.
            Diagnostics::log($correlationId, Diagnostics::CATEGORY_AUDIT_SINK, 'activity_log', $e);
        }

        return $correlationId;
    }

    /**
     * Última barreira do Activity Log (D7).
     *
     * `AuditMetadata` já entrega só metadado, mas esta função existe para que
     * um call-site novo — ou um refactor distraído — não consiga passar valores
     * de campo. Só sobrevivem: inteiros, booleanos, tokens curtos que casem
     * `[A-Za-z0-9_]` e listas desses tokens. Qualquer outra coisa vira um
     * marcador de tipo.
     */
    private static function sanitizeMetadata(array $metadata, int $depth = 0): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            $name = is_string($key) ? Diagnostics::safeToken($key) : (string) (int) $key;

            if (is_int($value) || is_bool($value)) {
                $safe[$name] = $value;
            } elseif (is_string($value)) {
                $safe[$name] = preg_match('/^[A-Za-z0-9_]{1,64}\z/', $value) === 1 ? $value : '[omitted]';
            } elseif (is_array($value)) {
                $safe[$name] = $depth >= 4 ? '[nested]' : self::sanitizeMetadata($value, $depth + 1);
            } elseif ($value === null) {
                $safe[$name] = null;
            } else {
                $safe[$name] = '[' . gettype($value) . ']';
            }
        }

        return $safe;
    }
}
