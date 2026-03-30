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

    /** @var callable|null Para injecao em testes */
    private $callable = null;

    public function __construct(private readonly string $adminUser = 'admin') {}

    public function setCallable(callable $fn): void
    {
        $this->callable = $fn;
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
