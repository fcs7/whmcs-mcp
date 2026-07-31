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
     * Sensitive parameter keys whose values must NEVER appear in logs.
     * Values are replaced with '[REDACTED]' before writing audit entries.
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
            throw new \RuntimeException(
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
     * readonly master switch — FAIL-CLOSED: qualquer falha de leitura de config
     * é tratada como read-only (bloqueia escrita), consistente com
     * CapsuleClient::isReadonly(). O override de teste tem precedência.
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
            $v = \WHMCS\Config\Setting::getValue('nt_mcp_readonly');
            return $v === '1' || $v === 1 || $v === true;
        } catch (\Throwable $e) {
            error_log('NT MCP LocalApiClient: readonly config read failed — failing closed: ' . $e->getMessage());
            return true;
        }
    }

    /** Lê config booleana com override de teste e default seguro. */
    private function boolSetting(string $key, bool $default, string $overrideKey): bool
    {
        if ($this->gatesOverride !== null) {
            return (bool) ($this->gatesOverride[$overrideKey] ?? $default);
        }
        try {
            $v = \WHMCS\Config\Setting::getValue($key);
            if ($v === null || $v === '') return $default;
            return $v === '1' || $v === 1 || $v === true;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function assertModeAllows(string $command, array $params = []): void
    {
        $class = $this->classOf($command);
        if (!$this->gateEnabled($class)) {
            self::auditLog("MCP BLOCKED {$class} '{$command}' (gate disabled)", $params);
            throw new \RuntimeException(
                "LocalApiClient: command '{$command}' is blocked (class {$class} disabled by config)."
            );
        }

        // Requisito ORTOGONAL: notificar o cliente exige COMMS ALÉM da classe
        // primária. Vale para qualquer caminho que chegue até aqui, inclusive
        // uma chamada direta ao LocalApiClient que omita 'noemail'.
        if ($this->sendsNotification($command, $params) && !$this->gateEnabled('COMMS')) {
            self::auditLog("MCP BLOCKED COMMS '{$command}' (notification requested, comms gate disabled)", $params);
            throw new \RuntimeException(
                "LocalApiClient: command '{$command}' is blocked (client notification requires the COMMS gate)."
            );
        }
    }

    /** true quando o comando enviará e-mail ao cliente com os params dados. */
    private function sendsNotification(string $command, array $params): bool
    {
        if (!in_array($command, self::NOTIFYING_COMMANDS, true)) {
            return false;
        }

        $noemail = $params['noemail'] ?? false;

        return !($noemail === true || $noemail === 1 || $noemail === '1' || $noemail === 'true');
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
            error_log('NT MCP: resolveAdminId failed: ' . $e->getMessage());
            return null;
        }
    }

    public function call(string $command, array $params = []): array
    {
        if (!in_array($command, self::ALLOWED_COMMANDS, true)) {
            // ---------------------------------------------------------------
            // SECURITY FIX (F8): Log blocked command attempts for forensics.
            // ---------------------------------------------------------------
            self::auditLog("MCP BLOCKED command '{$command}' (not in allowlist)", $params);
            throw new \RuntimeException(
                "LocalApiClient: WHMCS API command '{$command}' is not in the allowed list."
            );
        }

        $this->assertModeAllows($command, $params);              // A
        $params = $this->clampImpersonation($command, $params);  // B

        // ---------------------------------------------------------------
        // SECURITY FIX (F8 -- HIGH): Audit logging for every tool
        // invocation.  Writes to the WHMCS Activity Log with the client
        // IP, command name, and a redacted parameter summary so that
        // administrators have a forensic trail of all MCP operations.
        // ---------------------------------------------------------------
        self::auditLog("MCP API call: {$command}", $params);

        if ($this->callable !== null) {
            $result = ($this->callable)($command, $params);
        } else {
            $result = localAPI($command, $params, $this->adminUser);
        }

        if (!is_array($result)) {
            $type = gettype($result);
            self::auditLog("MCP API call '{$command}' returned non-array ({$type})", $params);
            throw new \RuntimeException(
                "LocalApiClient: WHMCS API command '{$command}' returned unexpected type ({$type}). WHMCS may not be fully initialized."
            );
        }

        // ---------------------------------------------------------------
        // SECURITY FIX (F15 -- revised): Audit-log API errors but return
        // the WHMCS response as-is.  The original F15 threw a generic
        // RuntimeException that hid useful diagnostics ("Email already
        // exists", "Client Not Found") from the MCP caller, making
        // create/update tools unusable.  WHMCS API error messages are
        // user-facing by design and do not leak internal paths or SQL.
        // ---------------------------------------------------------------
        if (($result['result'] ?? '') === 'error') {
            self::auditLog(
                "MCP API ERROR {$command}: " . ($result['message'] ?? 'Unknown error'),
                $params
            );
        }

        ResponseRedactor::scrubSensitive($result);  // D defense-in-depth

        return $result;
    }

    // ---------------------------------------------------------------
    // Audit helpers
    // ---------------------------------------------------------------

    /**
     * Write an entry to the WHMCS Activity Log (tblactivitylog).
     *
     * @param string $message  Human-readable description
     * @param array  $params   Tool parameters (sensitive values redacted)
     */
    public static function auditLog(string $message, array $params = []): void
    {
        // SECURITY FIX (F3 -- audit, revised H1): Use IpResolver directly.
        // Behind Plesk reverse proxy, REMOTE_ADDR is 127.0.0.1 — useless for
        // forensics. IpResolver respects nt_mcp_trusted_proxies and walks XFF.
        $ip = IpResolver::resolve();
        $safe = self::redactParams($params);
        $summary = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Truncate the parameter summary to avoid bloating the log table
        if (strlen($summary) > 1024) {
            $summary = substr($summary, 0, 1021) . '...';
        }

        $entry = "[NT-MCP] [{$ip}] {$message} | params: {$summary}";

        try {
            if (function_exists('logActivity')) {
                logActivity($entry);
            }
        } catch (\Throwable $e) {
            // Logging must never break the request flow, but we must
            // never lose forensic visibility silently either.
            error_log("[NT-MCP] auditLog FAILED: {$e->getMessage()} | entry: {$entry}");
        }
    }

    /**
     * Replace sensitive parameter values with '[REDACTED]'.
     */
    private static function redactParams(array $params, int $depth = 0): array
    {
        $redacted = [];
        foreach ($params as $key => $value) {
            if (in_array(strtolower($key), self::REDACTED_PARAMS, true)) {
                $redacted[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $redacted[$key] = $depth >= 5 ? '[NESTED]' : self::redactParams($value, $depth + 1);
            } else {
                $redacted[$key] = $value;
            }
        }
        return $redacted;
    }
}
