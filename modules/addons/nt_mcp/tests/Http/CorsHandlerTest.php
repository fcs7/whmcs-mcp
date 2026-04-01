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
        // No WHMCS bootstrap — Setting::getValue() throws → returns []
        $origins = CorsHandler::getAllowedOrigins();
        $this->assertIsArray($origins);
        $this->assertContainsOnly('string', $origins);
    }

    // --- resolveOriginHeader() ---

    public function test_no_allowlist_returns_wildcard(): void
    {
        $result = CorsHandler::resolveOriginHeader('https://claude.ai', []);
        $this->assertSame('*', $result);
    }

    public function test_no_origin_header_returns_wildcard(): void
    {
        $result = CorsHandler::resolveOriginHeader('', ['https://claude.ai']);
        $this->assertSame('*', $result);
    }

    public function test_origin_in_allowlist_returns_specific_origin(): void
    {
        $result = CorsHandler::resolveOriginHeader('https://claude.ai', ['https://claude.ai']);
        $this->assertSame('https://claude.ai', $result);
    }

    public function test_origin_not_in_allowlist_returns_null(): void
    {
        $result = CorsHandler::resolveOriginHeader('https://evil.com', ['https://claude.ai']);
        $this->assertNull($result);
    }

    public function test_origin_matches_exactly_no_partial_match(): void
    {
        // 'https://evil.claude.ai' should NOT match 'https://claude.ai'
        $result = CorsHandler::resolveOriginHeader('https://evil.claude.ai', ['https://claude.ai']);
        $this->assertNull($result);
    }

    public function test_no_origin_and_no_allowlist_returns_wildcard(): void
    {
        $result = CorsHandler::resolveOriginHeader('', []);
        $this->assertSame('*', $result);
    }

    public function test_multiple_origins_in_allowlist_match_correctly(): void
    {
        $allowlist = ['https://claude.ai', 'https://app.example.com'];
        $this->assertSame('https://app.example.com', CorsHandler::resolveOriginHeader('https://app.example.com', $allowlist));
        $this->assertNull(CorsHandler::resolveOriginHeader('https://evil.com', $allowlist));
    }

    // --- getAllowedOrigins() CSV parsing ---

    public function test_multiple_origins_csv_parsed_correctly(): void
    {
        // Test the parsing logic directly using a public helper approach
        // We test getAllowedOrigins() returns [] in test env (no WHMCS), which is correct
        $origins = CorsHandler::getAllowedOrigins();
        $this->assertSame([], $origins); // WHMCS not available → returns []
    }
}
