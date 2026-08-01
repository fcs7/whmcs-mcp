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
$ntMcpFailureJson = '{"jsonrpc":"2.0","error":{"code":-32603,"message":"Internal server error."},"id":null}';
$ntMcpResponseState = 'pending';
$ntMcpCapturedOutput = '';
ob_start(
    static function (string $buffer, int $phase) use (
        &$ntMcpResponseState,
        &$ntMcpCapturedOutput,
        $ntMcpFailureJson,
    ): string {
        if (($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
            $ntMcpCapturedOutput = '';
        } else {
            $ntMcpCapturedOutput .= $buffer;
        }

        // Nada atravessa antes da finalização. Isto impede que flushes do
        // bootstrap enviem headers/corpo prematuramente e permite descartar o
        // ruído antes de entrar na aplicação.
        if (($phase & PHP_OUTPUT_HANDLER_FINAL) === 0) {
            return '';
        }

        // O buffer raiz é o árbitro final. Buffers filhos já foram finalizados
        // neste ponto, portanto nenhum callback hostil consegue transformar o
        // JSON fixo de falha que nasce aqui.
        return $ntMcpResponseState === 'success'
            ? $ntMcpCapturedOutput
            : $ntMcpFailureJson;
    },
    0,
    PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE
);
$ntMcpOwnedBufferLevel = ob_get_level();

$ntMcpMarkFailure = static function () use (&$ntMcpResponseState): void {
    $ntMcpResponseState = 'failure';

    if (!headers_sent()) {
        header_remove();
        http_response_code(500);
        header('Content-Type: application/json');
    }
};

// Remove somente buffers filhos. Cada iteração precisa provar que o nível
// caiu; um buffer não removível encerra a tentativa e falha fechado, sem loop.
$ntMcpDiscardChildBuffers = static function () use ($ntMcpOwnedBufferLevel): bool {
    while (ob_get_level() > $ntMcpOwnedBufferLevel) {
        $before = ob_get_level();
        $ended = @ob_end_clean();
        $after = ob_get_level();

        if (!$ended || $after >= $before) {
            // Se for limpável, reduzimos a contaminação antes do shutdown; ele
            // continua não confiável porque não pôde ser removido.
            @ob_clean();
            return false;
        }
    }

    return ob_get_level() === $ntMcpOwnedBufferLevel;
};

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '0');

// Rede mínima para falha do PRÓPRIO autoload: não pode depender de nenhuma
// classe do addon, porque elas ainda não existem.
register_shutdown_function(static function () use (&$ntMcpResponseState, $ntMcpMarkFailure): void {
    if ($ntMcpResponseState === 'success') {
        return;
    }

    // Inclui fatal, Throwable, warning e também exit/die (error_get_last null).
    // Não ecoa: o corpo nasce exclusivamente no callback FINAL do buffer raiz.
    $ntMcpMarkFailure();
});

// 1. Autoload do Composer PRIMEIRO — garante que psr/log v3 e demais
//    PSR packages do addon sejam registrados antes do WHMCS carregar
//    suas versões v1, evitando fatal "declaration compatibility" errors.
require_once __DIR__ . '/vendor/autoload.php';

// 2. Handler estruturado assim que as classes do addon existem — e ANTES do
//    `init.php`, para cobrir também o bootstrap do WHMCS/DB.
\NtMcp\Whmcs\Diagnostics::installExceptionHandler('mcp_endpoint', $ntMcpMarkFailure);

// 3. Inicializar WHMCS (3 niveis: addons/nt_mcp -> modules -> whmcs root)
define('CLIENTAREA', true);
try {
    require_once __DIR__ . '/../../../init.php';
} catch (\Throwable $e) {
    \NtMcp\Whmcs\Diagnostics::respondToThrowable($e, 'mcp_endpoint', $ntMcpMarkFailure);
}

// O bootstrap pode imprimir incidentalmente e pode substituir os handlers.
// Descartamos apenas o que veio antes da aplicação. Se algum buffer filho não
// puder ser removido, não existe caminho de sucesso exclusivo: falhamos antes
// de autenticar ou executar qualquer ferramenta.
if (!$ntMcpDiscardChildBuffers() || !@ob_clean()) {
    $ntMcpMarkFailure();
    exit;
}
\NtMcp\Whmcs\Diagnostics::installExceptionHandler('mcp_endpoint', $ntMcpMarkFailure);

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

// Somente o retorno normal do servidor autoriza a resposta MCP capturada. Um
// exit/die em qualquer ponto anterior deixa a sentinela pendente e o shutdown
// converte status, headers e corpo para a falha fixa.
if (!$ntMcpDiscardChildBuffers()) {
    $ntMcpMarkFailure();
    exit;
}
$ntMcpResponseState = 'success';

// O buffer raiz é não-removível e será finalizado pelo próprio PHP.
