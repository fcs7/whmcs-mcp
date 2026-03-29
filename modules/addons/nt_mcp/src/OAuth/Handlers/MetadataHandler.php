<?php

declare(strict_types=1);

namespace NtMcp\OAuth\Handlers;

/**
 * OAuth metadata endpoints (RFC 9728, RFC 8414).
 */
final class MetadataHandler
{
    /** Protected Resource Metadata (RFC 9728) */
    public static function resourceMetadata(string $mcpUrl, string $issuerUrl): void
    {
        header('Content-Type: application/json');
        echo json_encode([
            'resource'                 => $mcpUrl,
            'authorization_servers'    => [$issuerUrl],
            'bearer_methods_supported' => ['header'],
        ], JSON_UNESCAPED_SLASHES);
    }

    /** Authorization Server Metadata (RFC 8414 / OpenID Discovery) */
    public static function serverMetadata(string $oauthUrl, string $issuerUrl): void
    {
        header('Content-Type: application/json');
        echo json_encode([
            'issuer'                                => $issuerUrl,
            'authorization_endpoint'                => $oauthUrl . '/authorize',
            'token_endpoint'                        => $oauthUrl . '/token',
            'registration_endpoint'                 => $oauthUrl . '/register',
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported'                      => ['mcp'],
        ], JSON_UNESCAPED_SLASHES);
    }
}
