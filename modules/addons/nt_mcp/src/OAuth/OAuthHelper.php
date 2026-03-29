<?php

declare(strict_types=1);

namespace NtMcp\OAuth;

/**
 * Shared OAuth error response helper.
 */
final class OAuthHelper
{
    public static function error(int $httpCode, string $error, string $description): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode([
            'error'             => $error,
            'error_description' => $description,
        ], JSON_UNESCAPED_SLASHES);
    }
}
