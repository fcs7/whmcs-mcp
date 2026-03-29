<?php

declare(strict_types=1);

namespace NtMcp\Http;

/**
 * CORS headers for browser-based MCP clients (Claude.ai Custom Connectors).
 * OPTIONS preflight must be handled before auth (no Authorization header).
 */
final class CorsHandler
{
    /**
     * Emit CORS headers. Returns true if this was an OPTIONS preflight (caller should exit).
     *
     * @param string[] $exposeHeaders Additional headers to expose
     */
    public static function handle(array $exposeHeaders = []): bool
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
}
