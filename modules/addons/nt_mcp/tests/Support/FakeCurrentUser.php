<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

/** Authentication states supplied by WHMCS 8, including user without account. */
final class FakeCurrentUser
{
    public static bool $user = false;
    public static bool $admin = false;
    public static ?object $client = null;
    public static bool $throwOnRead = false;

    public static function reset(): void
    {
        self::$user = false;
        self::$admin = false;
        self::$client = null;
        self::$throwOnRead = false;
    }

    public function isAuthenticatedUser(): bool
    {
        if (self::$throwOnRead) {
            throw new \RuntimeException('Authentication unavailable');
        }

        return self::$user;
    }

    public function isAuthenticatedAdmin(): bool
    {
        return self::$admin;
    }

    public function client(): ?object
    {
        return self::$client;
    }
}
