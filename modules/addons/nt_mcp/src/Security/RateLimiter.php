<?php

declare(strict_types=1);

namespace NtMcp\Security;

use NtMcp\Http\IpResolver;

/**
 * SECURITY FIX (F7 -- HIGH): IP-based rate limiting.
 *
 * Uses WHMCS transient cache (tblconfiguration with expiring keys)
 * to enforce a configurable maximum of requests per time window per IP.
 * Falls back to file-based locking when transients are unavailable.
 *
 * Replaces 4 near-identical rate limiting implementations from mcp.php and oauth.php.
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $cacheKeyPrefix,
        private readonly int $maxRequests,
        private readonly int $windowSeconds,
        private readonly string $filePrefix = '',
        private readonly ?string $errorDescription = null,
    ) {}

    public function enforce(): void
    {
        // SECURITY FIX (H-05): Use private data directory instead of world-writable /tmp
        $dataDir = dirname(__DIR__, 2) . '/data/rate';
        if (!is_dir($dataDir) && !@mkdir($dataDir, 0700, true)) {
            error_log('NT MCP RateLimiter: failed to create rate limit directory ' . $dataDir);
        }

        $clientIp = IpResolver::resolve();
        // Normalise the IP to a safe cache key (preserve colons for IPv6)
        $safeIp = preg_replace('/[^a-f0-9.:]/', '_', $clientIp);
        $cacheKey = $this->cacheKeyPrefix . $safeIp;

        // Try WHMCS transient cache first (DB-backed, shared across workers)
        try {
            if (class_exists('\WHMCS\TransientData')) {
                $data = \WHMCS\TransientData::getInstance()->retrieve($cacheKey);
                if ($data === false || $data === null || $data === '') {
                    // First request in this window
                    \WHMCS\TransientData::getInstance()->store($cacheKey, json_encode([
                        'count' => 1,
                        'window_start' => time(),
                    ]), $this->windowSeconds);
                    return; // within limits
                }

                $state = json_decode($data, true);
                if (!is_array($state) || (time() - ($state['window_start'] ?? 0)) > $this->windowSeconds) {
                    // Window expired -- reset
                    \WHMCS\TransientData::getInstance()->store($cacheKey, json_encode([
                        'count' => 1,
                        'window_start' => time(),
                    ]), $this->windowSeconds);
                    return;
                }

                $state['count'] = ($state['count'] ?? 0) + 1;
                if ($state['count'] > $this->maxRequests) {
                    $this->denyAndExit();
                }

                \WHMCS\TransientData::getInstance()->store(
                    $cacheKey,
                    json_encode($state),
                    max(1, $this->windowSeconds - (time() - $state['window_start']))
                );
                return;
            }
        } catch (\Throwable $e) {
            // Fall through to file-based limiter
        }

        // Fallback: file-based rate limiter — read+increment+write inside LOCK_EX to prevent TOCTOU
        $rateFile = $dataDir . '/' . $this->filePrefix . $safeIp . '.json';

        $fp = @fopen($rateFile, 'c+');
        if ($fp === false) {
            // Cannot open file; fail open to avoid blocking legitimate requests
            error_log('NT MCP RateLimiter: failed to open rate file ' . $rateFile);
            return;
        }

        flock($fp, LOCK_EX);

        $raw = stream_get_contents($fp);
        $decoded = $raw ? json_decode($raw, true) : null;

        $state = ['count' => 0, 'window_start' => time()];
        if (is_array($decoded) && (time() - ($decoded['window_start'] ?? 0)) <= $this->windowSeconds) {
            $state = $decoded;
        }

        $state['count']++;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state));

        flock($fp, LOCK_UN);
        fclose($fp);

        if ($state['count'] > $this->maxRequests) {
            $this->denyAndExit();
        }
    }

    private function denyAndExit(): never
    {
        http_response_code(429);
        header('Retry-After: ' . $this->windowSeconds);
        header('Content-Type: application/json');

        if ($this->errorDescription !== null) {
            echo json_encode([
                'error'             => 'rate_limit_exceeded',
                'error_description' => $this->errorDescription,
            ], JSON_UNESCAPED_SLASHES);
        } else {
            echo json_encode(['error' => 'Rate limit exceeded. Try again later.']);
        }

        exit;
    }
}
