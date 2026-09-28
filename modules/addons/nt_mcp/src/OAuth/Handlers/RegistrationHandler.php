<?php

declare(strict_types=1);

namespace NtMcp\OAuth\Handlers;

use Illuminate\Database\Capsule\Manager as Capsule;
use NtMcp\OAuth\OAuthHelper;
use NtMcp\OAuth\RedirectUri;
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
        $terminal = (new RateLimiter('nt_mcp_reg_rl_', 20, 3600, 'reg_', 'Too many client registrations. Maximum 20 per hour.'))->enforce();
        if ($terminal !== null) {
            $terminal->emit();
            return;
        }

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
        if (empty($redirectUris) || !is_array($redirectUris) || !array_is_list($redirectUris) || count($redirectUris) > 10) {
            OAuthHelper::error(400, 'invalid_client_metadata', 'redirect_uris is required');
            return;
        }

        foreach ($redirectUris as $uri) {
            if (!RedirectUri::isAllowed($uri)) {
                OAuthHelper::error(400, 'invalid_redirect_uri', 'Invalid or unsupported redirect_uri');
                return;
            }
        }
        if (isset($input['client_name']) && !is_string($input['client_name'])) {
            OAuthHelper::error(400, 'invalid_client_metadata', 'client_name must be a string');
            return;
        }

        // SECURITY FIX (B3): prefix discriminador facilita auditoria/busca de
        // clients registrados via DCR público (RFC 7591).  Coluna client_id
        // é VARCHAR(64); "nt-mcp-" (7) + 32 hex = 39 chars, cabe.
        $clientId   = 'nt-mcp-' . bin2hex(random_bytes(16));
        // SECURITY FIX (L-01 -- LOW): Sanitize client_name to prevent stored XSS
        $clientName = substr(strip_tags($input['client_name'] ?? 'MCP Client'), 0, 255);

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
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], JSON_UNESCAPED_SLASHES);
    }
}
