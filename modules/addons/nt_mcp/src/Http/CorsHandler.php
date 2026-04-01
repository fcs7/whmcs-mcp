<?php

declare(strict_types=1);

namespace NtMcp\Http;

/**
 * CORS headers for browser-based MCP clients (Claude.ai Custom Connectors).
 * OPTIONS preflight must be handled before auth (no Authorization header).
 *
 * Origin policy (F9):
 * - nt_mcp_cors_origins empty/unset → Access-Control-Allow-Origin: * (default, backward-compat)
 * - nt_mcp_cors_origins set + HTTP_ORIGIN in list → specific origin + Vary: Origin
 * - nt_mcp_cors_origins set + HTTP_ORIGIN not in list → no CORS header (browser blocks)
 * - nt_mcp_cors_origins set + no HTTP_ORIGIN (CLI) → Access-Control-Allow-Origin: *
 */
final class CorsHandler
{
    /**
     * Emit CORS headers. Returns true if this was an OPTIONS preflight (caller should exit).
     *
     * @param string[] $exposeHeaders Additional headers to expose
     */
    public static function handle(array $exposeHeaders = [], string $methods = 'GET, POST, OPTIONS'): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = self::getAllowedOrigins();

        $originHeader = self::resolveOriginHeader($origin, $allowedOrigins);
        if ($originHeader !== null) {
            header('Access-Control-Allow-Origin: ' . $originHeader);
            if ($originHeader !== '*') {
                header('Vary: Origin');
            }
        }

        header('Access-Control-Allow-Methods: ' . $methods);
        header('Access-Control-Allow-Headers: Content-Type, Authorization, MCP-Protocol-Version, MCP-Session-Id');

        if ($exposeHeaders !== []) {
            header('Access-Control-Expose-Headers: ' . implode(', ', $exposeHeaders));
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            return true;
        }

        return false;
    }

    /**
     * Determines the Access-Control-Allow-Origin header value.
     *
     * Returns the specific origin if it is in the allowlist, null if the request
     * origin is present but not allowed, or '*' if no allowlist is configured or
     * no origin header was sent.
     *
     * @param string   $origin         The request's HTTP_ORIGIN (empty string if absent)
     * @param string[] $allowedOrigins Parsed allowlist (empty = not configured)
     * @return string|null             Header value, or null to omit the header
     */
    public static function resolveOriginHeader(string $origin, array $allowedOrigins): ?string
    {
        if ($origin !== '' && $allowedOrigins !== []) {
            return in_array($origin, $allowedOrigins, true) ? $origin : null;
        }
        return '*';
    }

    /**
     * Reads nt_mcp_cors_origins from WHMCS config (CSV of allowed origins).
     * Returns empty array if not configured or on error → falls back to wildcard.
     *
     * @return string[]
     */
    public static function getAllowedOrigins(): array
    {
        try {
            $raw = \WHMCS\Config\Setting::getValue('nt_mcp_cors_origins') ?? '';
            $origins = array_filter(array_map('trim', explode(',', $raw)));
            return array_values($origins);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
