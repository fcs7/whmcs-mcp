<?php
/**
 * NT MCP — Endpoint HTTP publico
 * URL: https://seu-whmcs.com/modules/addons/nt_mcp/mcp.php
 *
 * SEGURANCA: Bearer Token validado ANTES de qualquer processamento.
 * Rate limiting, security headers, and audit logging applied at this layer.
 */

// 1. Inicializar WHMCS (3 niveis: addons/nt_mcp -> modules -> whmcs root)
define('CLIENTAREA', true);
require_once __DIR__ . '/../../../init.php';

// 2. Autoload do Composer (depois do WHMCS para evitar conflitos)
require_once __DIR__ . '/vendor/autoload.php';

// ---------------------------------------------------------------
// SECURITY CONTROL (9.2 -- F13): TLS enforcement.
// Reject plain HTTP requests to prevent credential exposure in transit.
// The Bearer token and all MCP payloads MUST travel over TLS.
//
// Override: Set environment variable NT_MCP_ALLOW_HTTP=1 for local dev.
// ---------------------------------------------------------------
(function () {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    );

    $allowHttp = (
        getenv('NT_MCP_ALLOW_HTTP') === '1'
        || (isset($_ENV['NT_MCP_ALLOW_HTTP']) && $_ENV['NT_MCP_ALLOW_HTTP'] === '1')
    );

    if (!$isHttps && !$allowHttp) {
        http_response_code(421); // Misdirected Request
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'TLS required. Plain HTTP requests are rejected for security.',
        ]);
        exit;
    }
})();

// ---------------------------------------------------------------
// SECURITY CONTROL (9.4): Optional IP allowlist.
// If nt_mcp_allowed_ips is configured in WHMCS settings, only
// requests from those IPs are accepted.  Empty = allow all.
// Supports comma-separated IPs and CIDR notation.
// ---------------------------------------------------------------
(function () {
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

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($clientIp === '') {
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
        if (strpos($entry, '/') !== false && _ntMcpIpInCidr($clientIp, $entry)) {
            return;
        }
    }

    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden: IP address not in allowlist.']);
    exit;
})();

/**
 * Check if an IP address falls within a CIDR range.
 * Supports both IPv4 and IPv6.
 */
function _ntMcpIpInCidr(string $ip, string $cidr): bool
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

// ---------------------------------------------------------------
// SECURITY FIX (F9 -- HIGH): Emit security response headers BEFORE
// any output.  These headers apply defence-in-depth against XSS,
// click-jacking, MIME sniffing, and cache-based data leakage.
// ---------------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'");
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Permitted-Cross-Domain-Policies: none');
header('Referrer-Policy: no-referrer');

// ---------------------------------------------------------------
// SECURITY FIX (F7 -- HIGH): IP-based rate limiting.
// Uses WHMCS transient cache (tblconfiguration with expiring keys)
// to enforce a maximum of 60 requests per minute per IP address.
// Falls back to file-based locking when transients are unavailable.
// ---------------------------------------------------------------
(function () {
    $maxRequests = 60;
    $windowSeconds = 60;
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Normalise the IP to a safe cache key (strip colons for IPv6)
    $safeIp = preg_replace('/[^a-f0-9.]/', '_', $clientIp);
    $cacheKey = 'nt_mcp_rl_' . $safeIp;

    // Try WHMCS transient cache first (DB-backed, shared across workers)
    try {
        if (class_exists('\WHMCS\TransientData')) {
            $data = \WHMCS\TransientData::getInstance()->retrieve($cacheKey);
            if ($data === false || $data === null || $data === '') {
                // First request in this window
                \WHMCS\TransientData::getInstance()->store($cacheKey, json_encode([
                    'count' => 1,
                    'window_start' => time(),
                ]), $windowSeconds);
                return; // within limits
            }

            $state = json_decode($data, true);
            if (!is_array($state) || (time() - ($state['window_start'] ?? 0)) > $windowSeconds) {
                // Window expired — reset
                \WHMCS\TransientData::getInstance()->store($cacheKey, json_encode([
                    'count' => 1,
                    'window_start' => time(),
                ]), $windowSeconds);
                return;
            }

            $state['count'] = ($state['count'] ?? 0) + 1;
            if ($state['count'] > $maxRequests) {
                http_response_code(429);
                header('Retry-After: ' . $windowSeconds);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Rate limit exceeded. Try again later.']);
                exit;
            }

            \WHMCS\TransientData::getInstance()->store(
                $cacheKey,
                json_encode($state),
                $windowSeconds - (time() - $state['window_start'])
            );
            return;
        }
    } catch (\Throwable $e) {
        // Fall through to file-based limiter
    }

    // Fallback: file-based rate limiter
    $rateDir = sys_get_temp_dir() . '/nt_mcp_rate';
    if (!is_dir($rateDir)) {
        @mkdir($rateDir, 0700, true);
    }
    $rateFile = $rateDir . '/' . $safeIp . '.json';

    $state = ['count' => 0, 'window_start' => time()];
    if (file_exists($rateFile)) {
        $raw = @file_get_contents($rateFile);
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded) && (time() - ($decoded['window_start'] ?? 0)) <= $windowSeconds) {
            $state = $decoded;
        }
    }

    $state['count']++;
    if ($state['count'] > $maxRequests) {
        http_response_code(429);
        header('Retry-After: ' . $windowSeconds);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Rate limit exceeded. Try again later.']);
        exit;
    }

    @file_put_contents($rateFile, json_encode($state), LOCK_EX);
})();

// 3. Autenticar ANTES de qualquer coisa
use NtMcp\Auth\BearerAuth;

// SECURITY (F17): The stored value is now a SHA-256 hash, not the plaintext
// token.  BearerAuth::isValid() hashes the presented Bearer value before
// comparing, so the plaintext token never needs to be stored.
$storedHash = \WHMCS\Config\Setting::getValue('nt_mcp_bearer_token') ?? '';
$auth = new BearerAuth($storedHash);

if (!$auth->isValid()) {
    BearerAuth::denyAndExit();
}

// 4. Iniciar MCP Server
NtMcp\Server::run();
