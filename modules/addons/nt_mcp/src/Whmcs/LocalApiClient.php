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

        // BillingTools
        'GetInvoices',
        'GetInvoice',
        'CreateInvoice',
        'AddInvoicePayment',
        'UpdateInvoice',
        'GetTransactions',

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

        // DomainTools
        'DomainRegister',
        'DomainRenew',
        'DomainUpdateNameservers',

        // SystemTools
        'GetStats',
        'SendEmail',
        'GetActivityLog',

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
    ];

    /**
     * Sensitive parameter keys whose values must NEVER appear in logs.
     * Values are replaced with '[REDACTED]' before writing audit entries.
     */
    private const REDACTED_PARAMS = [
        'password', 'password2', 'cardnum', 'cvv', 'expdate',
        'cardnumber', 'cvc', 'bankacct', 'bankcode',
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

        // ---------------------------------------------------------------
        // SECURITY FIX (F15 -- MEDIUM): Prevent information disclosure.
        //
        // Before this fix the raw WHMCS error message (which may contain
        // internal paths, SQL fragments, or stack traces) was thrown
        // directly back to the MCP caller.  Now we log the detailed
        // error server-side and return only a generic message.
        // ---------------------------------------------------------------
        if (($result['result'] ?? '') === 'error') {
            $internalMsg = $result['message'] ?? 'Unknown error';
            self::auditLog("MCP API ERROR {$command}: {$internalMsg}", $params);

            throw new \RuntimeException(
                "The requested operation ({$command}) could not be completed. "
                . 'Check the WHMCS activity log for details.'
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
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
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
            // Logging must never break the request flow.
            // Silently discard — the error itself is not actionable here.
        }
    }

    /**
     * Replace sensitive parameter values with '[REDACTED]'.
     */
    private static function redactParams(array $params): array
    {
        $redacted = [];
        foreach ($params as $key => $value) {
            if (in_array(strtolower($key), self::REDACTED_PARAMS, true)) {
                $redacted[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $redacted[$key] = self::redactParams($value);
            } else {
                $redacted[$key] = $value;
            }
        }
        return $redacted;
    }
}
