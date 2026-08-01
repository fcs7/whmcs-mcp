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
$ntMcpBodySnapshot = '';
$ntMcpStatusSnapshot = 500;
$ntMcpHeadersSnapshot = ['Content-Type' => 'application/json'];
ob_start(
    static function (string $buffer, int $phase) use (
        &$ntMcpResponseState,
        &$ntMcpCapturedOutput,
        &$ntMcpBodySnapshot,
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

        // Depois do snapshot, todo byte tardio é ignorado. O body final não é
        // mais derivado do conteúdo recebido pelo callback.
        return in_array($ntMcpResponseState, ['sealed', 'success'], true)
            ? $ntMcpBodySnapshot
            : $ntMcpFailureJson;
    },
    0,
    PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE
);
$ntMcpOwnedBufferLevel = ob_get_level();

$ntMcpAllowedHeaders = [
    'access-control-allow-origin' => 'Access-Control-Allow-Origin',
    'access-control-allow-methods' => 'Access-Control-Allow-Methods',
    'access-control-allow-headers' => 'Access-Control-Allow-Headers',
    'access-control-expose-headers' => 'Access-Control-Expose-Headers',
    'vary' => 'Vary',
    'www-authenticate' => 'WWW-Authenticate',
    'retry-after' => 'Retry-After',
    'content-type' => 'Content-Type',
    'mcp-session-id' => 'Mcp-Session-Id',
    'allow' => 'Allow',
    'x-content-type-options' => 'X-Content-Type-Options',
    'x-frame-options' => 'X-Frame-Options',
    'content-security-policy' => 'Content-Security-Policy',
    'cache-control' => 'Cache-Control',
    'strict-transport-security' => 'Strict-Transport-Security',
    'x-permitted-cross-domain-policies' => 'X-Permitted-Cross-Domain-Policies',
    'referrer-policy' => 'Referrer-Policy',
];

$ntMcpSelectHeaders = static function (array $extra = []) use ($ntMcpAllowedHeaders): array {
    $selected = [];
    $candidates = headers_list();
    foreach ($extra as $name => $value) {
        $candidates[] = $name . ': ' . $value;
    }

    foreach ($candidates as $line) {
        if (!is_string($line) || !str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $lower = strtolower(trim($name));
        $value = trim($value);
        if (!isset($ntMcpAllowedHeaders[$lower]) || preg_match('/[\r\n]/', $value) === 1) {
            continue;
        }
        if ($lower === 'retry-after' && preg_match('/^[1-9]\d{0,4}\z/', $value) !== 1) {
            continue;
        }
        if ($lower === 'mcp-session-id' && preg_match('/^[A-Za-z0-9._\-]{8,128}\z/', $value) !== 1) {
            continue;
        }
        if ($lower === 'content-type' && preg_match('/^application\/json(?:;\s*charset=UTF-8)?\z/i', $value) !== 1) {
            continue;
        }

        $selected[$ntMcpAllowedHeaders[$lower]] = $value;
    }

    return $selected;
};

$ntMcpApplyFinalHeaders = static function () use (
    &$ntMcpResponseState,
    &$ntMcpStatusSnapshot,
    &$ntMcpHeadersSnapshot,
    &$ntMcpBodySnapshot,
    $ntMcpFailureJson,
): void {
    $completed = in_array($ntMcpResponseState, ['sealed', 'success'], true);
    $status = $completed ? $ntMcpStatusSnapshot : 500;
    $body = $completed ? $ntMcpBodySnapshot : $ntMcpFailureJson;
    $headers = $completed ? $ntMcpHeadersSnapshot : ['Content-Type' => 'application/json'];

    header_remove();
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value, true);
    }
    header('Content-Length: ' . strlen($body), true);
};

header_register_callback($ntMcpApplyFinalHeaders);

$ntMcpMarkFailure = static function () use (
    &$ntMcpResponseState,
    &$ntMcpStatusSnapshot,
    &$ntMcpHeadersSnapshot,
    &$ntMcpBodySnapshot,
    $ntMcpFailureJson,
    $ntMcpApplyFinalHeaders,
): void {
    $ntMcpResponseState = 'failure';
    $ntMcpStatusSnapshot = 500;
    $ntMcpHeadersSnapshot = ['Content-Type' => 'application/json'];
    $ntMcpBodySnapshot = $ntMcpFailureJson;

    if (!headers_sent()) {
        header_register_callback($ntMcpApplyFinalHeaders);
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

$ntMcpCommitSnapshot = static function (
    string $state,
    int $status,
    string $body,
    array $extraHeaders = [],
) use (
    &$ntMcpResponseState,
    &$ntMcpStatusSnapshot,
    &$ntMcpHeadersSnapshot,
    &$ntMcpBodySnapshot,
    $ntMcpDiscardChildBuffers,
    $ntMcpSelectHeaders,
    $ntMcpApplyFinalHeaders,
    $ntMcpOwnedBufferLevel,
): bool {
    if (!in_array($state, ['sealed', 'success'], true)
        || !$ntMcpDiscardChildBuffers()
        || ob_get_level() !== $ntMcpOwnedBufferLevel) {
        return false;
    }

    $headers = $ntMcpSelectHeaders($extraHeaders);
    if (!@ob_clean()) {
        return false;
    }

    $ntMcpStatusSnapshot = ($status >= 100 && $status <= 599) ? $status : 500;
    $ntMcpHeadersSnapshot = $headers;
    $ntMcpBodySnapshot = $body;
    $ntMcpResponseState = $state;
    header_register_callback($ntMcpApplyFinalHeaders);

    return true;
};

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '0');

// Rede mínima para falha do PRÓPRIO autoload: não pode depender de nenhuma
// classe do addon, porque elas ainda não existem.
register_shutdown_function(static function () use (&$ntMcpResponseState, $ntMcpMarkFailure): void {
    if (in_array($ntMcpResponseState, ['sealed', 'success'], true)) {
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
if (!$ntMcpDiscardChildBuffers() || !@ob_clean() || headers_sent()) {
    $ntMcpMarkFailure();
    return;
}
header_remove();
http_response_code(200);
header_register_callback($ntMcpApplyFinalHeaders);
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
$terminalResponse = TlsEnforcer::enforce();

// CORS headers for browser-based MCP clients (Claude.ai Custom Connectors)
if ($terminalResponse === null) {
    $corsDecision = CorsHandler::handle(['MCP-Session-Id'], 'POST, OPTIONS');
    $corsDecision->emitHeaders();
    $terminalResponse = $corsDecision->terminalResponse();
}

// SECURITY CONTROL (9.4): Optional IP allowlist
if ($terminalResponse === null) {
    $terminalResponse = IpAllowlist::enforce();
}

// SECURITY FIX (F9 -- HIGH): Security response headers
if ($terminalResponse === null) {
    SecurityHeaders::emit();
}

// SECURITY FIX (F7 -- HIGH): IP-based rate limiting (60 req/min)
if ($terminalResponse === null) {
    $terminalResponse = (new RateLimiter('nt_mcp_rl_', 60, 60))->enforce();
}

if ($terminalResponse !== null) {
    if (!$ntMcpCommitSnapshot(
        'sealed',
        $terminalResponse->status(),
        $terminalResponse->body(),
        $terminalResponse->headers(),
    )) {
        $ntMcpMarkFailure();
    }
    return;
}

// 3. Autenticar ANTES de qualquer coisa
// SECURITY (F17): The stored value is a SHA-256 hash, not the plaintext token.
// Also accepts OAuth-issued tokens from mod_nt_mcp_oauth_tokens.
$storedHash = \WHMCS\Config\Setting::getValue('nt_mcp_bearer_token') ?? '';
$auth = new BearerAuth($storedHash);

$_authenticatedAdmin = $auth->authenticate();
if ($_authenticatedAdmin === null) {
    $terminalResponse = BearerAuth::deniedResponse(SystemUrl::resourceMetadataUrl());
    if (!$ntMcpCommitSnapshot(
        'sealed',
        $terminalResponse->status(),
        $terminalResponse->body(),
        $terminalResponse->headers(),
    )) {
        $ntMcpMarkFailure();
    }
    return;
}

// 4. Iniciar MCP Server com o admin vinculado ao token
NtMcp\Server::run($_authenticatedAdmin);

// O retorno normal do servidor é selado imediatamente. O buffer é limpo e o
// snapshot passa a ser a única fonte do body; shutdown/destrutor tardio não
// consegue anexar bytes ou alterar status/headers.
if (!$ntMcpDiscardChildBuffers()) {
    $ntMcpMarkFailure();
    return;
}
$currentOutput = ob_get_contents();
if ($currentOutput === false) {
    $ntMcpMarkFailure();
    return;
}
$responseBody = $ntMcpCapturedOutput . $currentOutput;
$responseStatus = http_response_code();
if (!$ntMcpCommitSnapshot('success', is_int($responseStatus) ? $responseStatus : 200, $responseBody)) {
    $ntMcpMarkFailure();
}

// O buffer raiz é não-removível e será finalizado pelo próprio PHP.
