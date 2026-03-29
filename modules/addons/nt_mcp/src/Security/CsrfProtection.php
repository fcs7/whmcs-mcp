<?php

declare(strict_types=1);

namespace NtMcp\Security;

/**
 * CSRF helpers (F6).
 *
 * Generate and validate cryptographic CSRF nonces bound to the current
 * PHP session. The nonce is an HMAC of a random per-session secret and
 * a static purpose string, so it is both unpredictable and tied to the session.
 */
final class CsrfProtection
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['nt_mcp_csrf_secret'])) {
            $_SESSION['nt_mcp_csrf_secret'] = bin2hex(random_bytes(32));
        }
        return hash_hmac('sha256', 'nt_mcp_csrf', $_SESSION['nt_mcp_csrf_secret']);
    }

    public static function verify(string $submitted): bool
    {
        return hash_equals(self::token(), $submitted);
    }
}
