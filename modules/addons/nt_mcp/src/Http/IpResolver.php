<?php

declare(strict_types=1);

namespace NtMcp\Http;

/**
 * SECURITY FIX (M-01): Resolve the real client IP behind reverse proxies.
 *
 * Unification (WO-TP): prefer the client IP that WHMCS itself resolves via its
 * native Trusted Proxies configuration (Configuration > General Settings >
 * Security tab), so the addon's IP allowlist / rate-limiter key on the same IP
 * WHMCS logs and bans. A coherence guard prevents a directly-connected attacker
 * from spoofing a forwarded header. When the native path is unavailable the
 * previous rightmost-untrusted X-Forwarded-For algorithm is used unchanged.
 *
 * isTrustedProxy() trusts loopback plus the union of the native WHMCS proxy
 * list (TrustedProxyIps) and the addon's own nt_mcp_trusted_proxies (now
 * additive/optional). Any parse failure degrades to loopback-only — never
 * fail-open.
 */
final class IpResolver
{
    /** @var callable|null test hook: fn(): ?string — the WHMCS-resolved client IP */
    private static $nativeIpResolver = null;

    /** @var callable|null test hook: fn(string $key): mixed — raw config reader */
    private static $configReader = null;

    /** @var string[]|null per-request cache of the merged trusted-proxy list */
    private static ?array $trustedProxiesCache = null;

    public static function setNativeIpResolverForTests(?callable $fn): void
    {
        self::$nativeIpResolver = $fn;
    }

    public static function setConfigReaderForTests(?callable $fn): void
    {
        self::$configReader = $fn;
        self::$trustedProxiesCache = null;
    }

    public static function resetForTests(): void
    {
        self::$nativeIpResolver = null;
        self::$configReader = null;
        self::$trustedProxiesCache = null;
    }

    public static function resolve(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        // Unification (WO-TP): prefer the IP WHMCS itself resolves — it honours
        // the native Trusted Proxy List and Proxy IP Header (Security tab).
        // COHERENCE GUARD: only accept the native value when it equals
        // REMOTE_ADDR (no proxy in the path) or REMOTE_ADDR is a trusted proxy
        // by the merged list. Otherwise a directly-connected attacker could
        // spoof a forwarded header (e.g. XFF: 10.0.0.5) to slip into the IP
        // allowlist or rotate the rate-limiter key — fall through to the
        // conservative algorithm below.
        $native = self::nativeClientIp();
        if ($native !== null && ($native === $remoteAddr || self::isTrustedProxy($remoteAddr))) {
            return $native;
        }

        if ($remoteAddr === '') {
            return '0.0.0.0';
        }

        // Check if REMOTE_ADDR is a trusted proxy (loopback or configured list)
        if (!self::isTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        // Use rightmost untrusted IP from X-Forwarded-For
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff === '') {
            return $remoteAddr;
        }

        $ips = array_map('trim', explode(',', $xff));
        // Walk from right to left, peeling off trusted proxies. The FIRST
        // untrusted hop is the real client and terminates the chain of trust —
        // everything to its left is attacker-controlled, so the scan must never
        // continue past it (SECURITY: stop at the first untrusted hop, else a
        // forged leftmost XFF value could be returned).
        for ($i = count($ips) - 1; $i >= 0; $i--) {
            $ip = $ips[$i];
            if ($ip === '') {
                continue;
            }
            // Trusted proxies (commonly private IPs) are peeled off; the private/
            // reserved-range check below is intentionally applied only to the
            // untrusted client hop, not to this trusted-proxy match.
            if (self::isTrustedProxy($ip)) {
                continue;
            }
            // First untrusted hop = the real client. Accept it only when it is a
            // valid PUBLIC IP; a private/reserved/malformed value here is spoofed
            // (a legitimate public client is never private), so stop and fall
            // back to REMOTE_ADDR instead of walking further left.
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
            break;
        }

        return $remoteAddr;
    }

    /**
     * The client IP resolved by the WHMCS core (which applies the native
     * Trusted Proxy List and Proxy IP Header), or null if unavailable/invalid
     * so resolve() falls back to its own algorithm.
     */
    private static function nativeClientIp(): ?string
    {
        try {
            if (self::$nativeIpResolver !== null) {
                $ip = (self::$nativeIpResolver)();
            } elseif (class_exists('\App') && is_callable(['\App', 'getClientIp'])) {
                $ip = \App::getClientIp();
            } else {
                return null;
            }

            if (!is_string($ip)) {
                return null;
            }
            $ip = trim($ip);
            // '0.0.0.0' would trip IpAllowlist's empty-IP 403 — treat as null.
            if ($ip === '' || $ip === '0.0.0.0' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return null;
            }
            return $ip;
        } catch (\Throwable $e) {
            error_log('NT MCP: native client IP resolution failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * SECURITY FIX (WO-4): Check whether an IP is a trusted proxy — loopback
     * (always trusted) or present in the merged proxy list (WHMCS native
     * TrustedProxyIps ∪ addon nt_mcp_trusted_proxies), which may contain exact
     * IPs or CIDR ranges (e.g. "10.0.0.0/8").
     */
    public static function isTrustedProxy(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        foreach (self::trustedProxies() as $entry) {
            if (str_contains($entry, '/')) {
                if (self::isInCidr($ip, $entry)) {
                    return true;
                }
            } elseif ($entry === $ip) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merged, per-request-cached trusted-proxy list: the addon's own
     * nt_mcp_trusted_proxies (additive/optional) ∪ the WHMCS native
     * TrustedProxyIps. isTrustedProxy() is called many times per request
     * (TlsEnforcer twice + the resolve() walk), so the parsed list is cached.
     *
     * @return string[]
     */
    private static function trustedProxies(): array
    {
        if (self::$trustedProxiesCache !== null) {
            return self::$trustedProxiesCache;
        }

        $own = self::parseProxyList(self::readSetting('nt_mcp_trusted_proxies'));
        $native = self::parseProxyList(self::readSetting('TrustedProxyIps'));

        // Observability: XFF present but no trusted proxies configured anywhere
        // is a likely unification no-op (native key named differently on this
        // WHMCS version?) — surface it instead of silently trusting nothing.
        if ($own === [] && $native === [] && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            error_log('NT MCP: X-Forwarded-For present but no trusted proxies configured '
                . '(nt_mcp_trusted_proxies empty; WHMCS TrustedProxyIps not found/empty) — only loopback trusted');
        }

        return self::$trustedProxiesCache = array_values(array_unique(array_merge($own, $native)));
    }

    /**
     * Read a WHMCS config value (via the test hook when set). Any Throwable is
     * swallowed and null returned — a config read failure must never fail-open
     * into an empty (loopback-only) trusted list without a log trail.
     */
    private static function readSetting(string $key): mixed
    {
        try {
            if (self::$configReader !== null) {
                return (self::$configReader)($key);
            }
            return \WHMCS\Config\Setting::getValue($key);
        } catch (\Throwable $e) {
            // SECURITY FIX (F-05): Log config load failures
            error_log("NT MCP: Failed to load {$key}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Normalise a proxy-list config value into a list of syntactically VALID
     * IPs/CIDRs. The native TrustedProxyIps storage format is undocumented, so
     * every plausible shape is accepted: an already-decoded array (getValue may
     * return one); a JSON array of strings or of objects ({ip, note, ...}); a
     * PHP-serialized array (allowed_classes=false, only when prefixed "a:"); or
     * plain CSV/newline text. Anything unrecognised or invalid is dropped — an
     * unparseable value never degrades into "trust everything".
     *
     * @return string[]
     */
    private static function parseProxyList(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $items = null;
        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $items = $decoded;
            } elseif (str_starts_with($raw, 'a:')) {
                $unserialized = @unserialize($raw, ['allowed_classes' => false]);
                if (is_array($unserialized)) {
                    $items = $unserialized;
                }
            }
            if ($items === null) {
                $items = preg_split('/[,\r\n]+/', $raw) ?: [];
            }
        } else {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $item = $item['ip'] ?? '';
            }
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '' && self::isValidProxyEntry($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** An entry must be a valid IP or a valid CIDR — never an arbitrary string. */
    private static function isValidProxyEntry(string $entry): bool
    {
        if (str_contains($entry, '/')) {
            $parts = explode('/', $entry, 2);
            if (count($parts) !== 2) {
                return false;
            }
            [$subnet, $bits] = $parts;
            return $bits !== '' && ctype_digit($bits)
                && filter_var($subnet, FILTER_VALIDATE_IP) !== false;
        }
        return filter_var($entry, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Check if an IP address falls within a CIDR range.
     * Supports both IPv4 and IPv6.
     */
    public static function isInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$subnet, $bits] = $parts;
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        // Both must be the same address family (same byte length)
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $totalBits = strlen($ipBin) * 8; // 32 for IPv4, 128 for IPv6
        if ($bits < 0 || $bits > $totalBits) {
            return false;
        }

        // Build the bitmask
        $mask = str_repeat("\xff", (int)($bits / 8));
        if ($bits % 8 !== 0) {
            $mask .= chr(0xff << (8 - ($bits % 8)) & 0xff);
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }
}
