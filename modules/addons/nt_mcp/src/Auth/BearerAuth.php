<?php
// src/Auth/BearerAuth.php
namespace NtMcp\Auth;

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
        // SECURITY FIX (F1 -- CVSS 10.0): Reject stored hashes that are
        // missing or too short.  When the addon is deactivated the persisted
        // value is set to '' which would make any comparison trivially true.
        if (strlen($this->expectedHash) < self::MIN_TOKEN_LENGTH) {
            return false;
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return false;
        }

        $presentedToken = substr($header, 7);

        if (strlen($presentedToken) < self::MIN_TOKEN_LENGTH) {
            return false;
        }

        // ------------------------------------------------------------------
        // SECURITY FIX (F17 -- HIGH): Token hashing.
        //
        // The database stores only a SHA-256 hash of the bearer token.  We
        // hash the presented token with the same algorithm and then compare
        // the two hashes in constant time via hash_equals().
        //
        // This ensures that a database leak (SQL injection, backup exposure,
        // etc.) does not directly reveal a usable credential.
        // ------------------------------------------------------------------
        $presentedHash = hash('sha256', $presentedToken);

        return hash_equals($this->expectedHash, $presentedHash);
    }

    public static function denyAndExit(): never
    {
        http_response_code(401);
        header('WWW-Authenticate: Bearer realm="WHMCS MCP"');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}
