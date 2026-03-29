<?php
/**
 * Authorization Server Metadata (RFC 8414) — discovery fallback handler.
 *
 * mcp-remote tries: origin/path/.well-known/openid-configuration
 * This file serves the metadata at that path.
 */
define('CLIENTAREA', true);
require_once __DIR__ . '/../../../../../init.php';

$systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL') ?? '', '/');
if ($systemUrl === '') {
    try { $systemUrl = rtrim(\App::getSystemURL(), '/'); } catch (\Throwable $e) { $systemUrl = 'https://localhost'; }
}
// Upgrade http→https when request arrived over TLS
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
);
if ($isHttps && str_starts_with($systemUrl, 'http://')) {
    $systemUrl = 'https://' . substr($systemUrl, 7);
}

$oauthUrl  = $systemUrl . '/modules/addons/nt_mcp/oauth.php';
$issuerUrl = $systemUrl;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
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
