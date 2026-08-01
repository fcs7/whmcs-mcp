<?php
/**
 * NT MCP — Endpoint HTTP publico
 * URL: https://seu-whmcs.com/modules/addons/nt_mcp/mcp.php
 *
 * SEGURANCA: Bearer Token validado ANTES de qualquer processamento.
 * Rate limiting, security headers, and audit logging applied at this layer.
 */

// 1. Autoload do Composer PRIMEIRO — garante que psr/log v3 e demais
//    PSR packages do addon sejam registrados antes do WHMCS carregar
//    suas versões v1, evitando fatal "declaration compatibility" errors.
require_once __DIR__ . '/vendor/autoload.php';

// 2. Inicializar WHMCS (3 niveis: addons/nt_mcp -> modules -> whmcs root)
define('CLIENTAREA', true);
require_once __DIR__ . '/../../../init.php';

use NtMcp\Auth\BearerAuth;
use NtMcp\Http\TlsEnforcer;
use NtMcp\Http\SecurityHeaders;
use NtMcp\Http\CorsHandler;
use NtMcp\Http\IpAllowlist;
use NtMcp\Security\RateLimiter;
use NtMcp\Whmcs\Diagnostics;
use NtMcp\Whmcs\SystemUrl;

// ---------------------------------------------------------------
// F2 — rede de segurança do endpoint.
//
// Tudo abaixo (TLS, CORS, allowlist, rate limit, auth, montagem do servidor)
// roda ANTES do Processor do MCP, portanto fora do tratamento de erro do
// adapter. Uma exceção aqui — falha de config, de DB, de bootstrap — chegaria
// ao handler do PHP e, com `display_errors` ligado, imprimiria a mensagem crua
// (DSN, credencial, path) direto na resposta HTTP.
//
// O catch devolve um erro estável e manda só classe + fingerprint ao log.
// ---------------------------------------------------------------
set_exception_handler(static function (\Throwable $e): void {
    $correlationId = Diagnostics::report(Diagnostics::CATEGORY_UNHANDLED, 'mcp_endpoint', $e);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    echo json_encode([
        'jsonrpc' => '2.0',
        'error' => [
            'code' => -32603,
            'message' => 'Internal server error. Correlation id: ' . $correlationId,
        ],
        'id' => null,
    ]);
    exit;
});

// SECURITY CONTROL (9.2 -- F13): TLS enforcement
TlsEnforcer::enforce();

// CORS headers for browser-based MCP clients (Claude.ai Custom Connectors)
if (CorsHandler::handle(['MCP-Session-Id'], 'POST, OPTIONS')) {
    exit;
}

// SECURITY CONTROL (9.4): Optional IP allowlist
IpAllowlist::enforce();

// SECURITY FIX (F9 -- HIGH): Security response headers
SecurityHeaders::emit();

// SECURITY FIX (F7 -- HIGH): IP-based rate limiting (60 req/min)
(new RateLimiter('nt_mcp_rl_', 60, 60))->enforce();

// 3. Autenticar ANTES de qualquer coisa
// SECURITY (F17): The stored value is a SHA-256 hash, not the plaintext token.
// Also accepts OAuth-issued tokens from mod_nt_mcp_oauth_tokens.
$storedHash = \WHMCS\Config\Setting::getValue('nt_mcp_bearer_token') ?? '';
$auth = new BearerAuth($storedHash);

$_authenticatedAdmin = $auth->authenticate();
if ($_authenticatedAdmin === null) {
    BearerAuth::denyAndExit(SystemUrl::resourceMetadataUrl());
}

// 4. Iniciar MCP Server com o admin vinculado ao token
NtMcp\Server::run($_authenticatedAdmin);
