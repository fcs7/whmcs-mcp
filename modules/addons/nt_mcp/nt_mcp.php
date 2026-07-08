<?php
/**
 * NT MCP — WHMCS Addon Module
 * Instalar em: modules/addons/nt_mcp/
 * Ativar em: Setup > Addon Modules > NT MCP
 */

if (!defined('WHMCS')) die('Direct access denied.');

require_once __DIR__ . '/vendor/autoload.php';

use NtMcp\Admin\AdminController;
use NtMcp\Admin\OAuthApprovalController;
use NtMcp\OAuth\OAuthMigration;
use NtMcp\Security\CsrfProtection;

/**
 * Metadados e configuracoes do addon
 */
function nt_mcp_config(): array
{
    return [
        'name'        => 'NT MCP Server',
        'description' => 'Model Context Protocol server para integrar Claude Code ao WHMCS.',
        'version'     => '1.1.0',
        'author'      => 'NT Web',
        'language'    => 'english',
        'fields'      => [], // Configuracoes gerenciadas na tela _output
    ];
}

/**
 * Executado na ativacao — gera Bearer Token inicial
 *
 * SECURITY (F17): Only the SHA-256 hash of the token is persisted.
 * The plaintext is shown once in the activation success message; after
 * that it cannot be recovered.
 */
function nt_mcp_activate(): array
{
    // FASE 4b (6A): guarda de ordem de autoload. Se vendor/autoload.php não
    // carregou antes do init.php do WHMCS, as classes da lib php-mcp/server
    // (psr/log v3) não resolvem e o runtime falha silenciosamente. Detecta na
    // ativação, onde o erro é visível ao operador.
    if (!class_exists(\PhpMcp\Server\Server::class)) {
        return [
            'status'      => 'error',
            'description' => 'NT MCP: autoloader nao inicializado corretamente '
                . '(vendor/autoload.php deve carregar ANTES de init.php). Ativacao abortada.',
        ];
    }

    // FASE 4c (9.3): o servidor grava lock global + cache em data/. Se o
    // diretorio nao for gravavel, o addon fica inoperante em runtime (HTTP 500
    // sem contexto). Valida agora, na ativacao, em vez de por request.
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0700, true);
    }
    if (!is_dir($dataDir) || !is_writable($dataDir)) {
        return [
            'status'      => 'error',
            'description' => 'NT MCP: diretorio data/ nao gravavel (' . $dataDir . '). '
                . 'Ajuste as permissoes e reative.',
        ];
    }

    $token = bin2hex(random_bytes(32)); // 64 chars hex
    $hash  = hash('sha256', $token);
    \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token', $hash);

    // SECURITY (F10): Seed the admin-user config with a safe default.
    if (!trim(\WHMCS\Config\Setting::getValue('nt_mcp_admin_user') ?? '')) {
        \WHMCS\Config\Setting::setValue('nt_mcp_admin_user', 'admin');
    }

    return [
        'status'      => 'success',
        'description' => 'NT MCP ativado. Bearer Token (copie agora, nao sera exibido novamente): ' . $token,
    ];
}

/**
 * Executado na desativacao
 */
function nt_mcp_deactivate(): array
{
    \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token', '');
    return ['status' => 'success', 'description' => 'NT MCP desativado.'];
}

/**
 * Executado pelo WHMCS quando a versao do addon muda — roda migracoes de schema.
 */
function nt_mcp_upgrade(array $vars): array
{
    $version = $vars['version'] ?? 'unknown';

    // FASE 2/4c (9.4): invalida o cache de elementos (tool registry). O cache é
    // persistido SEM TTL (nunca expira), então uma versão nova com tools
    // alteradas precisa forçar novo discovery — senão o servidor continuaria
    // servindo o registro antigo. Deletar o arquivo também zera o estado de
    // sessão (compartilha o mesmo arquivo); aceitável no upgrade — clientes
    // reinicializam e o próximo request re-descobre e regrava.
    $registryCache = __DIR__ . '/data/cache/mcp_state.json';
    if (is_file($registryCache)) {
        @unlink($registryCache);
    }

    if (!OAuthMigration::ensureTables()) {
        logActivity("NT MCP: FALHA na migracao de schema para versao {$version} — verifique o error log do PHP");
        return ['status' => 'error', 'description' => 'NT MCP: falha na migracao de schema. Verifique o log de erros do PHP.'];
    }
    logActivity("NT MCP: schema atualizado para versao {$version}");
    return ['status' => 'success', 'description' => 'NT MCP schema atualizado.'];
}

// -----------------------------------------------------------------------
// CSRF helpers (F6) — delegated to NtMcp\Security\CsrfProtection
// -----------------------------------------------------------------------
function nt_mcp_csrf_token(): string { return CsrfProtection::token(); }
function nt_mcp_csrf_verify(string $submitted): bool { return CsrfProtection::verify($submitted); }

/**
 * Tela administrativa do addon.
 * Delegates to AdminController (dashboard) or OAuthApprovalController (OAuth approval).
 */
function nt_mcp_output(array $vars): void
{
    if (isset($_GET['authorize']) && $_GET['authorize'] !== '') {
        (new OAuthApprovalController())->handle($vars);
        return;
    }

    (new AdminController())->handle($vars);
}
