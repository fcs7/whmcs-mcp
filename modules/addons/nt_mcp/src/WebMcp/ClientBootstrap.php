<?php

declare(strict_types=1);

namespace NtMcp\WebMcp;

use NtMcp\Whmcs\ConfigFlag;
use NtMcp\Whmcs\Diagnostics;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Config\Setting;

/** Optional browser bootstrap. No MCP server, credentials, catalog or tools. */
final class ClientBootstrap
{
    public const ENABLED_SETTING = 'nt_mcp_webmcp_enabled';

    /** @param array<string, mixed> $vars WHMCS ClientAreaFooterOutput variables. */
    public static function footer(array $vars): string
    {
        if (($vars['template'] ?? null) !== 'ntweb-2026-theme'
            || ($vars['nt_landing_slug'] ?? null) !== 'hospedagem'
            || ($vars['servedOverSsl'] ?? null) !== true
            || !empty($vars['loggedin'])
        ) {
            return '';
        }

        // WEB_ROOT is a path, never an absolute or protocol-relative script URL.
        $webRoot = $vars['WEB_ROOT'] ?? null;
        if (!is_string($webRoot) || preg_match('~\A(?:/[a-zA-Z0-9_-]+)*/?\z~', $webRoot) !== 1) {
            return '';
        }

        try {
            if (ConfigFlag::parse(Setting::getValue(self::ENABLED_SETTING)) !== ConfigFlag::On) {
                return '';
            }

            // WHMCS 8 users may be authenticated without a selected client account.
            $currentUser = new CurrentUser();
            if ($currentUser->isAuthenticatedUser()
                || $currentUser->isAuthenticatedAdmin()
                || $currentUser->client() !== null
            ) {
                return '';
            }
        } catch (\Throwable $error) {
            // This optional integration must never break the public page. Unknown
            // configuration/authentication state leaves it off; no SDK bootstrap.
            try {
                // Reuse the sole log writer only on failure. No autoloader, SDK,
                // message access, fingerprint computation or configuration retry.
                $diagnostics = __DIR__ . '/../Whmcs/Diagnostics.php';
                if (is_readable($diagnostics)) {
                    require_once $diagnostics;
                    Diagnostics::logWithFingerprint(
                        null,
                        Diagnostics::CATEGORY_RUNTIME,
                        'webmcp_footer_unavailable',
                        null,
                        get_debug_type($error)
                    );
                }
            } catch (\Throwable) {
                // Diagnostic failure must not turn this optional base into a 500.
            }
            return '';
        }

        $src = rtrim($webRoot, '/') . '/modules/addons/nt_mcp/assets/webmcp.js?v=20260905-1';

        return '<script defer src="'
            . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" data-nt-mcp-webmcp></script>';
    }
}
