<?php

declare(strict_types=1);

namespace NtMcp\Http;

/**
 * SECURITY FIX (M-01): Resolve the real client IP behind reverse proxies.
 *
 * When REMOTE_ADDR is a loopback address or matches a configured trusted
 * proxy, the rightmost untrusted IP from X-Forwarded-For is used instead.
 * This prevents rate-limit bypass and ensures IP allowlists work correctly
 * behind Plesk/nginx reverse proxies.
 */
final class IpResolver
{
    public static function resolve(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remoteAddr === '') {
            return '0.0.0.0';
        }

        // Check if REMOTE_ADDR is a trusted proxy (loopback or configured list)
        $trustedProxies = ['127.0.0.1', '::1'];
        try {
            $configured = \WHMCS\Config\Setting::getValue('nt_mcp_trusted_proxies') ?? '';
            if ($configured !== '') {
                $trustedProxies = array_merge(
                    $trustedProxies,
                    array_filter(array_map('trim', explode(',', $configured)))
                );
            }
        } catch (\Throwable $e) {
            // SECURITY FIX (F-05): Log config load failures
            error_log('NT MCP: Failed to load nt_mcp_trusted_proxies: ' . $e->getMessage());
        }

        if (!in_array($remoteAddr, $trustedProxies, true)) {
            return $remoteAddr;
        }

        // Use rightmost untrusted IP from X-Forwarded-For
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff === '') {
            return $remoteAddr;
        }

        $ips = array_map('trim', explode(',', $xff));
        // Walk from right to left, return first IP not in trusted list
        for ($i = count($ips) - 1; $i >= 0; $i--) {
            $ip = $ips[$i];
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $trustedProxies, true)) {
                return $ip;
            }
        }

        return $remoteAddr;
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
