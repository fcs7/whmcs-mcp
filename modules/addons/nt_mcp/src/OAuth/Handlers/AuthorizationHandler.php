<?php

declare(strict_types=1);

namespace NtMcp\OAuth\Handlers;

use Illuminate\Database\Capsule\Manager as Capsule;
use NtMcp\OAuth\OAuthHelper;
use NtMcp\Security\RateLimiter;
use NtMcp\Whmcs\SystemUrl;

/**
 * OAuth authorization endpoint.
 *
 * SECURITY FIX (V-03 -- CRITICAL CVSS 9.1): The approval form is rendered
 * inside the WHMCS admin panel (nt_mcp.php), NOT here.
 */
final class AuthorizationHandler
{
    /** GET /authorize — validate params, create pending request, redirect to admin panel */
    public static function handleGet(): void
    {
        // SECURITY FIX (M-03 -- MEDIUM): Rate limit authorization endpoint
        (new RateLimiter('nt_mcp_auth_rl_', 20, 60, 'auth_', 'Too many authorization requests. Maximum 20 per minute.'))->enforce();

        $clientId      = $_GET['client_id'] ?? '';
        $redirectUri   = $_GET['redirect_uri'] ?? '';
        $codeChallenge = $_GET['code_challenge'] ?? '';
        $state         = $_GET['state'] ?? '';
        $responseType  = $_GET['response_type'] ?? '';
        $codeChallengeMethod = $_GET['code_challenge_method'] ?? '';

        if ($responseType !== 'code') {
            OAuthHelper::error(400, 'unsupported_response_type', 'Only response_type=code is supported');
            return;
        }
        if ($codeChallenge === '') {
            OAuthHelper::error(400, 'invalid_request', 'code_challenge is required (PKCE)');
            return;
        }
        // V-14 fix: require S256
        if ($codeChallengeMethod !== '' && $codeChallengeMethod !== 'S256') {
            OAuthHelper::error(400, 'invalid_request', 'Only code_challenge_method=S256 is supported');
            return;
        }

        $client = Capsule::table('mod_nt_mcp_oauth_clients')
            ->where('client_id', $clientId)
            ->first();

        if (!$client) {
            // SECURITY FIX (M-04 -- MEDIUM): Generic error to prevent client_id enumeration
            OAuthHelper::error(400, 'invalid_request', 'Invalid request parameters');
            return;
        }

        $registeredUris = json_decode($client->redirect_uris, true) ?: [];
        if (!in_array($redirectUri, $registeredUris, true)) {
            OAuthHelper::error(400, 'invalid_request', 'redirect_uri does not match registered URIs');
            return;
        }

        // Create pending authorization request (to be approved in admin panel)
        $requestId = bin2hex(random_bytes(16));

        // SECURITY FIX (S2A-01): Store code as SHA-256 hash
        Capsule::table('mod_nt_mcp_oauth_codes')->insert([
            'code'           => hash('sha256', 'pending_' . $requestId),
            'client_id'      => $clientId,
            'code_challenge'  => $codeChallenge,
            'redirect_uri'   => $redirectUri,
            'state'          => $state,
            'expires_at'     => time() + 600, // 10 minutes
            'used'           => false,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        // Redirect to WHMCS admin panel for approval
        http_response_code(302);
        header('Location: ' . SystemUrl::adminAuthorizeUrl($requestId));
        exit;
    }

    /**
     * POST /authorize — no longer handled here.
     * Approval is processed inside the WHMCS admin panel (nt_mcp.php).
     */
    public static function handlePost(): void
    {
        OAuthHelper::error(400, 'invalid_request', 'Authorization approval must be done via the WHMCS admin panel. Use GET /authorize to start the flow.');
    }
}
