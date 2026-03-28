<?php
/**
 * NT MCP — WHMCS Addon Module
 * Instalar em: modules/addons/nt_mcp/
 * Ativar em: Setup > Addon Modules > NT MCP
 */

if (!defined('WHMCS')) die('Direct access denied.');

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
 */
function nt_mcp_activate(): array
{
    $token = bin2hex(random_bytes(32)); // 64 chars hex
    \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token', $token);
    return ['status' => 'success', 'description' => 'NT MCP ativado. Acesse o modulo para ver o Bearer Token.'];
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
 * Tela administrativa do addon
 */
function nt_mcp_output(array $vars): void
{
    $token = \WHMCS\Config\Setting::getValue('nt_mcp_bearer_token') ?? '';
    $mcpUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
        . '/modules/addons/nt_mcp/mcp.php';

    // Regenerar token se solicitado
    if (isset($_POST['regenerate_token'])) {
        $token = bin2hex(random_bytes(32));
        \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token', $token);
    }

    echo <<<HTML
    <div class="panel panel-default">
        <div class="panel-heading"><h3 class="panel-title">NT MCP Server — Configuracao</h3></div>
        <div class="panel-body">
            <h4>Endpoint MCP</h4>
            <pre>{$mcpUrl}</pre>

            <h4>Bearer Token</h4>
            <pre>{$token}</pre>

            <h4>Configuracao Claude Code (~/.claude.json)</h4>
            <pre>{
  "mcpServers": {
    "whmcs-ntweb": {
      "type": "http",
      "url": "{$mcpUrl}",
      "headers": { "Authorization": "Bearer {$token}" }
    }
  }
}</pre>

            <form method="post">
                <button type="submit" name="regenerate_token" class="btn btn-warning">
                    Regenerar Token
                </button>
            </form>
        </div>
    </div>
    HTML;
}
