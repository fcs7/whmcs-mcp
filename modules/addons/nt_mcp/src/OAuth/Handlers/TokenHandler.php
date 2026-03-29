<?php

declare(strict_types=1);

namespace NtMcp\OAuth\Handlers;

use Illuminate\Database\Capsule\Manager as Capsule;
use NtMcp\Http\IpResolver;
use NtMcp\OAuth\OAuthHelper;
use NtMcp\Security\RateLimiter;

/**
 * Token exchange — authorization_code grant with PKCE.
 */
final class TokenHandler
{
    public static function handle(string $oauthUrl): void
    {
        header('Content-Type: application/json');

        // SECURITY FIX (H-01 -- HIGH): Rate limit token endpoint
        (new RateLimiter('nt_mcp_tok_rl_', 30, 60, 'tok_', 'Too many token requests. Maximum 30 per minute.'))->enforce();

        // Accept both form-urlencoded and JSON
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $params = json_decode(file_get_contents('php://input'), true) ?: [];
        } else {
            $params = $_POST;
        }

        $grantType    = $params['grant_type'] ?? '';
        $code         = $params['code'] ?? '';
        $codeVerifier = $params['code_verifier'] ?? '';
        $redirectUri  = $params['redirect_uri'] ?? '';
        $clientId     = $params['client_id'] ?? '';

        if ($grantType !== 'authorization_code') {
            OAuthHelper::error(400, 'unsupported_grant_type', 'Only authorization_code is supported');
            try { logActivity("NT MCP: Token exchange FAILED from IP " . (IpResolver::resolve()) . ": unsupported grant_type"); } catch (\Throwable $e) {}
            return;
        }

        if ($code === '' || $codeVerifier === '') {
            OAuthHelper::error(400, 'invalid_request', 'code and code_verifier are required');
            try { logActivity("NT MCP: Token exchange FAILED from IP " . (IpResolver::resolve()) . ": missing code or code_verifier"); } catch (\Throwable $e) {}
            return;
        }

        // SECURITY FIX (S2A-01): Compare hash of presented code, not plaintext
        $codeRow = Capsule::table('mod_nt_mcp_oauth_codes')
            ->where('code', hash('sha256', $code))
            ->where('used', false)
            ->where('expires_at', '>', time())
            ->first();

        if (!$codeRow) {
            OAuthHelper::error(400, 'invalid_grant', 'Invalid, expired, or already used authorization code');
            try { logActivity("NT MCP: Token exchange FAILED from IP " . (IpResolver::resolve()) . ": invalid or expired authorization code"); } catch (\Throwable $e) {}
            return;
        }

        // SECURITY FIX (H-04 -- CRITICAL): Atomic code consumption to prevent replay
        $affected = Capsule::table('mod_nt_mcp_oauth_codes')
            ->where('id', $codeRow->id)
            ->where('used', false)
            ->update(['used' => true]);

        if ($affected === 0) {
            OAuthHelper::error(400, 'invalid_grant', 'Authorization code already consumed');
            try { logActivity("NT MCP: Token exchange FAILED from IP " . (IpResolver::resolve()) . ": authorization code already consumed (race condition)"); } catch (\Throwable $e) {}
            return;
        }

        // Validate client_id — RFC 6749 §4.1.3: required for public clients (no secret)
        if ($clientId === '' || $clientId !== $codeRow->client_id) {
            OAuthHelper::error(400, 'invalid_client', 'client_id is required and must match the authorization code');
            try { logActivity("NT MCP: Token exchange FAILED from IP " . (IpResolver::resolve()) . ": client_id missing or mismatch"); } catch (\Throwable $e) {}
            return;
        }

        // Validate redirect_uri — RFC 6749 §4.1.3: required when present in authorization request
        if ($redirectUri === '' || $redirectUri !== $codeRow->redirect_uri) {
            OAuthHelper::error(400, 'invalid_grant', 'redirect_uri is required and must match the authorization code');
            try { logActivity("NT MCP: Token exchange FAILED from IP " . (IpResolver::resolve()) . ": redirect_uri missing or mismatch"); } catch (\Throwable $e) {}
            return;
        }

        // PKCE S256 verification
        $computedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        if (!hash_equals($codeRow->code_challenge, $computedChallenge)) {
            OAuthHelper::error(400, 'invalid_grant', 'PKCE code_verifier verification failed');
            try { logActivity("NT MCP: Token exchange FAILED from IP " . (IpResolver::resolve()) . ": PKCE verification failed"); } catch (\Throwable $e) {}
            return;
        }

        // Issue access token
        $accessToken = bin2hex(random_bytes(32));
        $tokenHash   = hash('sha256', $accessToken);
        $expiresIn   = 86400; // 24 hours

        // SECURITY FIX (F7 -- audit): Wrap token insert in try/catch
        try {
            $tokenData = [
                'token_hash'  => $tokenHash,
                'client_id'   => $codeRow->client_id,
                'expires_at'  => time() + $expiresIn,
                'created_at'  => date('Y-m-d H:i:s'),
            ];
            // Propagate admin_user from the approving admin (post-migration)
            if (Capsule::schema()->hasColumn('mod_nt_mcp_oauth_tokens', 'admin_user')) {
                // SECURITY (F-13 fix): Guard against undefined property on pre-migration DBs
                $tokenData['admin_user'] = property_exists($codeRow, 'approved_by')
                    ? ($codeRow->approved_by ?? null)
                    : null;
            }
            Capsule::table('mod_nt_mcp_oauth_tokens')->insert($tokenData);
        } catch (\Throwable $dbEx) {
            error_log('NT MCP: Failed to insert OAuth token: ' . $dbEx->getMessage());
            OAuthHelper::error(500, 'server_error', 'Failed to persist access token');
            return;
        }

        // SECURITY FIX (L-03 -- LOW): Audit logging for token issuance
        try { logActivity("NT MCP: OAuth token issued for client '{$codeRow->client_id}' from IP " . (IpResolver::resolve())); } catch (\Throwable $e) {}

        // Cleanup expired tokens
        try {
            Capsule::table('mod_nt_mcp_oauth_tokens')
                ->where('expires_at', '<', time())
                ->delete();
        } catch (\Throwable $e) {
            // Non-critical: cleanup failure should not block token issuance
        }

        echo json_encode([
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => $expiresIn,
            'scope'        => 'mcp',
        ], JSON_UNESCAPED_SLASHES);
    }
}
