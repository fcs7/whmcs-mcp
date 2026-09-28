<?php

declare(strict_types=1);
namespace NtMcp\Tests\OAuth;

use NtMcp\OAuth\RedirectUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedirectUriTest extends TestCase
{
    public static function cases(): array
    {
        return [
            ['https://example.org/callback?state=abc', true],
            ['http://localhost:3210/callback', true], ['http://127.0.0.1:3210/', true],
            ['http://[::1]:3210/', true], ['cursor://app/oauth/callback', true],
            ['vscode://app/oauth/callback', true], ['https://example.org/%3Cscript%3E', true],
            ['https://example.org/</script><script>alert(1)</script>', false],
            ['https://example.org/"', false], ["https://example.org/\n", false],
            ['https://example.org\\@evil.example/', false], ['https://user:pass@example.org/', false],
            ['https://example.org/#fragment', false], ['javascript:alert(1)', false],
            ['http://example.org/callback', false], ['cursor://app/other', false],
            ['https://example.org/' . str_repeat('a', 2048), false], [[], false], [null, false],
        ];
    }
    #[DataProvider('cases')]
    public function testRedirectValidation(mixed $uri, bool $allowed): void
    {
        self::assertSame($allowed, RedirectUri::isAllowed($uri));
    }
}
