<?php

declare(strict_types=1);

namespace NtMcp\OAuth;

final class RedirectUri
{
    public static function isAllowed(mixed $uri): bool
    {
        if (!is_string($uri) || $uri === '' || strlen($uri) > 2048
            || preg_match('/[\x00-\x20\x7f<>"]/', $uri) || str_contains($uri, '\\')) {
            return false;
        }
        $parts = parse_url($uri);
        if ($parts === false || empty($parts['host']) || !isset($parts['scheme'])
            || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if ($parts['scheme'] === 'https') {
            return true;
        }
        if ($parts['scheme'] === 'http') {
            return in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true);
        }
        return in_array($parts['scheme'], ['cursor', 'vscode'], true)
            && ($parts['path'] ?? '') === '/oauth/callback';
    }
}
