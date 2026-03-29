<?php
/**
 * NT MCP — OAuth 2.1 Authorization Server
 *
 * Implements OAuth 2.1 with PKCE (S256) for MCP Streamable HTTP transport.
 * All endpoints routed via PATH_INFO on this single file.
 *
 * Endpoints:
 *   GET  /resource-metadata                    → Protected Resource Metadata (RFC 9728)
 *   GET  /.well-known/openid-configuration     → Authorization Server Metadata (RFC 8414)
 *   POST /register                             → Dynamic Client Registration (RFC 7591)
 *   GET  /authorize                            → Authorization page
 *   POST /authorize                            → Process approval
 *   POST /token                                → Token exchange
 */

define('CLIENTAREA', true);
require_once __DIR__ . '/../../../init.php';

use Illuminate\Database\Capsule\Manager as Capsule;

// ---------------------------------------------------------------------------
// SECURITY FIX (F1 -- audit): TLS enforcement.
// OAuth 2.1 (RFC 6749 §3.1) requires TLS on ALL endpoints.
// Reject plain HTTP to prevent credential exposure in transit.
// Override: Set environment variable NT_MCP_ALLOW_HTTP=1 for local dev.
// ---------------------------------------------------------------------------
(function () {
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
})();

// ---------------------------------------------------------------------------
// SECURITY FIX (F2 -- audit): Security response headers.
// Defence-in-depth against XSS, clickjacking, MIME sniffing, cache leaks.
// ---------------------------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'");
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Permitted-Cross-Domain-Policies: none');
header('Referrer-Policy: no-referrer');

// ---------------------------------------------------------------------------
// SECURITY FIX (S2A-03): Resolve real client IP behind reverse proxies.
// Mirrors _ntMcpGetClientIp() from mcp.php to ensure consistent IP resolution
// across all endpoints.  Without this, oauth.php rate limiters see the proxy
// IP (127.0.0.1 on Plesk), sharing one bucket for all clients.
// ---------------------------------------------------------------------------
function _oauthGetClientIp(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remoteAddr === '') {
        return '0.0.0.0';
    }

    $trustedProxies = ['127.0.0.1', '::1'];
    try {
        $configured = \WHMCS\Config\Setting::getValue('nt_mcp_trusted_proxies') ?? '';
        if ($configured !== '') {
            $trustedProxies = array_merge(
                $trustedProxies,
                array_filter(array_map('trim', explode(',', $configured)))
            );
        }
    } catch (\Throwable $e) {
        // Setting not available
    }

    if (!in_array($remoteAddr, $trustedProxies, true)) {
        return $remoteAddr;
    }

    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff === '') {
        return $remoteAddr;
    }

    $ips = array_map('trim', explode(',', $xff));
    for ($i = count($ips) - 1; $i >= 0; $i--) {
        $ip = $ips[$i];
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $trustedProxies, true)) {
            return $ip;
        }
    }

    return $remoteAddr;
}

// ---------------------------------------------------------------------------
// Base URL derivation (from WHMCS System URL, never from Host header)
// ---------------------------------------------------------------------------
$systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL') ?? '', '/');
if ($systemUrl === '') {
    try {
        $systemUrl = rtrim(\App::getSystemURL(), '/');
    } catch (\Throwable $e) {
        $systemUrl = 'https://localhost';
    }
}
// OAuth 2.1 requires HTTPS.  Upgrade the scheme when the current request
// arrived over TLS but WHMCS SystemURL was saved with http://.
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
);
if ($isHttps && str_starts_with($systemUrl, 'http://')) {
    $systemUrl = 'https://' . substr($systemUrl, 7);
}
$baseUrl   = $systemUrl . '/modules/addons/nt_mcp';
$oauthUrl  = $baseUrl . '/oauth.php';
$mcpUrl    = $baseUrl . '/mcp.php';
// Issuer is the origin only (no path) so that RFC 8414 discovery uses
// /.well-known/oauth-authorization-server without a sub-path suffix.
// The actual endpoints still live at oauth.php/... — only the identifier changes.
$issuerUrl = $systemUrl;

// ---------------------------------------------------------------------------
// Ensure OAuth tables exist (lazy creation)
// ---------------------------------------------------------------------------
(function () {
    $schema = Capsule::schema();

    if (!$schema->hasTable('mod_nt_mcp_oauth_clients')) {
        $schema->create('mod_nt_mcp_oauth_clients', function ($t) {
            $t->increments('id');
            $t->string('client_id', 64)->unique();
            $t->string('client_name', 255)->nullable();
            $t->text('redirect_uris');
            $t->timestamp('created_at')->useCurrent();
        });
    }

    if (!$schema->hasTable('mod_nt_mcp_oauth_codes')) {
        $schema->create('mod_nt_mcp_oauth_codes', function ($t) {
            $t->increments('id');
            $t->string('code', 128)->unique();
            $t->string('client_id', 64);
            $t->string('code_challenge', 128);
            $t->string('redirect_uri', 2048);
            $t->string('state', 255)->nullable();
            $t->integer('expires_at');
            $t->boolean('used')->default(false);
            $t->timestamp('created_at')->useCurrent();
        });
    }

    if (!$schema->hasTable('mod_nt_mcp_oauth_tokens')) {
        $schema->create('mod_nt_mcp_oauth_tokens', function ($t) {
            $t->increments('id');
            $t->string('token_hash', 64)->unique();
            $t->string('client_id', 64);
            $t->integer('expires_at');
            $t->timestamp('created_at')->useCurrent();
        });
    }

})();

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($pathInfo === '' && isset($_SERVER['REQUEST_URI'])) {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
    if ($scriptName !== '' && str_starts_with($uri, $scriptName)) {
        $pathInfo = substr($uri, strlen($scriptName));
    }
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, MCP-Protocol-Version');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

switch (true) {
    case $pathInfo === '/resource-metadata' && $method === 'GET':
        handleResourceMetadata($mcpUrl, $issuerUrl);
        break;

    case (str_contains($pathInfo, 'openid-configuration') || str_contains($pathInfo, 'oauth-authorization-server')) && $method === 'GET':
        handleServerMetadata($oauthUrl, $issuerUrl);
        break;

    case $pathInfo === '/register' && $method === 'POST':
        handleRegister();
        break;

    case $pathInfo === '/authorize' && $method === 'GET':
        handleAuthorizeGet();
        break;

    case $pathInfo === '/authorize' && $method === 'POST':
        handleAuthorizePost();
        break;

    case $pathInfo === '/token' && $method === 'POST':
        handleToken($oauthUrl);
        break;

    default:
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'not_found', 'error_description' => 'Unknown OAuth endpoint']);
        break;
}
exit;

// ===========================================================================
// Endpoint handlers
// ===========================================================================

/** Protected Resource Metadata (RFC 9728) */
function handleResourceMetadata(string $mcpUrl, string $issuerUrl): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'resource'                 => $mcpUrl,
        'authorization_servers'    => [$issuerUrl],
        'bearer_methods_supported' => ['header'],
    ], JSON_UNESCAPED_SLASHES);
}

/** Authorization Server Metadata (RFC 8414 / OpenID Discovery) */
function handleServerMetadata(string $oauthUrl, string $issuerUrl): void
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

/** Dynamic Client Registration (RFC 7591) */
function handleRegister(): void
{
    // ------------------------------------------------------------------
    // SECURITY FIX (V-02 -- HIGH CVSS 7.5): Rate limit, validate URIs,
    // enforce max client count to prevent resource exhaustion and
    // redirect URI poisoning from unauthenticated registration.
    // ------------------------------------------------------------------

    // Rate limit: max 20 registrations per hour per IP
    enforceRegistrationRateLimit();

    // Max client count: prevent DB exhaustion
    $maxClients = 50;
    $clientCount = Capsule::table('mod_nt_mcp_oauth_clients')->count();
    if ($clientCount >= $maxClients) {
        oauthError(429, 'too_many_clients', 'Maximum number of registered clients reached (' . $maxClients . ')');
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        oauthError(400, 'invalid_request', 'Invalid JSON body');
        return;
    }

    $redirectUris = $input['redirect_uris'] ?? [];
    if (empty($redirectUris) || !is_array($redirectUris)) {
        oauthError(400, 'invalid_client_metadata', 'redirect_uris is required');
        return;
    }

    // Validate redirect URIs
    foreach ($redirectUris as $uri) {
        if (!is_string($uri) || $uri === '') {
            oauthError(400, 'invalid_redirect_uri', 'Each redirect_uri must be a non-empty string');
            return;
        }
        $parsed = parse_url($uri);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            oauthError(400, 'invalid_redirect_uri', 'Invalid redirect_uri format: ' . $uri);
            return;
        }
        // Allow http://localhost and http://127.0.0.1 for local development (MCP clients).
        // All other redirect URIs must use HTTPS.
        $isLocalhost = in_array($parsed['host'], ['localhost', '127.0.0.1', '[::1]'], true);
        if ($parsed['scheme'] !== 'https' && !($parsed['scheme'] === 'http' && $isLocalhost)) {
            oauthError(400, 'invalid_redirect_uri', 'redirect_uri must use HTTPS (except localhost): ' . $uri);
            return;
        }
        // Reject fragments (OAuth 2.1 requirement)
        if (isset($parsed['fragment'])) {
            oauthError(400, 'invalid_redirect_uri', 'redirect_uri must not contain a fragment');
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
    echo json_encode(array_merge($input, [
        'client_id'                => $clientId,
        'client_name'              => $clientName,
        'client_id_issued_at'      => time(),
        'client_secret_expires_at' => 0,
    ]), JSON_UNESCAPED_SLASHES);
}

/**
 * Authorization GET — validate params, create pending request, redirect to admin panel.
 *
 * SECURITY FIX (V-03 -- CRITICAL CVSS 9.1): The approval form is rendered
 * inside the WHMCS admin panel (nt_mcp.php), NOT here. This ensures the
 * admin must be logged into WHMCS to approve OAuth grants.
 *
 * Defense in depth:
 *  1. WHMCS requires admin login to access configaddonmods.php (native gate)
 *  2. nt_mcp.php verifies $_SESSION['adminid'] explicitly (second check)
 *  3. CSRF token tied to admin session (third check)
 *  4. Pending request expires in 10 minutes (temporal window)
 *  5. Admin ID logged in WHMCS activity log (audit trail)
 */
function handleAuthorizeGet(): void
{
    // SECURITY FIX (M-03 -- MEDIUM): Rate limit authorization endpoint
    enforceAuthorizeRateLimit();

    $clientId      = $_GET['client_id'] ?? '';
    $redirectUri   = $_GET['redirect_uri'] ?? '';
    $codeChallenge = $_GET['code_challenge'] ?? '';
    $state         = $_GET['state'] ?? '';
    $responseType  = $_GET['response_type'] ?? '';
    $codeChallengeMethod = $_GET['code_challenge_method'] ?? '';

    if ($responseType !== 'code') {
        oauthError(400, 'unsupported_response_type', 'Only response_type=code is supported');
        return;
    }
    if ($codeChallenge === '') {
        oauthError(400, 'invalid_request', 'code_challenge is required (PKCE)');
        return;
    }
    // V-14 fix: require S256
    if ($codeChallengeMethod !== '' && $codeChallengeMethod !== 'S256') {
        oauthError(400, 'invalid_request', 'Only code_challenge_method=S256 is supported');
        return;
    }

    $client = Capsule::table('mod_nt_mcp_oauth_clients')
        ->where('client_id', $clientId)
        ->first();

    if (!$client) {
        // SECURITY FIX (M-04 -- MEDIUM): Generic error to prevent client_id enumeration
        oauthError(400, 'invalid_request', 'Invalid request parameters');
        return;
    }

    $registeredUris = json_decode($client->redirect_uris, true) ?: [];
    if (!in_array($redirectUri, $registeredUris, true)) {
        oauthError(400, 'invalid_request', 'redirect_uri does not match registered URIs');
        return;
    }

    // Create pending authorization request (to be approved in admin panel)
    $requestId = bin2hex(random_bytes(16));

    // SECURITY FIX (S2A-01): Store code as SHA-256 hash to match the protection
    // model used for Bearer tokens and OAuth access tokens.  The plaintext
    // $requestId is passed to the admin panel via URL; only the hash is persisted.
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
    // The admin panel URL uses the system URL + /admin/ (WHMCS default).
    // The admin must be logged in to access this page.
    $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL') ?? '', '/');
    if ($systemUrl === '') {
        try {
            $systemUrl = rtrim(\App::getSystemURL(), '/');
        } catch (\Throwable $e) {
            oauthError(500, 'server_error', 'Cannot determine WHMCS system URL');
            return;
        }
    }
    // Upgrade to HTTPS if current request is HTTPS
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    );
    if ($isHttps && str_starts_with($systemUrl, 'http://')) {
        $systemUrl = 'https://' . substr($systemUrl, 7);
    }

    $adminUrl = $systemUrl . '/admin/addonmodules.php?module=nt_mcp&authorize=' . urlencode($requestId);

    http_response_code(302);
    header('Location: ' . $adminUrl);
    exit;
}

/**
 * Authorization POST — no longer handled here.
 *
 * Approval is processed inside the WHMCS admin panel (nt_mcp.php) where
 * admin session is guaranteed. Direct POST to oauth.php/authorize returns
 * an error directing the user to use the admin panel.
 */
function handleAuthorizePost(): void
{
    oauthError(400, 'invalid_request', 'Authorization approval must be done via the WHMCS admin panel. Use GET /authorize to start the flow.');
}

/** Token exchange — authorization_code grant with PKCE */
function handleToken(string $oauthUrl): void
{
    header('Content-Type: application/json');

    // SECURITY FIX (H-01 -- HIGH): Rate limit token endpoint
    enforceTokenRateLimit();

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
        oauthError(400, 'unsupported_grant_type', 'Only authorization_code is supported');
        try { logActivity("NT MCP: Token exchange FAILED from IP " . (_oauthGetClientIp()) . ": unsupported grant_type"); } catch (\Throwable $e) {}
        return;
    }

    if ($code === '' || $codeVerifier === '') {
        oauthError(400, 'invalid_request', 'code and code_verifier are required');
        try { logActivity("NT MCP: Token exchange FAILED from IP " . (_oauthGetClientIp()) . ": missing code or code_verifier"); } catch (\Throwable $e) {}
        return;
    }

    // SECURITY FIX (S2A-01): Compare hash of presented code, not plaintext
    $codeRow = Capsule::table('mod_nt_mcp_oauth_codes')
        ->where('code', hash('sha256', $code))
        ->where('used', false)
        ->where('expires_at', '>', time())
        ->first();

    if (!$codeRow) {
        oauthError(400, 'invalid_grant', 'Invalid, expired, or already used authorization code');
        try { logActivity("NT MCP: Token exchange FAILED from IP " . (_oauthGetClientIp()) . ": invalid or expired authorization code"); } catch (\Throwable $e) {}
        return;
    }

    // SECURITY FIX (H-04 -- CRITICAL): Atomic code consumption to prevent replay via race condition
    $affected = Capsule::table('mod_nt_mcp_oauth_codes')
        ->where('id', $codeRow->id)
        ->where('used', false)
        ->update(['used' => true]);

    if ($affected === 0) {
        oauthError(400, 'invalid_grant', 'Authorization code already consumed');
        try { logActivity("NT MCP: Token exchange FAILED from IP " . (_oauthGetClientIp()) . ": authorization code already consumed (race condition)"); } catch (\Throwable $e) {}
        return;
    }

    // Validate client_id
    if ($clientId !== '' && $clientId !== $codeRow->client_id) {
        oauthError(400, 'invalid_grant', 'client_id mismatch');
        try { logActivity("NT MCP: Token exchange FAILED from IP " . (_oauthGetClientIp()) . ": client_id mismatch"); } catch (\Throwable $e) {}
        return;
    }

    // Validate redirect_uri
    if ($redirectUri !== '' && $redirectUri !== $codeRow->redirect_uri) {
        oauthError(400, 'invalid_grant', 'redirect_uri mismatch');
        try { logActivity("NT MCP: Token exchange FAILED from IP " . (_oauthGetClientIp()) . ": redirect_uri mismatch"); } catch (\Throwable $e) {}
        return;
    }

    // PKCE S256 verification
    $computedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    if (!hash_equals($codeRow->code_challenge, $computedChallenge)) {
        oauthError(400, 'invalid_grant', 'PKCE code_verifier verification failed');
        try { logActivity("NT MCP: Token exchange FAILED from IP " . (_oauthGetClientIp()) . ": PKCE verification failed"); } catch (\Throwable $e) {}
        return;
    }

    // Issue access token
    $accessToken = bin2hex(random_bytes(32));
    $tokenHash   = hash('sha256', $accessToken);
    $expiresIn   = 86400; // 24 hours

    // SECURITY FIX (F7 -- audit): Wrap token insert in try/catch.  The auth
    // code is already consumed above; if the insert fails, the client would
    // receive a token that does not exist in the database — every subsequent
    // MCP request would return 401.
    try {
        Capsule::table('mod_nt_mcp_oauth_tokens')->insert([
            'token_hash'  => $tokenHash,
            'client_id'   => $codeRow->client_id,
            'expires_at'  => time() + $expiresIn,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $dbEx) {
        error_log('NT MCP: Failed to insert OAuth token: ' . $dbEx->getMessage());
        oauthError(500, 'server_error', 'Failed to persist access token');
        return;
    }

    // SECURITY FIX (L-03 -- LOW): Audit logging for token issuance
    try { logActivity("NT MCP: OAuth token issued for client '{$codeRow->client_id}' from IP " . (_oauthGetClientIp())); } catch (\Throwable $e) {}

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

// ===========================================================================
// Helpers
// ===========================================================================

/**
 * SECURITY FIX (M-03 -- MEDIUM): Rate limit authorization endpoint.
 *
 * Enforces a maximum of 20 requests per minute per IP address using
 * WHMCS TransientData (DB-backed) with a file-based fallback.
 * Prevents abuse of the authorization flow.
 */
function enforceAuthorizeRateLimit(): void
{
    $maxRequests    = 20;
    $windowSeconds  = 60; // 1 minute
    $clientIp       = _oauthGetClientIp();
    $safeIp         = preg_replace('/[^a-f0-9.:]/', '_', $clientIp);
    $cacheKey       = 'nt_mcp_auth_rl_' . $safeIp;

    // Try WHMCS TransientData first (DB-backed, shared across workers)
    try {
        if (class_exists('\WHMCS\TransientData')) {
            $data = \WHMCS\TransientData::getInstance()->retrieve($cacheKey);
            if ($data === false || $data === null || $data === '') {
                \WHMCS\TransientData::getInstance()->store($cacheKey, json_encode([
                    'count' => 1,
                    'window_start' => time(),
                ]), $windowSeconds);
                return;
            }

            $state = json_decode($data, true);
            if (!is_array($state) || (time() - ($state['window_start'] ?? 0)) > $windowSeconds) {
                \WHMCS\TransientData::getInstance()->store($cacheKey, json_encode([
                    'count' => 1,
                    'window_start' => time(),
                ]), $windowSeconds);
                return;
            }

            $state['count'] = ($state['count'] ?? 0) + 1;
            if ($state['count'] > $maxRequests) {
                http_response_code(429);
                header('Retry-After: ' . $windowSeconds);
                header('Content-Type: application/json');
                echo json_encode([
                    'error'             => 'rate_limit_exceeded',
                    'error_description' => 'Too many authorization requests. Maximum ' . $maxRequests . ' per minute.',
                ], JSON_UNESCAPED_SLASHES);
                exit;
            }

            \WHMCS\TransientData::getInstance()->store(
                $cacheKey,
                json_encode($state),
                max(1, $windowSeconds - (time() - $state['window_start']))
            );
            return;
        }
    } catch (\Throwable $e) {
        // Fall through to file-based limiter
    }

    // Fallback: file-based rate limiter
    $rateDir = __DIR__ . '/data/rate';
    if (!is_dir($rateDir)) {
        @mkdir($rateDir, 0700, true);
    }
    $rateFile = $rateDir . '/auth_' . $safeIp . '.json';

    $state = ['count' => 0, 'window_start' => time()];
    if (file_exists($rateFile)) {
        $raw = @file_get_contents($rateFile);
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded) && (time() - ($decoded['window_start'] ?? 0)) <= $windowSeconds) {
            $state = $decoded;
        }
    }

    $state['count']++;
    if ($state['count'] > $maxRequests) {
        http_response_code(429);
        header('Retry-After: ' . $windowSeconds);
        header('Content-Type: application/json');
        echo json_encode([
            'error'             => 'rate_limit_exceeded',
            'error_description' => 'Too many authorization requests. Maximum ' . $maxRequests . ' per minute.',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    @file_put_contents($rateFile, json_encode($state), LOCK_EX);
}

/**
 * SECURITY FIX (H-01 -- HIGH): Rate limit token endpoint.
 *
 * Enforces a maximum of 30 requests per minute per IP address using
 * WHMCS TransientData (DB-backed) with a file-based fallback.
 * Prevents brute-force and replay attacks on /token POST requests.
 */
function enforceTokenRateLimit(): void
{
    $maxRequests    = 30;
    $windowSeconds  = 60; // 1 minute
    $clientIp       = _oauthGetClientIp();
    $safeIp         = preg_replace('/[^a-f0-9.:]/', '_', $clientIp);
    $cacheKey       = 'nt_mcp_tok_rl_' . $safeIp;

    // Try WHMCS TransientData first (DB-backed, shared across workers)
    try {
        if (class_exists('\WHMCS\TransientData')) {
            $data = \WHMCS\TransientData::getInstance()->retrieve($cacheKey);
            if ($data === false || $data === null || $data === '') {
                \WHMCS\TransientData::getInstance()->store($cacheKey, json_encode([
                    'count' => 1,
                    'window_start' => time(),
                ]), $windowSeconds);
                return;
            }

            $state = json_decode($data, true);
            if (!is_array($state) || (time() - ($state['window_start'] ?? 0)) > $windowSeconds) {
                \WHMCS\TransientData::getInstance()->store($cacheKey, json_encode([
                    'count' => 1,
                    'window_start' => time(),
                ]), $windowSeconds);
                return;
            }

            $state['count'] = ($state['count'] ?? 0) + 1;
            if ($state['count'] > $maxRequests) {
                http_response_code(429);
                header('Retry-After: ' . $windowSeconds);
                header('Content-Type: application/json');
                echo json_encode([
                    'error'             => 'rate_limit_exceeded',
                    'error_description' => 'Too many token requests. Maximum ' . $maxRequests . ' per minute.',
                ], JSON_UNESCAPED_SLASHES);
                exit;
            }

            \WHMCS\TransientData::getInstance()->store(
                $cacheKey,
                json_encode($state),
                max(1, $windowSeconds - (time() - $state['window_start']))
            );
            return;
        }
    } catch (\Throwable $e) {
        // Fall through to file-based limiter
    }

    // Fallback: file-based rate limiter
    $rateDir = __DIR__ . '/data/rate';
    if (!is_dir($rateDir)) {
        @mkdir($rateDir, 0700, true);
    }
    $rateFile = $rateDir . '/tok_' . $safeIp . '.json';

    $state = ['count' => 0, 'window_start' => time()];
    if (file_exists($rateFile)) {
        $raw = @file_get_contents($rateFile);
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded) && (time() - ($decoded['window_start'] ?? 0)) <= $windowSeconds) {
            $state = $decoded;
        }
    }

    $state['count']++;
    if ($state['count'] > $maxRequests) {
        http_response_code(429);
        header('Retry-After: ' . $windowSeconds);
        header('Content-Type: application/json');
        echo json_encode([
            'error'             => 'rate_limit_exceeded',
            'error_description' => 'Too many token requests. Maximum ' . $maxRequests . ' per minute.',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    @file_put_contents($rateFile, json_encode($state), LOCK_EX);
}

/**
 * SECURITY FIX (V-02 -- HIGH CVSS 7.5): Rate limit client registration.
 *
 * Enforces a maximum of 20 registrations per hour per IP address using
 * WHMCS TransientData (DB-backed) with a file-based fallback.
 * Prevents resource exhaustion from unauthenticated /register POST requests.
 */
function enforceRegistrationRateLimit(): void
{
    $maxRegistrations = 20;
    $windowSeconds    = 3600; // 1 hour
    $clientIp         = _oauthGetClientIp();
    $safeIp           = preg_replace('/[^a-f0-9.:]/', '_', $clientIp);
    $cacheKey          = 'nt_mcp_reg_rl_' . $safeIp;

    // Try WHMCS TransientData first (DB-backed, shared across workers)
    try {
        if (class_exists('\WHMCS\TransientData')) {
            $data = \WHMCS\TransientData::getInstance()->retrieve($cacheKey);
            if ($data === false || $data === null || $data === '') {
                \WHMCS\TransientData::getInstance()->store($cacheKey, json_encode([
                    'count' => 1,
                    'window_start' => time(),
                ]), $windowSeconds);
                return;
            }

            $state = json_decode($data, true);
            if (!is_array($state) || (time() - ($state['window_start'] ?? 0)) > $windowSeconds) {
                \WHMCS\TransientData::getInstance()->store($cacheKey, json_encode([
                    'count' => 1,
                    'window_start' => time(),
                ]), $windowSeconds);
                return;
            }

            $state['count'] = ($state['count'] ?? 0) + 1;
            if ($state['count'] > $maxRegistrations) {
                http_response_code(429);
                header('Retry-After: ' . $windowSeconds);
                header('Content-Type: application/json');
                echo json_encode([
                    'error'             => 'rate_limit_exceeded',
                    'error_description' => 'Too many client registrations. Maximum ' . $maxRegistrations . ' per hour.',
                ], JSON_UNESCAPED_SLASHES);
                exit;
            }

            \WHMCS\TransientData::getInstance()->store(
                $cacheKey,
                json_encode($state),
                max(1, $windowSeconds - (time() - $state['window_start']))
            );
            return;
        }
    } catch (\Throwable $e) {
        // Fall through to file-based limiter
    }

    // Fallback: file-based rate limiter
    // SECURITY FIX (H-05 -- HIGH): Use app-local directory instead of shared /tmp
    $rateDir = __DIR__ . '/data/rate';
    if (!is_dir($rateDir)) {
        @mkdir($rateDir, 0700, true);
    }
    $rateFile = $rateDir . '/reg_' . $safeIp . '.json';

    $state = ['count' => 0, 'window_start' => time()];
    if (file_exists($rateFile)) {
        $raw = @file_get_contents($rateFile);
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded) && (time() - ($decoded['window_start'] ?? 0)) <= $windowSeconds) {
            $state = $decoded;
        }
    }

    $state['count']++;
    if ($state['count'] > $maxRegistrations) {
        http_response_code(429);
        header('Retry-After: ' . $windowSeconds);
        header('Content-Type: application/json');
        echo json_encode([
            'error'             => 'rate_limit_exceeded',
            'error_description' => 'Too many client registrations. Maximum ' . $maxRegistrations . ' per hour.',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    @file_put_contents($rateFile, json_encode($state), LOCK_EX);
}

function oauthError(int $httpCode, string $error, string $description): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode([
        'error'             => $error,
        'error_description' => $description,
    ], JSON_UNESCAPED_SLASHES);
}
