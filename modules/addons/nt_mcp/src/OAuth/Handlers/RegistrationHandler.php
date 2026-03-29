<?php

declare(strict_types=1);

namespace NtMcp\OAuth\Handlers;

use Illuminate\Database\Capsule\Manager as Capsule;
use NtMcp\OAuth\OAuthHelper;
use NtMcp\Security\RateLimiter;

/**
 * Dynamic Client Registration (RFC 7591).
 *
 * SECURITY FIX (V-02 -- HIGH CVSS 7.5): Rate limit, validate URIs,
 * enforce max client count to prevent resource exhaustion.
 */
final class RegistrationHandler
{
    public static function handle(): void
    {
        (new RateLimiter('nt_mcp_reg_rl_', 20, 3600, 'reg_', 'Too many client registrations. Maximum 20 per hour.'))->enforce();

        // Max client count: prevent DB exhaustion
        $maxClients = 50;
        $clientCount = Capsule::table('mod_nt_mcp_oauth_clients')->count();
        if ($clientCount >= $maxClients) {
            OAuthHelper::error(429, 'too_many_clients', 'Maximum number of registered clients reached (' . $maxClients . ')');
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            OAuthHelper::error(400, 'invalid_request', 'Invalid JSON body');
            return;
        }

        $redirectUris = $input['redirect_uris'] ?? [];
        if (empty($redirectUris) || !is_array($redirectUris)) {
            OAuthHelper::error(400, 'invalid_client_metadata', 'redirect_uris is required');
            return;
        }

        // Validate redirect URIs
        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || $uri === '') {
                OAuthHelper::error(400, 'invalid_redirect_uri', 'Each redirect_uri must be a non-empty string');
                return;
            }
            $parsed = parse_url($uri);
            if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
                OAuthHelper::error(400, 'invalid_redirect_uri', 'Invalid redirect_uri format: ' . $uri);
                return;
            }
            // Allow http://localhost and http://127.0.0.1 for local development (MCP clients)
            $isLocalhost = in_array($parsed['host'], ['localhost', '127.0.0.1', '[::1]'], true);
            if ($parsed['scheme'] !== 'https' && !($parsed['scheme'] === 'http' && $isLocalhost)) {
                OAuthHelper::error(400, 'invalid_redirect_uri', 'redirect_uri must use HTTPS (except localhost): ' . $uri);
                return;
            }
            // Reject fragments (OAuth 2.1 requirement)
            if (isset($parsed['fragment'])) {
                OAuthHelper::error(400, 'invalid_redirect_uri', 'redirect_uri must not contain a fragment');
                return;
            }
        }

        $clientId   = bin2hex(random_bytes(16));
        // SECURITY FIX (L-01 -- LOW): Sanitize client_name to prevent stored XSS
        $clientName = strip_tags($input['client_name'] ?? 'MCP Client');

        Capsule::table('mod_nt_mcp_oauth_clients')->insert([
            'client_id'     => $clientId,
            'client_name'   => substr($clientName, 0, 255),
            'redirect_uris' => json_encode($redirectUris),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        header('Content-Type: application/json');
        http_response_code(201);
        // Return only known/safe fields — do not reflect raw $input (RFC 7591 §3.2.1)
        echo json_encode([
            'client_id'                  => $clientId,
            'client_name'                => $clientName,
            'redirect_uris'              => $redirectUris,
            'client_id_issued_at'        => time(),
            'client_secret_expires_at'   => 0,
            'grant_types'                => ['authorization_code'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], JSON_UNESCAPED_SLASHES);
    }
}
