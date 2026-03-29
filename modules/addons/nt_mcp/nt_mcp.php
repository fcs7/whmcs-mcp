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
use NtMcp\Security\CsrfProtection;

/**
 * Metadados e configuracoes do addon
 */
function nt_mcp_config(): array
{
    return [
        'name'        => 'NT MCP Server',
        'description' => 'Model Context Protocol server para integrar Claude Code ao WHMCS.',
        'version'     => '1.0.0',
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
