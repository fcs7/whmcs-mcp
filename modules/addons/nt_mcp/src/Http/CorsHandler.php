<?php

declare(strict_types=1);

namespace NtMcp\Http;

use NtMcp\Whmcs\Diagnostics;

/**
 * CORS headers for browser-based MCP clients (Claude.ai Custom Connectors).
 * OPTIONS preflight must be handled before auth (no Authorization header).
 *
 * Origin policy (F9):
 * - nt_mcp_cors_origins empty/unset → Access-Control-Allow-Origin: * (default, backward-compat)
 * - nt_mcp_cors_origins set + HTTP_ORIGIN in list → specific origin + Vary: Origin
 * - nt_mcp_cors_origins set + HTTP_ORIGIN not in list → envelope 403 sem ACAO
 * - nt_mcp_cors_origins set + no HTTP_ORIGIN (CLI) → Access-Control-Allow-Origin: *
 *
 * Fail-closed on config-read error (E): a real error reading nt_mcp_cors_origins
 * (e.g. DB failure) must NEVER be treated as "no allowlist configured" — that would
 * silently degrade to Access-Control-Allow-Origin: * on infra failure. handle()
 * responds 503 instead. See getAllowedOriginsOrFail(), mirrors IpAllowlist.php:20-28.
 */
final class CorsHandler
{
    /**
     * Devolve headers CORS validados e eventual envelope terminal. Não escreve
     * status, header, body nem encerra o processo.
     *
     * @param string[] $exposeHeaders Additional headers to expose
     */
    public static function handle(array $exposeHeaders = [], string $methods = 'GET, POST, OPTIONS'): CorsDecision
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = self::getAllowedOriginsOrFail();

        if ($allowedOrigins === false) {
            // Config read failed (DB error etc.) — fail closed: deny rather than silently
            // falling back to Access-Control-Allow-Origin: *.
            return CorsDecision::terminal(TerminalResponse::serviceUnavailable());
        }

        $originHeader = self::resolveOriginHeader($origin, $allowedOrigins);
        if ($origin !== '' && $allowedOrigins !== [] && $originHeader === null) {
            return CorsDecision::terminal(TerminalResponse::corsForbidden());
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            if (!self::allowsRequestedMethod($methods) || !self::allowsRequestedHeaders()) {
                return CorsDecision::terminal(TerminalResponse::corsForbidden());
            }
            return CorsDecision::preflight($originHeader, $exposeHeaders, $methods);
        }

        return CorsDecision::proceed($originHeader, $exposeHeaders, $methods);
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

    private static function allowsRequestedMethod(string $methods): bool
    {
        $requested = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? '';
        if ($requested === '') {
            return true;
        }

        return preg_match('/^[A-Z]+\z/D', $requested) === 1
            && in_array($requested, explode(', ', $methods), true);
    }

    private static function allowsRequestedHeaders(): bool
    {
        if (!array_key_exists('HTTP_ACCESS_CONTROL_REQUEST_HEADERS', $_SERVER)) {
            return true;
        }
        $requested = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'];
        if (!is_string($requested) || trim($requested) === '') {
            return false;
        }

        $allowed = [
            'content-type',
            'authorization',
            'mcp-protocol-version',
            'mcp-session-id',
        ];
        $seen = [];
        foreach (explode(',', $requested) as $header) {
            $header = trim($header);
            $normalized = strtolower($header);
            if ($header === ''
                || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/D', $header) !== 1
                || !in_array($normalized, $allowed, true)
                || isset($seen[$normalized])) {
                return false;
            }
            $seen[$normalized] = true;
        }

        return true;
    }

    /**
     * Reads nt_mcp_cors_origins from WHMCS config (CSV of allowed origins).
     *
     * Back-compat wrapper around getAllowedOriginsOrFail(): treats a real config-read
     * error the same as "not configured" (empty array). Callers that need to
     * distinguish the two (i.e. handle()) must use getAllowedOriginsOrFail() instead,
     * since collapsing "error" into "empty" here would resolve to a wildcard origin.
     *
     * @return string[]
     */
    public static function getAllowedOrigins(): array
    {
        $result = self::getAllowedOriginsOrFail();
        return $result === false ? [] : $result;
    }

    /**
     * Reads nt_mcp_cors_origins from WHMCS config (CSV of allowed origins).
     *
     * Unlike getAllowedOrigins(), distinguishes "not configured" ([]) from
     * "config read failed" (false) so callers can fail closed (E) instead of
     * silently falling back to Access-Control-Allow-Origin: *. Returns array|false
     * rather than exiting directly so this stays unit-testable; the actual
     * fail-closed response (503 + exit) is emitted by handle().
     *
     * @return string[]|false
     */
    public static function getAllowedOriginsOrFail()
    {
        try {
            $raw = \WHMCS\Config\Setting::getValue('nt_mcp_cors_origins') ?? '';
        } catch (\Throwable $e) {
            Diagnostics::report(Diagnostics::CATEGORY_CONFIG_READ, 'nt_mcp_cors_origins', $e);
            return false;
        }

        $origins = array_filter(array_map('trim', explode(',', $raw)));
        return array_values($origins);
    }
}
