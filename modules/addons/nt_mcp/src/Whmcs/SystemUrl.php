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
        return self::resolve() . '/admin/addonmodules.php?module=nt_mcp&authorize=' . urlencode($requestId);
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
    }
}
