<?php

declare(strict_types=1);

namespace NtMcp\Whmcs;

/**
 * SECURITY FIX (9.3 -- F11): Host header injection prevention.
 *
 * Centralizes system URL resolution from WHMCS configuration,
 * never from the Host header. Automatically upgrades http->https
 * when the current request arrived over TLS.
 */
final class SystemUrl
{
    private static ?string $cached = null;
    private static ?string $cachedAdminFolder = null;
    private static ?\Closure $adminFolderResolver = null;

    /**
     * Resolve the WHMCS system URL (e.g. "https://desenv.ntweb.com.br").
     * Uses WHMCS config, never Host header. Upgrades to HTTPS if current request is TLS.
     */
    public static function resolve(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL') ?? '', '/');
        if ($systemUrl === '') {
            try {
                $systemUrl = rtrim(\App::getSystemURL(), '/');
            } catch (\Throwable $e) {
                $systemUrl = 'https://localhost';
            }
        }

        // Upgrade http->https when request arrived over TLS
        if (self::isTls() && str_starts_with($systemUrl, 'http://')) {
            $systemUrl = 'https://' . substr($systemUrl, 7);
        }

        self::$cached = $systemUrl;
        return $systemUrl;
    }

    /**
     * Extract the hostname from the resolved system URL (e.g. "desenv.ntweb.com.br").
     * Lowercase for consistent comparison.
     */
    public static function host(): string
    {
        return strtolower((string) parse_url(self::resolve(), PHP_URL_HOST));
    }

    /**
     * Full URL to the MCP endpoint.
     */
    public static function mcpUrl(): string
    {
        return self::resolve() . '/modules/addons/nt_mcp/mcp.php';
    }

    /**
     * Full URL to the OAuth endpoint.
     */
    public static function oauthUrl(): string
    {
        return self::resolve() . '/modules/addons/nt_mcp/oauth.php';
    }

    /**
     * Addon base URL.
     */
    public static function baseUrl(): string
    {
        return self::resolve() . '/modules/addons/nt_mcp';
    }

    /**
     * Resource metadata URL (for RFC 9728 discovery).
     */
    public static function resourceMetadataUrl(): string
    {
        return self::oauthUrl() . '/resource-metadata';
    }

    /**
     * Issuer URL (origin only, no path) for RFC 8414 discovery.
     */
    public static function issuerUrl(): string
    {
        return self::resolve();
    }

    /**
     * WHMCS admin panel URL for OAuth approval redirect.
     */
    public static function adminAuthorizeUrl(string $requestId): string
    {
        return self::adminUrl('addonmodules.php?module=nt_mcp&authorize=' . urlencode($requestId));
    }

    /**
     * Build a URL under the WHMCS admin folder, resolved at runtime instead
     * of hardcoding "/admin/". Production may run a custom admin folder
     * (WHMCS "Change Admin Folder Name" feature, e.g. "gestor"), and a
     * hardcoded "/admin/" 404s there.
     */
    public static function adminUrl(string $path): string
    {
        return self::resolve() . '/' . self::adminFolder() . '/' . ltrim($path, '/');
    }

    /**
     * Resolve the WHMCS admin folder name (default "admin", but WHMCS lets
     * operators rename it). Tries, in order: \App::get_admin_folder_name(),
     * \WHMCS\Admin\AdminServiceProvider::getAdminRouteBase() (if present),
     * the $customadminpath global WHMCS defines when it includes
     * configuration.php, then falls back to "admin". Never reads
     * configuration.php directly.
     */
    public static function adminFolder(): string
    {
        if (self::$cachedAdminFolder !== null) {
            return self::$cachedAdminFolder;
        }

        if (self::$adminFolderResolver !== null) {
            $resolved = (self::$adminFolderResolver)();
            self::$cachedAdminFolder = self::sanitizeAdminFolder(is_string($resolved) ? $resolved : null);
            return self::$cachedAdminFolder;
        }

        $folder = null;

        // \App is a facade (__callStatic), so method_exists() is always false
        // for get_admin_folder_name(); is_callable() honours __callStatic.
        if (class_exists('\App') && is_callable(['\App', 'get_admin_folder_name'])) {
            try {
                $resolved = \App::get_admin_folder_name();
                $folder = is_string($resolved) ? $resolved : null;
            } catch (\Throwable $e) {
                $folder = null;
            }
        }

        if ($folder === null && class_exists('\WHMCS\Admin\AdminServiceProvider') && method_exists('\WHMCS\Admin\AdminServiceProvider', 'getAdminRouteBase')) {
            try {
                $resolved = \WHMCS\Admin\AdminServiceProvider::getAdminRouteBase();
                $folder = is_string($resolved) ? trim($resolved, '/') : null;
            } catch (\Throwable $e) {
                $folder = null;
            }
        }

        if ($folder === null && isset($GLOBALS['customadminpath']) && is_string($GLOBALS['customadminpath'])) {
            $folder = $GLOBALS['customadminpath'];
        }

        self::$cachedAdminFolder = self::sanitizeAdminFolder($folder);
        return self::$cachedAdminFolder;
    }

    private static function sanitizeAdminFolder(?string $folder): string
    {
        if ($folder === null || $folder === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $folder)) {
            return 'admin';
        }

        return $folder;
    }

    /**
     * Test-only seam: override admin folder resolution with a callable.
     * Pass null to restore the runtime resolution chain.
     */
    public static function setAdminFolderResolverForTesting(?callable $resolver): void
    {
        self::$adminFolderResolver = $resolver === null ? null : \Closure::fromCallable($resolver);
        self::$cachedAdminFolder = null;
    }

    /**
     * Check if the current request arrived over TLS.
     */
    public static function isTls(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        );
    }

    /**
     * Reset cached URL (for testing).
     */
    public static function reset(): void
    {
        self::$cached = null;
        self::$cachedAdminFolder = null;
        self::$adminFolderResolver = null;
    }
}
