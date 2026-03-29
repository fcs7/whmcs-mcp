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

    public function isValid(): bool
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return false;
        }

        $presentedToken = substr($header, 7);

        if (strlen($presentedToken) < self::MIN_TOKEN_LENGTH) {
            return false;
        }

        $presentedHash = hash('sha256', $presentedToken);

        // Check 1: Static token from tblconfiguration (original auth)
        if (strlen($this->expectedHash) >= self::MIN_TOKEN_LENGTH
            && hash_equals($this->expectedHash, $presentedHash)) {
            return true;
        }

        // Check 2: OAuth-issued token from mod_nt_mcp_oauth_tokens
        return $this->isValidOAuthToken($presentedHash);
    }

    private function isValidOAuthToken(string $tokenHash): bool
    {
        try {
            if (!Capsule::schema()->hasTable('mod_nt_mcp_oauth_tokens')) {
                return false;
            }

            $row = Capsule::table('mod_nt_mcp_oauth_tokens')
                ->where('token_hash', $tokenHash)
                ->where('expires_at', '>', time())
                ->first();

            return $row !== null;
        } catch (\Throwable $e) {
            // SECURITY FIX (F4 -- audit): Log DB failures instead of silently
            // returning false.  A database outage should not masquerade as an
            // authentication failure with zero diagnostic information.
            error_log('NT MCP BearerAuth: OAuth token validation failed: ' . $e->getMessage());
            return false;
        }
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
