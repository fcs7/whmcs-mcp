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
        echo json_encode(['error' => 'not_found', 'error_description' => 'Unknown OAuth endpoint: ' . $pathInfo]);
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

    $clientId   = bin2hex(random_bytes(16));
    $clientName = $input['client_name'] ?? 'MCP Client';

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

/** Authorization GET — render approval page */
function handleAuthorizeGet(): void
{
    $clientId      = $_GET['client_id'] ?? '';
    $redirectUri   = $_GET['redirect_uri'] ?? '';
    $codeChallenge = $_GET['code_challenge'] ?? '';
    $state         = $_GET['state'] ?? '';
    $responseType  = $_GET['response_type'] ?? '';
    $scope         = $_GET['scope'] ?? '';

    if ($responseType !== 'code') {
        oauthError(400, 'unsupported_response_type', 'Only response_type=code is supported');
        return;
    }
    if ($codeChallenge === '') {
        oauthError(400, 'invalid_request', 'code_challenge is required (PKCE)');
        return;
    }

    $client = Capsule::table('mod_nt_mcp_oauth_clients')
        ->where('client_id', $clientId)
        ->first();

    if (!$client) {
        oauthError(400, 'invalid_client', 'Unknown client_id');
        return;
    }

    $registeredUris = json_decode($client->redirect_uris, true) ?: [];
    if (!in_array($redirectUri, $registeredUris, true)) {
        oauthError(400, 'invalid_request', 'redirect_uri does not match registered URIs');
        return;
    }

    // Render approval page
    $clientName = htmlspecialchars($client->client_name ?? 'MCP Client', ENT_QUOTES, 'UTF-8');
    $csrfToken  = bin2hex(random_bytes(16));

    // Store CSRF in a short-lived code entry (reuse table, mark as csrf)
    Capsule::table('mod_nt_mcp_oauth_codes')->insert([
        'code'           => 'csrf_' . $csrfToken,
        'client_id'      => $clientId,
        'code_challenge'  => $codeChallenge,
        'redirect_uri'   => $redirectUri,
        'state'          => $state,
        'expires_at'     => time() + 600, // 10 minutes
        'used'           => false,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Autorizar MCP Client</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 16px rgba(0,0,0,.1); padding: 2rem; max-width: 420px; width: 100%; }
        .card h1 { font-size: 1.3rem; color: #1a1a2e; margin-bottom: .5rem; }
        .card p { color: #555; margin-bottom: 1.5rem; line-height: 1.5; }
        .client-name { font-weight: 600; color: #2563eb; }
        .buttons { display: flex; gap: .75rem; }
        .btn { flex: 1; padding: .75rem 1rem; border: none; border-radius: 8px; font-size: 1rem; cursor: pointer; font-weight: 500; }
        .btn-approve { background: #2563eb; color: #fff; }
        .btn-approve:hover { background: #1d4ed8; }
        .btn-deny { background: #e5e7eb; color: #374151; }
        .btn-deny:hover { background: #d1d5db; }
        .icon { font-size: 2.5rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">&#128274;</div>
        <h1>Autorizar Acesso MCP</h1>
        <p><span class="client-name">{$clientName}</span> quer acessar o <strong>WHMCS MCP Server</strong>.</p>
        <p>Isso permitira que o cliente execute ferramentas de gerenciamento via MCP.</p>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="{$csrfToken}">
            <input type="hidden" name="approve" value="1">
            <div class="buttons">
                <button type="submit" name="approve" value="0" class="btn btn-deny">Negar</button>
                <button type="submit" name="approve" value="1" class="btn btn-approve">Aprovar</button>
            </div>
        </form>
    </div>
</body>
</html>
HTML;
}

/** Authorization POST — process approval, redirect with code */
function handleAuthorizePost(): void
{
    $csrfToken = $_POST['csrf_token'] ?? '';
    $approve   = $_POST['approve'] ?? '0';

    $csrfRow = Capsule::table('mod_nt_mcp_oauth_codes')
        ->where('code', 'csrf_' . $csrfToken)
        ->where('used', false)
        ->where('expires_at', '>', time())
        ->first();

    if (!$csrfRow) {
        oauthError(400, 'invalid_request', 'Invalid or expired session. Please try again.');
        return;
    }

    // Mark CSRF as used
    Capsule::table('mod_nt_mcp_oauth_codes')
        ->where('id', $csrfRow->id)
        ->update(['used' => true]);

    $redirectUri = $csrfRow->redirect_uri;
    $state       = $csrfRow->state;

    if ($approve !== '1') {
        $params = http_build_query(array_filter([
            'error'             => 'access_denied',
            'error_description' => 'User denied the authorization request',
            'state'             => $state,
        ]));
        header('Location: ' . $redirectUri . '?' . $params);
        exit;
    }

    // Generate authorization code
    $authCode = bin2hex(random_bytes(32));

    Capsule::table('mod_nt_mcp_oauth_codes')->insert([
        'code'           => $authCode,
        'client_id'      => $csrfRow->client_id,
        'code_challenge'  => $csrfRow->code_challenge,
        'redirect_uri'   => $redirectUri,
        'state'          => $state,
        'expires_at'     => time() + 300, // 5 minutes
        'used'           => false,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);

    $params = http_build_query(array_filter([
        'code'  => $authCode,
        'state' => $state,
    ]));
    header('Location: ' . $redirectUri . '?' . $params);
    exit;
}

/** Token exchange — authorization_code grant with PKCE */
function handleToken(string $oauthUrl): void
{
    header('Content-Type: application/json');

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
        return;
    }

    if ($code === '' || $codeVerifier === '') {
        oauthError(400, 'invalid_request', 'code and code_verifier are required');
        return;
    }

    $codeRow = Capsule::table('mod_nt_mcp_oauth_codes')
        ->where('code', $code)
        ->where('used', false)
        ->where('expires_at', '>', time())
        ->first();

    if (!$codeRow) {
        oauthError(400, 'invalid_grant', 'Invalid, expired, or already used authorization code');
        return;
    }

    // Mark code as used immediately
    Capsule::table('mod_nt_mcp_oauth_codes')
        ->where('id', $codeRow->id)
        ->update(['used' => true]);

    // Validate client_id
    if ($clientId !== '' && $clientId !== $codeRow->client_id) {
        oauthError(400, 'invalid_grant', 'client_id mismatch');
        return;
    }

    // Validate redirect_uri
    if ($redirectUri !== '' && $redirectUri !== $codeRow->redirect_uri) {
        oauthError(400, 'invalid_grant', 'redirect_uri mismatch');
        return;
    }

    // PKCE S256 verification
    $computedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    if (!hash_equals($codeRow->code_challenge, $computedChallenge)) {
        oauthError(400, 'invalid_grant', 'PKCE code_verifier verification failed');
        return;
    }

    // Issue access token
    $accessToken = bin2hex(random_bytes(32));
    $tokenHash   = hash('sha256', $accessToken);
    $expiresIn   = 86400; // 24 hours

    Capsule::table('mod_nt_mcp_oauth_tokens')->insert([
        'token_hash'  => $tokenHash,
        'client_id'   => $codeRow->client_id,
        'expires_at'  => time() + $expiresIn,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    // Cleanup expired tokens
    Capsule::table('mod_nt_mcp_oauth_tokens')
        ->where('expires_at', '<', time())
        ->delete();

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

function oauthError(int $httpCode, string $error, string $description): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode([
        'error'             => $error,
        'error_description' => $description,
    ], JSON_UNESCAPED_SLASHES);
}
