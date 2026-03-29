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
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        );

        $allowHttp = (
            getenv('NT_MCP_ALLOW_HTTP') === '1'
            || (isset($_ENV['NT_MCP_ALLOW_HTTP']) && $_ENV['NT_MCP_ALLOW_HTTP'] === '1')
        );

        if (!$isHttps && !$allowHttp) {
            http_response_code(421);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'TLS required. Plain HTTP requests are rejected for security.',
            ]);
            exit;
        }
    }
}
