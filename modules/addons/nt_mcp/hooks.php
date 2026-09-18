<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('Direct access denied.');
}

// This file can also be loaded by cron/API bootstraps using another PHP handler.
// Keep its syntax compatible with PHP 7; the optional runtime requires PHP 8.1.
if (PHP_VERSION_ID < 80100) {
    return;
}

add_hook('ClientAreaFooterOutput', 1, static function (array $vars): string {
    // Delay class loading until client output. A missing file during an upload
    // must disable this integration without aborting the WHMCS bootstrap.
    if (!is_readable(__DIR__ . '/src/Whmcs/ConfigFlag.php')
        || !is_readable(__DIR__ . '/src/WebMcp/ClientBootstrap.php')
    ) {
        return '';
    }

    // The landing entry point sets this before initPage(). Custom ClientAreaPage
    // Smarty variables may not yet exist when WHMCS builds footeroutput.
    $vars['nt_landing_slug'] = $GLOBALS['_nt_landing_slug'] ?? null;

    try {
        // Never import vendor/autoload.php, nt_mcp.php, mcp.php or the MCP SDK.
        require_once __DIR__ . '/src/Whmcs/ConfigFlag.php';
        require_once __DIR__ . '/src/WebMcp/ClientBootstrap.php';

        return \NtMcp\WebMcp\ClientBootstrap::footer($vars);
    } catch (\Throwable $ignored) {
        // An incomplete upload may also make the diagnostic boundary unavailable.
        return '';
    }
});
