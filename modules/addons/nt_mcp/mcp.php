<?php
/**
 * NT MCP — Endpoint HTTP publico
 * URL: https://seu-whmcs.com/modules/addons/nt_mcp/mcp.php
 *
 * SEGURANCA: Bearer Token validado ANTES de qualquer processamento.
 */

// 1. Inicializar WHMCS (3 niveis: addons/nt_mcp -> modules -> whmcs root)
define('CLIENTAREA', true);
require_once __DIR__ . '/../../../init.php';

// 2. Autoload do Composer (depois do WHMCS para evitar conflitos)
require_once __DIR__ . '/vendor/autoload.php';

// 3. Autenticar ANTES de qualquer coisa
use NtMcp\Auth\BearerAuth;

$storedToken = \WHMCS\Config\Setting::getValue('nt_mcp_bearer_token') ?? '';
$auth = new BearerAuth($storedToken);

if (!$auth->isValid()) {
    BearerAuth::denyAndExit();
}

// 4. Iniciar MCP Server
NtMcp\Server::run();
