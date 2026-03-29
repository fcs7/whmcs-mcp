<?php

declare(strict_types=1);

namespace NtMcp\Http;

/**
 * SECURITY CONTROL (9.4): Optional IP allowlist.
 * If nt_mcp_allowed_ips is configured in WHMCS settings, only
 * requests from those IPs are accepted. Empty = allow all.
 * Supports comma-separated IPs and CIDR notation.
 */
final class IpAllowlist
{
    public static function enforce(): void
    {
        $allowedIpsRaw = '';
        try {
            $allowedIpsRaw = \WHMCS\Config\Setting::getValue('nt_mcp_allowed_ips') ?? '';
        } catch (\Throwable $e) {
            // Setting doesn't exist yet — allow all
            return;
        }

        $allowedIpsRaw = trim($allowedIpsRaw);
        if ($allowedIpsRaw === '') {
            return; // No allowlist configured — allow all (backwards compatible)
        }

        $clientIp = IpResolver::resolve();
        if ($clientIp === '' || $clientIp === '0.0.0.0') {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Forbidden: unable to determine client IP.']);
            exit;
        }

        $allowedEntries = array_filter(array_map('trim', explode(',', $allowedIpsRaw)));

        foreach ($allowedEntries as $entry) {
            // Exact IP match
            if ($entry === $clientIp) {
                return;
            }
            // CIDR match
            if (strpos($entry, '/') !== false && IpResolver::isInCidr($clientIp, $entry)) {
                return;
            }
        }

        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden: IP address not in allowlist.']);
        exit;
    }
}
