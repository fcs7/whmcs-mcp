<?php

// Local PHP test server only. These WHMCS substitutes do not access real data.
namespace WHMCS\Config {
    final class Setting
    {
        public static function getValue(string $key): mixed
        {
            return $key === 'nt_mcp_webmcp_enabled' ? ($_GET['enabled'] ?? null) : null;
        }
    }
}

namespace WHMCS\Authentication {
    final class CurrentUser
    {
        public function isAuthenticatedUser(): bool { return ($_GET['auth'] ?? '') === 'user'; }
        public function isAuthenticatedAdmin(): bool { return ($_GET['auth'] ?? '') === 'admin'; }
        public function client(): ?object
        {
            return ($_GET['auth'] ?? '') === 'client' ? (object) ['id' => 1] : null;
        }
    }
}

namespace {
    $root = dirname(__DIR__, 3);
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path === '/modules/addons/nt_mcp/assets/webmcp.js') {
        header('Content-Type: application/javascript');
        readfile($root . '/assets/webmcp.js');
        return;
    }

    define('WHMCS', true);
    function add_hook(string $name, int $priority, callable $callback): void
    {
        $GLOBALS['smokeHooks'][$name] = $callback;
    }
    require $root . '/hooks.php';
    // Match the landing: hooks load during init.php, the marker is set later.
    if (($_GET['marker'] ?? '') !== 'missing') {
        $GLOBALS['_nt_landing_slug'] = $_GET['landing'] ?? 'hospedagem';
    }
    $vars = [
        'template' => $_GET['theme'] ?? 'ntweb-2026-theme',
        'servedOverSsl' => ($_GET['ssl'] ?? '1') === '1',
        'WEB_ROOT' => $_GET['webroot'] ?? '',
        'loggedin' => false,
        'token' => 'fixture-csrf-must-not-be-exported',
    ];
    // No custom Smarty landing variable: footeroutput can be built first.
    $footer = $GLOBALS['smokeHooks']['ClientAreaFooterOutput']($vars);
    $vendorLoaded = count(array_filter(get_included_files(), static fn($file) => str_contains($file, '/vendor/'))) > 0;
    header('Content-Type: text/html; charset=utf-8');
    header('X-Smoke-Sdk-Loaded: ' . ($vendorLoaded ? '1' : '0'));
    echo '<!doctype html><html><head><title>WebMCP local fixture</title></head><body>';
    echo '<p>Local WHMCS fixture</p>', $footer, '</body></html>';
}
