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
     * @param string $expectedHash  SHA-256 hex-digest of the real bearer token,
     *                              as stored in tblconfiguration.
     */
    public function __construct(private readonly string $expectedHash) {}

    /**
     * Authenticate the request and return the bound admin username.
     *
     * Returns the admin_user associated with the presented token, or null
     * if the token is invalid/expired.  For static tokens the admin comes
     * from nt_mcp_bearer_token_admin; for OAuth tokens from the DB row.
     *
     * Fallback chain: per-token admin_user → global nt_mcp_admin_user → 'admin'
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
        if (strlen($this->expectedHash) >= self::MIN_TOKEN_LENGTH
            && hash_equals($this->expectedHash, $presentedHash)) {
            return $this->getStaticTokenAdmin();
        }

        // Check 2: OAuth-issued token from mod_nt_mcp_oauth_tokens
        return $this->authenticateOAuthToken($presentedHash);
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
    private function getStaticTokenAdmin(): string
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
            return $admin !== '' ? $admin : $this->getFallbackAdmin();
        } catch (\Throwable $e) {
            // SECURITY FIX (F4 -- audit): Log DB failures instead of silently
            // returning null.  A database outage should not masquerade as an
            // authentication failure with zero diagnostic information.
            error_log('NT MCP BearerAuth: OAuth token validation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fallback admin resolution: global config → hardcoded 'admin'.
     */
    private function getFallbackAdmin(): string
    {
        try {
            $configured = trim(\WHMCS\Config\Setting::getValue('nt_mcp_admin_user') ?? '');
            if ($configured !== '') {
                return $configured;
            }
        } catch (\Throwable $e) {
            error_log('NT MCP BearerAuth: Failed to read nt_mcp_admin_user: ' . $e->getMessage());
        }

        error_log('NT MCP BearerAuth: WARNING - No admin_user configured, falling back to hardcoded "admin"');
        return 'admin';
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
