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

        if ($origin !== '' && $allowedOrigins !== []) {
            // Allowlist is configured and request has an Origin header
            if (in_array($origin, $allowedOrigins, true)) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
            }
            // else: origin not in allowlist — omit header, browser will block the request
        } else {
            // No allowlist configured, or no Origin header (CLI clients) — emit wildcard
            header('Access-Control-Allow-Origin: *');
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
