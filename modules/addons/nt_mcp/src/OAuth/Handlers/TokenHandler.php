<?php

declare(strict_types=1);

namespace NtMcp\OAuth\Handlers;

use NtMcp\Whmcs\Diagnostics;
use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\ActivityLog;

use Illuminate\Database\Capsule\Manager as Capsule;
use NtMcp\OAuth\OAuthHelper;
use NtMcp\OAuth\RefreshTokenService;
use NtMcp\Security\RateLimiter;

/**
 * Token exchange — authorization_code grant with PKCE, and refresh_token
 * grant with rotation (OAuth 2.1 §6.1). Access token TTL: 4h (SECURITY FIX
 * B2, não regredir). Refresh token TTL: 30 dias, sliding a cada rotação
 * (ver `RefreshTokenService`).
 */
final class TokenHandler
{
    /** SECURITY FIX (B2): TTL reduzido de 24h → 4h. */
    private const ACCESS_TOKEN_TTL = 14400; // 4 hours

    public static function handle(string $oauthUrl): void
    {
        header('Content-Type: application/json');

        // SECURITY FIX (H-01 -- HIGH): Rate limit token endpoint
        $terminal = (new RateLimiter('nt_mcp_tok_rl_', 30, 60, 'tok_', 'Too many token requests. Maximum 30 per minute.'))->enforce();
        if ($terminal !== null) {
            $terminal->emit();
            return;
        }

        // Accept both form-urlencoded and JSON
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $params = json_decode(file_get_contents('php://input'), true) ?: [];
        } else {
            $params = $_POST;
        }

        $grantType = $params['grant_type'] ?? '';

        switch ($grantType) {
            case 'authorization_code':
                self::handleAuthorizationCode($params);
                return;
            case 'refresh_token':
                self::handleRefreshToken($params);
                return;
            default:
                OAuthHelper::error(400, 'unsupported_grant_type', 'Only authorization_code and refresh_token are supported');
                ActivityLog::record(ActivityEvent::OAUTH_TOKEN_DENIED);
                return;
        }
    }

    private static function handleAuthorizationCode(array $params): void
    {
        $code         = $params['code'] ?? '';
        $codeVerifier = $params['code_verifier'] ?? '';
        $redirectUri  = $params['redirect_uri'] ?? '';
        $clientId     = $params['client_id'] ?? '';

        if ($code === '' || $codeVerifier === '') {
            OAuthHelper::error(400, 'invalid_request', 'code and code_verifier are required');
            ActivityLog::record(ActivityEvent::OAUTH_TOKEN_DENIED);
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
            ActivityLog::record(ActivityEvent::OAUTH_TOKEN_DENIED);
            return;
        }

        // SECURITY FIX (H-04 -- CRITICAL): Atomic code consumption to prevent replay
        $affected = Capsule::table('mod_nt_mcp_oauth_codes')
            ->where('id', $codeRow->id)
            ->where('used', false)
            ->update(['used' => true]);

        if ($affected === 0) {
            OAuthHelper::error(400, 'invalid_grant', 'Authorization code already consumed');
            ActivityLog::record(ActivityEvent::OAUTH_TOKEN_DENIED);
            return;
        }

        // Validate client_id — RFC 6749 §4.1.3: required for public clients (no secret)
        if ($clientId === '' || $clientId !== $codeRow->client_id) {
            OAuthHelper::error(400, 'invalid_client', 'client_id is required and must match the authorization code');
            ActivityLog::record(ActivityEvent::OAUTH_TOKEN_DENIED);
            return;
        }

        // Validate redirect_uri — RFC 6749 §4.1.3: required when present in authorization request
        if ($redirectUri === '' || $redirectUri !== $codeRow->redirect_uri) {
            OAuthHelper::error(400, 'invalid_grant', 'redirect_uri is required and must match the authorization code');
            ActivityLog::record(ActivityEvent::OAUTH_TOKEN_DENIED);
            return;
        }

        // PKCE S256 verification
        $computedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        if (!hash_equals($codeRow->code_challenge, $computedChallenge)) {
            OAuthHelper::error(400, 'invalid_grant', 'PKCE code_verifier verification failed');
            ActivityLog::record(ActivityEvent::OAUTH_TOKEN_DENIED);
            return;
        }

        // Propagate admin_user from the approving admin (post-migration)
        $adminUser = property_exists($codeRow, 'approved_by')
            ? ($codeRow->approved_by ?? null)
            : null;

        // refresh-token-grant (F4): família nova para este par access+refresh
        // — gerada explicitamente aqui e compartilhada entre os dois, porque
        // o valor gerado internamente por issue($familyId = null) não é
        // devolvido pela assinatura do serviço.
        $familyId = RefreshTokenService::newFamilyId();

        if (!self::issueTokenPair($codeRow->client_id, $adminUser, $familyId, ActivityEvent::OAUTH_TOKEN_ISSUED)) {
            return;
        }

        // Cleanup expired tokens
        try {
            Capsule::table('mod_nt_mcp_oauth_tokens')
                ->where('expires_at', '<', time())
                ->delete();
        } catch (\Throwable $e) {
            // Non-critical: cleanup failure should not block token issuance
        }
    }

    private static function handleRefreshToken(array $params): void
    {
        $refreshToken = $params['refresh_token'] ?? '';
        $clientId     = $params['client_id'] ?? '';

        if ($refreshToken === '' || $clientId === '') {
            OAuthHelper::error(400, 'invalid_request', 'refresh_token and client_id are required');
            ActivityLog::record(ActivityEvent::OAUTH_REFRESH_DENIED);
            return;
        }

        $result = (new RefreshTokenService())->redeem($refreshToken, $clientId, time());

        if ($result->reuseDetected) {
            OAuthHelper::error(400, 'invalid_grant', 'Refresh token reuse detected; the token family has been revoked');
            ActivityLog::record(ActivityEvent::OAUTH_REFRESH_REUSE_DETECTED);
            return;
        }

        if (!$result->ok) {
            OAuthHelper::error(400, 'invalid_grant', 'Invalid, expired, or already used refresh token');
            ActivityLog::record(ActivityEvent::OAUTH_REFRESH_DENIED);
            return;
        }

        self::issueTokenPair($result->clientId, $result->adminUser, $result->familyId, ActivityEvent::OAUTH_REFRESH_ISSUED);
    }

    /**
     * Emite o par access token (4h) + refresh token (30d, mesma família) e
     * escreve a resposta JSON. Usado por ambos os grants — a única diferença
     * entre eles é como `$familyId`/`$adminUser` foram obtidos.
     */
    private static function issueTokenPair(string $clientId, ?string $adminUser, string $familyId, ActivityEvent $issuedEvent): bool
    {
        $accessToken = bin2hex(random_bytes(32));
        $tokenHash   = hash('sha256', $accessToken);

        // SECURITY FIX (F7 -- audit): Wrap token insert in try/catch
        try {
            $tokenData = [
                'token_hash'  => $tokenHash,
                'client_id'   => $clientId,
                'expires_at'  => time() + self::ACCESS_TOKEN_TTL,
                'created_at'  => date('Y-m-d H:i:s'),
            ];
            $schema = Capsule::schema();
            if ($schema->hasColumn('mod_nt_mcp_oauth_tokens', 'admin_user')) {
                // SECURITY (F-13 fix): Guard against undefined property on pre-migration DBs
                $tokenData['admin_user'] = $adminUser;
            }
            // refresh-token-grant (F4): liga o access token à família de
            // refresh que o emitiu, para que revokeFamily() consiga revogar
            // ambos no reuso detectado / admin inativo / revoke individual.
            if ($schema->hasColumn('mod_nt_mcp_oauth_tokens', 'family_id')) {
                $tokenData['family_id'] = $familyId;
            }
            Capsule::table('mod_nt_mcp_oauth_tokens')->insert($tokenData);
        } catch (\Throwable $dbEx) {
            Diagnostics::report(Diagnostics::CATEGORY_OAUTH, 'token_insert', $dbEx);
            OAuthHelper::error(500, 'server_error', 'Failed to persist access token');
            return false;
        }

        // O insert do refresh precisa do MESMO tratamento do access (SECURITY
        // FIX F7): sem try/catch, uma falha aqui sobe como exceção não tratada
        // DEPOIS de o access já estar gravado — 500 sem corpo JSON e um access
        // token órfão vivo por 4h. Em caso de falha, desfaz o access recém-
        // inserido para não deixar credencial sem par de renovação.
        try {
            $refreshToken = (new RefreshTokenService())->issue($clientId, $adminUser, $familyId, time());
        } catch (\Throwable $dbEx) {
            Diagnostics::report(Diagnostics::CATEGORY_OAUTH, 'refresh_token_insert', $dbEx);
            try {
                Capsule::table('mod_nt_mcp_oauth_tokens')->where('token_hash', $tokenHash)->delete();
            } catch (\Throwable $rollbackEx) {
                Diagnostics::report(Diagnostics::CATEGORY_OAUTH, 'access_token_rollback', $rollbackEx);
            }
            OAuthHelper::error(500, 'server_error', 'Failed to persist refresh token');
            return false;
        }

        // SECURITY FIX (L-03 -- LOW): Audit logging for token issuance
        ActivityLog::record($issuedEvent);

        echo json_encode([
            'access_token'  => $accessToken,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TOKEN_TTL,
            'refresh_token' => $refreshToken,
            'scope'         => 'mcp',
        ], JSON_UNESCAPED_SLASHES);

        return true;
    }
}
