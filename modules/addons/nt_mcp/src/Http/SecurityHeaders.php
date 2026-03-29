<?php

declare(strict_types=1);

namespace NtMcp\Http;

/**
 * SECURITY FIX (F9 -- HIGH): Security response headers.
 * Defence-in-depth against XSS, clickjacking, MIME sniffing, cache leaks.
 */
final class SecurityHeaders
{
    public static function emit(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'");
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Referrer-Policy: no-referrer');
    }
}
