<?php
// src/Auth/BearerAuth.php
namespace NtMcp\Auth;

use Illuminate\Database\Capsule\Manager as Capsule;

class BearerAuth
{
    /**
     * Minimum token length to prevent empty-token bypass when addon is
     * deactivated and the stored secret is cleared to ''.
     *
     * Applied to the *stored hash* (64-char hex SHA-256) as well as to the
     * *presented token* (64-char hex from bin2hex(random_bytes(32))).
     */
    private const MIN_TOKEN_LENGTH = 32;

    /**
     * FASE 4a (débito #5 — 5B): os seams de teste viram constructor injection
     * (caminho preferido, explícito). Os setters setXCallable() abaixo
     * permanecem como back-compat para os testes existentes e apenas reatribuem
     * estas mesmas propriedades — nenhuma lógica de auth (B1/B4/WO-7) muda.
     *
     * @param string       $expectedHash    SHA-256 hex-digest do bearer token real
     *                                       (armazenado em tblconfiguration).
     * @param \Closure|null $oauthLookup     Seam: fn(string $tokenHash): ?object
     *                                       (substitui Capsule em authenticateOAuthToken()).
     * @param \Closure|null $adminValidator  Seam (B1): fn(string $username): bool.
     * @param \Closure|null $tokenRevoker    Seam (B1): fn(int $tokenId): void.
     */
    public function __construct(
        private readonly string $expectedHash,
        private ?\Closure $oauthLookup = null,
        private ?\Closure $adminValidator = null,
        private ?\Closure $tokenRevoker = null,
    ) {}

    /** Injeta callable para testes: fn(string $tokenHash): ?object */
    public function setOAuthLookupCallable(\Closure $fn): void
    {
        $this->oauthLookup = $fn;
    }

    /** Injeta callable para testes (B1): fn(string $username): bool */
    public function setAdminValidatorCallable(\Closure $fn): void
    {
        $this->adminValidator = $fn;
    }

    /** Injeta callable para testes (B1): fn(int $tokenId): void */
    public function setTokenRevokerCallable(\Closure $fn): void
    {
        $this->tokenRevoker = $fn;
    }

    /**
     * Authenticate the request and return the bound admin username.
     *
     * Returns the admin_user associated with the presented token, or null
     * if the token is invalid/expired.  For static tokens the admin comes
     * from nt_mcp_bearer_token_admin; for OAuth tokens from the DB row.
     *
     * Fallback chain: per-token admin_user → global nt_mcp_admin_user → null (deny)
     */
    public function authenticate(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $presentedToken = substr($header, 7);

        if (strlen($presentedToken) < self::MIN_TOKEN_LENGTH) {
            return null;
        }

        $presentedHash = hash('sha256', $presentedToken);

        // Check 1: Static token from tblconfiguration (original auth)
        // SECURITY FIX (B4): opt-in bypass — when nt_mcp_disable_static_bearer=1
        // only OAuth-issued tokens are accepted, shrinking the auth surface to
        // the full OAuth 2.1 path with per-token admin binding.
        if (!$this->isStaticBearerDisabled()
            && strlen($this->expectedHash) >= self::MIN_TOKEN_LENGTH
            && hash_equals($this->expectedHash, $presentedHash)) {
            $admin = $this->getStaticTokenAdmin();
            // SECURITY FIX (B1): confirm admin still active in tbladmins.
            return $this->validateAdminActive($admin) ? $admin : null;
        }

        // Check 2: OAuth-issued token from mod_nt_mcp_oauth_tokens
        return $this->authenticateOAuthToken($presentedHash);
    }

    /**
     * SECURITY FIX (B4): opt-in flag to refuse the static Bearer token,
     * forcing clients through the OAuth 2.1 flow (per-token admin binding,
     * revocable, approved via UI).  Default false for backwards compat.
     */
    private function isStaticBearerDisabled(): bool
    {
        try {
            $v = trim((string) (\WHMCS\Config\Setting::getValue('nt_mcp_disable_static_bearer') ?? ''));
            return $v === '1' || strtolower($v) === 'true' || strtolower($v) === 'on';
        } catch (\Throwable $e) {
            error_log('NT MCP BearerAuth: Failed to read nt_mcp_disable_static_bearer: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Backward-compatible boolean check — wraps authenticate().
     */
    public function isValid(): bool
    {
        return $this->authenticate() !== null;
    }

    /**
     * Resolve admin username for the static bearer token.
     */
    private function getStaticTokenAdmin(): ?string
    {
        try {
            $admin = trim(\WHMCS\Config\Setting::getValue('nt_mcp_bearer_token_admin') ?? '');
            if ($admin !== '') {
                return $admin;
            }
        } catch (\Throwable $e) {
            error_log('NT MCP BearerAuth: Failed to read nt_mcp_bearer_token_admin: ' . $e->getMessage());
        }

        return $this->getFallbackAdmin();
    }

    /**
     * Validate an OAuth token and return the bound admin username.
     * Updates last_used_at on successful authentication.
     */
    private function authenticateOAuthToken(string $tokenHash): ?string
    {
        // Test injection point — bypasses Capsule when set
        if ($this->oauthLookup !== null) {
            $row = ($this->oauthLookup)($tokenHash);
            if ($row === null) {
                return null;
            }
            $adminUser = property_exists($row, 'admin_user') ? trim((string) ($row->admin_user ?? '')) : '';
            $resolved = $adminUser !== '' ? $adminUser : $this->getFallbackAdmin();
            // SECURITY FIX (B1): validate admin active; revoke token if orphan.
            if (!$this->validateAdminActive($resolved)) {
                $tokenId = property_exists($row, 'id') ? (int) $row->id : 0;
                if ($tokenId > 0) {
                    $this->revokeToken($tokenId);
                }
                return null;
            }
            return $resolved;
        }

        try {
            if (!Capsule::schema()->hasTable('mod_nt_mcp_oauth_tokens')) {
                return null;
            }

            $row = Capsule::table('mod_nt_mcp_oauth_tokens')
                ->where('token_hash', $tokenHash)
                ->where('expires_at', '>', time())
                ->first();

            if ($row === null) {
                return null;
            }

            // Update last_used_at for audit tracking (best-effort, guard pre-migration DBs)
            if (Capsule::schema()->hasColumn('mod_nt_mcp_oauth_tokens', 'last_used_at')) {
                try {
                    Capsule::table('mod_nt_mcp_oauth_tokens')
                        ->where('id', $row->id)
                        ->update(['last_used_at' => time()]);
                } catch (\Throwable $e) {
                    error_log('NT MCP BearerAuth: last_used_at update failed for token ID ' . $row->id . ': ' . $e->getMessage());
                }
            }

            $admin = property_exists($row, 'admin_user') ? trim($row->admin_user ?? '') : '';
            $resolved = $admin !== '' ? $admin : $this->getFallbackAdmin();

            // SECURITY FIX (B1): orphan-token defense — confirm admin still
            // exists in tbladmins and is not disabled.  If not, revoke the
            // token (expires_at=0) and return 401.
            if (!$this->validateAdminActive($resolved)) {
                if (function_exists('logActivity')) {
                    @logActivity("[NT-MCP] OAuth token revoked: admin '{$resolved}' missing or disabled (token id {$row->id})");
                }
                $this->revokeToken((int) $row->id);
                return null;
            }

            return $resolved;
        } catch (\Throwable $e) {
            // SECURITY FIX (F4 -- audit): Log DB failures instead of silently
            // returning null.  A database outage should not masquerade as an
            // authentication failure with zero diagnostic information.
            error_log('NT MCP BearerAuth: OAuth token validation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * SECURITY FIX (B1): confirm the admin username is still present in
     * tbladmins and not disabled.  Prevents orphan tokens from surviving
     * admin deletion/deactivation up to 4h (OAuth token TTL) / forever
     * (static token).  Fails closed on DB error — availability of
     * tbladmins is a hard prerequisite anyway.
     */
    private function validateAdminActive(?string $username): bool
    {
        // WO-7 × B1: getFallbackAdmin() pode retornar null (nenhum admin
        // configurado → fail-closed). Tratamos null/'' como "sem admin válido"
        // → deny, unificando o fail-closed do WO-7 com a defesa de orphan token.
        if ($username === null || $username === '') {
            return false;
        }
        if ($this->adminValidator !== null) {
            return (bool) ($this->adminValidator)($username);
        }
        try {
            return (bool) Capsule::table('tbladmins')
                ->where('username', $username)
                ->where('disabled', 0)
                ->exists();
        } catch (\Throwable $e) {
            error_log('NT MCP BearerAuth: tbladmins validation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * SECURITY FIX (B1): revoke an OAuth token by expiring it.  No
     * `revoked` column exists, but the existing authenticate() filter
     * (expires_at > time()) already rejects the row.
     */
    private function revokeToken(int $tokenId): void
    {
        if ($this->tokenRevoker !== null) {
            ($this->tokenRevoker)($tokenId);
            return;
        }
        try {
            Capsule::table('mod_nt_mcp_oauth_tokens')
                ->where('id', $tokenId)
                ->update(['expires_at' => 0]);
        } catch (\Throwable $e) {
            error_log('NT MCP BearerAuth: failed to revoke token id ' . $tokenId . ': ' . $e->getMessage());
        }
    }

    /**
     * Fallback admin resolution: global config → null (fail-closed).
     *
     * SECURITY FIX (WO-7): previously fell back to a hardcoded 'admin' when
     * nt_mcp_admin_user was empty, silently binding every unconfigured token
     * to the superadmin account. Now returns null so authenticate() denies
     * the request (401) instead of granting superadmin access by default.
     */
    private function getFallbackAdmin(): ?string
    {
        try {
            $configured = trim(\WHMCS\Config\Setting::getValue('nt_mcp_admin_user') ?? '');
            if ($configured !== '') {
                return $configured;
            }
        } catch (\Throwable $e) {
            error_log('NT MCP BearerAuth: Failed to read nt_mcp_admin_user: ' . $e->getMessage());
        }

        error_log('NT MCP BearerAuth: WARNING - No admin_user configured, denying (no fallback admin)');
        return null;
    }

    /**
     * @param string $resourceMetadataUrl  Full URL to the Protected Resource
     *                                     Metadata endpoint (RFC 9728).
     */
    public static function denyAndExit(string $resourceMetadataUrl = ''): never
    {
        http_response_code(401);

        if ($resourceMetadataUrl !== '') {
            header('WWW-Authenticate: Bearer resource_metadata="' . $resourceMetadataUrl . '"');
        } else {
            header('WWW-Authenticate: Bearer realm="WHMCS MCP"');
        }

        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}
