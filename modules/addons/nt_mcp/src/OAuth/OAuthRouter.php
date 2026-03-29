<?php

declare(strict_types=1);

namespace NtMcp\OAuth;

use NtMcp\OAuth\Handlers\AuthorizationHandler;
use NtMcp\OAuth\Handlers\MetadataHandler;
use NtMcp\OAuth\Handlers\RegistrationHandler;
use NtMcp\OAuth\Handlers\TokenHandler;
use NtMcp\Whmcs\SystemUrl;

/**
 * OAuth 2.1 endpoint router.
 * Routes PATH_INFO to the appropriate handler class.
 */
final class OAuthRouter
{
    public static function dispatch(): void
    {
        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        if ($pathInfo === '' && isset($_SERVER['REQUEST_URI'])) {
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
            if ($scriptName !== '' && str_starts_with($uri, $scriptName)) {
                $pathInfo = substr($uri, strlen($scriptName));
            }
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $oauthUrl  = SystemUrl::oauthUrl();
        $mcpUrl    = SystemUrl::mcpUrl();
        $issuerUrl = SystemUrl::issuerUrl();

        switch (true) {
            case $pathInfo === '/resource-metadata' && $method === 'GET':
                MetadataHandler::resourceMetadata($mcpUrl, $issuerUrl);
                break;

            case (str_contains($pathInfo, 'openid-configuration') || str_contains($pathInfo, 'oauth-authorization-server')) && $method === 'GET':
                MetadataHandler::serverMetadata($oauthUrl, $issuerUrl);
                break;

            case $pathInfo === '/register' && $method === 'POST':
                RegistrationHandler::handle();
                break;

            case $pathInfo === '/authorize' && $method === 'GET':
                AuthorizationHandler::handleGet();
                break;

            case $pathInfo === '/authorize' && $method === 'POST':
                AuthorizationHandler::handlePost();
                break;

            case $pathInfo === '/token' && $method === 'POST':
                TokenHandler::handle($oauthUrl);
                break;

            default:
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'not_found', 'error_description' => 'Unknown OAuth endpoint']);
                break;
        }
    }
}
