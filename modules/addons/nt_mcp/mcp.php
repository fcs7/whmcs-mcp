<?php
/**
 * NT MCP — Endpoint HTTP publico
 * URL: https://seu-whmcs.com/modules/addons/nt_mcp/mcp.php
 *
 * SEGURANCA: Bearer Token validado ANTES de qualquer processamento.
 * Rate limiting, security headers, and audit logging applied at this layer.
 */

// ---------------------------------------------------------------
// 0. Abrir a fronteira de output ANTES de qualquer require ou bootstrap.
//
// Isto precisa ser a primeira instrução do arquivo. Uma falha no próprio
// autoload — ou no bootstrap do WHMCS — acontece antes de existir qualquer
// classe do addon, portanto antes de existir handler estruturado. Com
// `display_errors=1` herdado do ambiente, o PHP imprimiria mensagem e stack
// trace completos na resposta HTTP, que foi exatamente o que a revisão
// reproduziu.
//
// O log automático do PHP também fica desligado neste endpoint: em um fatal de
// autoload/bootstrap ele gravaria mensagem, stack e paths crus antes que nossa
// fronteira existisse. Eventos capturados continuam indo explicitamente pelo
// `Diagnostics`, cujo `error_log()` independe desta diretiva.
// ---------------------------------------------------------------
$ntMcpReleaseOutput = false;
ob_start(
    static function (string $buffer) use (&$ntMcpReleaseOutput): string {
        return $ntMcpReleaseOutput ? $buffer : '';
    },
    0,
    PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE
);
$ntMcpOwnedBufferLevel = ob_get_level();

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '0');

// Rede mínima para falha do PRÓPRIO autoload: não pode depender de nenhuma
// classe do addon, porque elas ainda não existem.
register_shutdown_function(static function () use ($ntMcpOwnedBufferLevel, &$ntMcpReleaseOutput): void {
    $fatal = error_get_last();
    if ($fatal === null || !in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    while (ob_get_level() > $ntMcpOwnedBufferLevel) {
        if (!@ob_end_clean()) {
            break;
        }
    }
    if (ob_get_level() === $ntMcpOwnedBufferLevel) {
        @ob_clean();
    }
    $ntMcpReleaseOutput = true;

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    // Texto fixo: nada de `$fatal['message']`, que carrega path e detalhe.
    echo '{"jsonrpc":"2.0","error":{"code":-32603,"message":"Internal server error during bootstrap."},"id":null}';
});

// 1. Autoload do Composer PRIMEIRO — garante que psr/log v3 e demais
//    PSR packages do addon sejam registrados antes do WHMCS carregar
//    suas versões v1, evitando fatal "declaration compatibility" errors.
require_once __DIR__ . '/vendor/autoload.php';

// 2. Handler estruturado assim que as classes do addon existem — e ANTES do
//    `init.php`, para cobrir também o bootstrap do WHMCS/DB.
$ntMcpRelease = static function () use (&$ntMcpReleaseOutput): void {
    $ntMcpReleaseOutput = true;
};
\NtMcp\Whmcs\Diagnostics::installExceptionHandler('mcp_endpoint', $ntMcpOwnedBufferLevel, $ntMcpRelease);

// 3. Inicializar WHMCS (3 niveis: addons/nt_mcp -> modules -> whmcs root)
define('CLIENTAREA', true);
try {
    require_once __DIR__ . '/../../../init.php';
} catch (\Throwable $e) {
    \NtMcp\Whmcs\Diagnostics::respondToThrowable($e, 'mcp_endpoint', $ntMcpOwnedBufferLevel, $ntMcpRelease);
}

// O bootstrap pode imprimir incidentalmente e pode substituir os handlers.
// Descartamos apenas o que veio antes da aplicação e reinstalamos a fronteira.
while (ob_get_level() > $ntMcpOwnedBufferLevel) {
    @ob_end_clean();
}
if (ob_get_level() === $ntMcpOwnedBufferLevel) {
    ob_clean();
}
$ntMcpReleaseOutput = true;
\NtMcp\Whmcs\Diagnostics::installExceptionHandler('mcp_endpoint', $ntMcpOwnedBufferLevel, $ntMcpRelease);

use NtMcp\Auth\BearerAuth;
use NtMcp\Http\TlsEnforcer;
use NtMcp\Http\SecurityHeaders;
use NtMcp\Http\CorsHandler;
use NtMcp\Http\IpAllowlist;
use NtMcp\Security\RateLimiter;
use NtMcp\Whmcs\Diagnostics;
use NtMcp\Whmcs\SystemUrl;

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

// O buffer proprietário é não-removível e será entregue pelo PHP no fim
// normal. Em fatal, o shutdown o limpa antes de escrever a resposta fechada.
