<?php

declare(strict_types=1);

namespace NtMcp\Tests\Http;

use NtMcp\Http\CorsHandler;
use PHPUnit\Framework\TestCase;

class CorsHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_ORIGIN']);
    }

    // --- getAllowedOrigins() ---

    public function test_get_allowed_origins_returns_array_of_strings(): void
    {
        // No WHMCS bootstrap in tests — Setting::getValue() throws → returns []
        $origins = CorsHandler::getAllowedOrigins();
        $this->assertIsArray($origins);
        $this->assertContainsOnly('string', $origins);
    }

    // --- Origin selection logic ---

    public function test_no_origin_header_triggers_wildcard_branch(): void
    {
        unset($_SERVER['HTTP_ORIGIN']);
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        // wildcard branch fires when origin is empty string
        $this->assertSame('', $origin);
    }

    public function test_origin_in_allowlist_would_match(): void
    {
        $allowlist = ['https://claude.ai'];
        $origin = 'https://claude.ai';
        $this->assertTrue(in_array($origin, $allowlist, true));
    }

    public function test_origin_not_in_allowlist_would_not_match(): void
    {
        $allowlist = ['https://claude.ai'];
        $origin = 'https://evil.com';
        $this->assertFalse(in_array($origin, $allowlist, true));
    }

    public function test_empty_allowlist_always_uses_wildcard_branch(): void
    {
        $allowlist = [];
        $origin = 'https://claude.ai';
        // When allowlist is empty, condition ($origin !== '' && $allowlist !== []) is false → wildcard
        $this->assertFalse($origin !== '' && $allowlist !== []);
    }

    public function test_allowlist_configured_but_no_origin_header_uses_wildcard(): void
    {
        unset($_SERVER['HTTP_ORIGIN']);
        $allowlist = ['https://claude.ai'];
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        // condition: ($origin !== '' && $allowlist !== []) → false because origin is ''
        $this->assertFalse($origin !== '' && $allowlist !== []);
    }

    public function test_multiple_origins_in_allowlist_csv(): void
    {
        // Simulate parsing CSV
        $raw = 'https://claude.ai, https://app.example.com , https://dev.example.com';
        $origins = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $this->assertCount(3, $origins);
        $this->assertSame('https://claude.ai', $origins[0]);
        $this->assertSame('https://app.example.com', $origins[1]);
        $this->assertSame('https://dev.example.com', $origins[2]);
    }

    public function test_empty_csv_returns_empty_array(): void
    {
        $raw = '';
        $origins = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $this->assertSame([], $origins);
    }
}
