<?php

declare(strict_types=1);

namespace NtMcp\Http;

/**
 * SECURITY CONTROL (9.2 -- F13): TLS enforcement.
 * Reject plain HTTP requests to prevent credential exposure in transit.
 * Override: Set environment variable NT_MCP_ALLOW_HTTP=1 for local dev.
 */
final class TlsEnforcer
{
    public static function enforce(): void
    {
        if (self::isRequestSecure()) {
            return;
        }

        if (self::isHttpBypassAllowed()) {
            return;
        }

        http_response_code(421);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'TLS required. Plain HTTP requests are rejected for security.',
        ]);
        exit;
    }

    /**
     * SECURITY FIX (WO-4): Pure decision logic, side-effect free, so it can be
     * unit-tested without triggering enforce()'s exit.
     */
    public static function isRequestSecure(): bool
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (
                isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
                // SECURITY FIX (WO-4): only a trusted proxy may assert the
                // original scheme via X-Forwarded-Proto; otherwise it is spoofable.
                && IpResolver::isTrustedProxy($remoteAddr)
            )
        );
    }

    /**
     * SECURITY FIX (WO-4): NT_MCP_ALLOW_HTTP is only honored when the request
     * comes from a trusted proxy or a loopback/private REMOTE_ADDR — never for
     * arbitrary internet clients. Every time the bypass is actually granted,
     * it is logged.
     */
    public static function isHttpBypassAllowed(): bool
    {
        $envAllows = (
            getenv('NT_MCP_ALLOW_HTTP') === '1'
            || (isset($_ENV['NT_MCP_ALLOW_HTTP']) && $_ENV['NT_MCP_ALLOW_HTTP'] === '1')
        );

        if (!$envAllows) {
            return false;
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $trusted = IpResolver::isTrustedProxy($remoteAddr) || self::isLoopbackOrPrivate($remoteAddr);

        if ($trusted) {
            error_log('NT MCP: TLS enforcement bypassed via NT_MCP_ALLOW_HTTP from ' . $remoteAddr);
        }

        return $trusted;
    }

    private static function isLoopbackOrPrivate(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
