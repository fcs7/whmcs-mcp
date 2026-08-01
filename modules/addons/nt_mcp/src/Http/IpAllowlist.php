<?php

declare(strict_types=1);

namespace NtMcp\Http;

use NtMcp\Whmcs\Diagnostics;

/**
 * SECURITY CONTROL (9.4): Optional IP allowlist.
 * If nt_mcp_allowed_ips is configured in WHMCS settings, only
 * requests from those IPs are accepted. Empty = allow all.
 * Supports comma-separated IPs and CIDR notation.
 */
final class IpAllowlist
{
    public static function enforce(): ?TerminalResponse
    {
        $allowedIpsRaw = '';
        try {
            $allowedIpsRaw = \WHMCS\Config\Setting::getValue('nt_mcp_allowed_ips') ?? '';
        } catch (\Throwable $e) {
            // Config read failed (DB error etc.) — fail closed: deny rather than silently allow.
            // Note: getValue() returns null for non-existent settings, not throws.
            Diagnostics::report(Diagnostics::CATEGORY_CONFIG_READ, 'nt_mcp_allowed_ips', $e);
            return TerminalResponse::serviceUnavailable();
        }

        $allowedIpsRaw = trim($allowedIpsRaw);
        if ($allowedIpsRaw === '') {
            return null; // No allowlist configured — allow all (backwards compatible)
        }

        $clientIp = IpResolver::resolve();
        if ($clientIp === '' || $clientIp === '0.0.0.0') {
            return TerminalResponse::clientIpUnavailable();
        }

        $allowedEntries = array_filter(array_map('trim', explode(',', $allowedIpsRaw)));

        foreach ($allowedEntries as $entry) {
            // Exact IP match
            if ($entry === $clientIp) {
                return null;
            }
            // CIDR match
            if (strpos($entry, '/') !== false && IpResolver::isInCidr($clientIp, $entry)) {
                return null;
            }
        }

        return TerminalResponse::ipForbidden();
    }
}
