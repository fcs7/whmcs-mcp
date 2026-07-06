<?php
// src/Whmcs/LocalApiClient.php
namespace NtMcp\Whmcs;

class LocalApiClient
{
    // ---------------------------------------------------------------
    // SECURITY FIX (F4 -- CVSS 9.1): Restrict callable WHMCS API
    // commands to only those used by the 54 MCP tools.
    //
    // Before this fix, call() accepted ANY command string, meaning a
    // compromised or malicious MCP tool caller could invoke destructive
    // or data-exfiltrating API actions such as AddAdmin, EncryptPassword,
    // WhoAmI, DecryptPassword, CreateSsoToken, etc.
    // ---------------------------------------------------------------

    /** Exhaustive allowlist of WHMCS API commands used by the addon tools. */
    private const ALLOWED_COMMANDS = [
        // ClientTools
        'GetClients',
        'GetClientsDetails',
        'AddClient',
        'UpdateClient',
        'CloseClient',
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
        'CreateInvoice',
        'AddInvoicePayment',
        'UpdateInvoice',
        'GetTransactions',
        'AddCredit',
        'GetCredits',
        'AddTransaction',
        'UpdateTransaction',
        'AddBillableItem',
        'GetPayMethods',

        // ServiceTools
        'ModuleSuspend',
        'ModuleUnsuspend',
        'ModuleTerminate',
        'UpgradeProduct',

        // TicketTools
        'GetTickets',
        'GetTicket',
        'OpenTicket',
        'AddTicketReply',
        'UpdateTicket',

        // OrderTools
        'GetOrders',
        'AcceptOrder',
        'CancelOrder',
        'DeleteOrder',
        'AddOrder',
        'GetOrderStatuses',
        'GetProducts',
        'GetPromotions',
        'PendingOrder',

        // DomainTools
        'DomainRegister',
        'DomainRenew',
        'DomainUpdateNameservers',
        'DomainGetNameservers',
        'DomainGetLockingStatus',
        'DomainGetWhoisInfo',
        'GetTLDPricing',
        'UpdateClientDomain',

        // SystemTools
        'GetStats',
        'SendEmail',
        'GetActivityLog',
        'GetAdminDetails',
        'GetCurrencies',
        'GetEmailTemplates',
        'GetPaymentMethods',
        'GetToDoItems',
        'GetToDoItemStatuses',
        'UpdateToDoItem',
        'LogActivity',

        // ProjectManagerTools
        'GetProjects',
        'GetProject',
        'CreateProject',
        'UpdateProject',
        'AddProjectTask',
        'UpdateProjectTask',
        'DeleteProjectTask',
        'StartTaskTimer',
        'EndTaskTimer',
        'AddProjectMessage',

        // QuoteTools
        'GetQuotes',
        'CreateQuote',
        'UpdateQuote',
        'SendQuote',
        'AcceptQuote',

        // SupportInfoTools
        'GetSupportDepartments',
        'GetSupportStatuses',
        'GetTicketCounts',
        'GetTicketNotes',
        'GetTicketPredefinedCats',
        'GetTicketPredefinedReplies',
        'GetTicketAttachment',
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

    /** Classe de efeito colateral por comando. Fail-safe: ausente ⇒ WRITE. */
    private const COMMAND_CLASS = [
        // READ
        'GetClients'=>'READ','GetClientsDetails'=>'READ','GetClientsProducts'=>'READ',
        'GetClientsDomains'=>'READ','GetContacts'=>'READ','GetClientGroups'=>'READ',
        'GetClientsAddons'=>'READ','GetInvoices'=>'READ','GetInvoice'=>'READ',
        'GetTransactions'=>'READ','GetCredits'=>'READ','GetPayMethods'=>'READ',
        'GetTickets'=>'READ','GetTicket'=>'READ','GetOrders'=>'READ','GetOrderStatuses'=>'READ',
        'GetProducts'=>'READ','GetPromotions'=>'READ','DomainGetNameservers'=>'READ',
        'DomainGetLockingStatus'=>'READ','DomainGetWhoisInfo'=>'READ','GetTLDPricing'=>'READ',
        'GetStats'=>'READ','GetActivityLog'=>'READ','GetAdminDetails'=>'READ','GetCurrencies'=>'READ',
        'GetEmailTemplates'=>'READ','GetPaymentMethods'=>'READ','GetToDoItems'=>'READ',
        'GetToDoItemStatuses'=>'READ','GetProjects'=>'READ','GetProject'=>'READ','GetQuotes'=>'READ',
        'GetSupportDepartments'=>'READ','GetSupportStatuses'=>'READ','GetTicketCounts'=>'READ',
        'GetTicketNotes'=>'READ','GetTicketPredefinedCats'=>'READ','GetTicketPredefinedReplies'=>'READ',
        'GetTicketAttachment'=>'READ',
        // WRITE (reversível)
        'AddClient'=>'WRITE','UpdateClient'=>'WRITE','AddContact'=>'WRITE','UpdateContact'=>'WRITE',
        'ModuleSuspend'=>'WRITE','ModuleUnsuspend'=>'WRITE','OpenTicket'=>'WRITE',
        'AddTicketReply'=>'WRITE','UpdateTicket'=>'WRITE','CancelOrder'=>'WRITE','PendingOrder'=>'WRITE',
        'DomainUpdateNameservers'=>'WRITE','UpdateClientDomain'=>'WRITE','UpdateToDoItem'=>'WRITE',
        'LogActivity'=>'WRITE','CreateProject'=>'WRITE','UpdateProject'=>'WRITE','AddProjectTask'=>'WRITE',
        'UpdateProjectTask'=>'WRITE','StartTaskTimer'=>'WRITE','EndTaskTimer'=>'WRITE',
        'AddProjectMessage'=>'WRITE','CreateQuote'=>'WRITE','UpdateQuote'=>'WRITE','AcceptQuote'=>'WRITE',
        // DESTRUCTIVE (irreversível)
        'CloseClient'=>'DESTRUCTIVE','ModuleTerminate'=>'DESTRUCTIVE','DeleteOrder'=>'DESTRUCTIVE',
        'DeleteProjectTask'=>'DESTRUCTIVE',
        // FINANCIAL
        'CreateInvoice'=>'FINANCIAL','AddInvoicePayment'=>'FINANCIAL','UpdateInvoice'=>'FINANCIAL',
        'AddCredit'=>'FINANCIAL','AddTransaction'=>'FINANCIAL','UpdateTransaction'=>'FINANCIAL',
        'AddBillableItem'=>'FINANCIAL',
        // COST (custo/provisionamento externo)
        'DomainRegister'=>'COST','DomainRenew'=>'COST','UpgradeProduct'=>'COST',
        'AcceptOrder'=>'COST','AddOrder'=>'COST',
        // COMMS (envio de e-mail)
        'SendEmail'=>'COMMS','SendQuote'=>'COMMS',
    ];

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
        return self::COMMAND_CLASS[$command] ?? 'WRITE'; // fail-safe
    }

    private function gateEnabled(string $class): bool
    {
        if ($class === 'READ') return true;
        if ($this->isReadonly()) return false; // master switch (fail-closed)
        [$key, $default] = match ($class) {
            'WRITE'       => ['nt_mcp_enable_write', true],   // WRITE habilitado por padrão
            'DESTRUCTIVE' => ['nt_mcp_enable_destructive', false],
            'FINANCIAL'   => ['nt_mcp_enable_financial', false],
            'COST'        => ['nt_mcp_enable_cost', false],
            'COMMS'       => ['nt_mcp_enable_comms', false],
            default       => ['nt_mcp_enable_write', false],
        };
        return $this->boolSetting($key, $default, strtolower($class));
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

    private function assertModeAllows(string $command): void
    {
        $class = $this->classOf($command);
        if (!$this->gateEnabled($class)) {
            self::auditLog("MCP BLOCKED {$class} '{$command}' (gate disabled)", []);
            throw new \RuntimeException(
                "LocalApiClient: command '{$command}' is blocked (class {$class} disabled by config)."
            );
        }
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

        $this->assertModeAllows($command);                       // A
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
        // SECURITY FIX (F3 -- audit): Use proxy-aware IP when available.
        // Behind Plesk reverse proxy, REMOTE_ADDR is 127.0.0.1 — useless
        // for forensics. The entry-point files (mcp.php, oauth.php) define
        // IP resolution functions; use them if available.
        $ip = 'unknown';
        if (function_exists('_ntMcpGetClientIp')) {
            $ip = _ntMcpGetClientIp();
        } elseif (function_exists('_oauthGetClientIp')) {
            $ip = _oauthGetClientIp();
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
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
