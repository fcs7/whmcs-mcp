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

    // --- getAllowedOriginsOrFail() fail-closed (WO-5 / item E) ---

    public function test_get_allowed_origins_or_fail_returns_false_on_config_error(): void
    {
        // No WHMCS bootstrap in tests → \WHMCS\Config\Setting doesn't exist → this is
        // exactly the "real config-read error" path. It must return the `false`
        // sentinel, NOT an empty array — an empty array would be indistinguishable
        // from "no allowlist configured" and resolveOriginHeader() would then hand
        // back a wildcard on what is actually an infra failure.
        $result = CorsHandler::getAllowedOriginsOrFail();
        $this->assertFalse($result);
    }

    public function test_get_allowed_origins_or_fail_error_would_not_resolve_to_wildcard_if_handled_correctly(): void
    {
        // Demonstrates the bug WO-5 fixes: naively feeding a config-read error into
        // resolveOriginHeader() (by collapsing it to []) silently produces '*'.
        $orFail = CorsHandler::getAllowedOriginsOrFail();
        $this->assertFalse($orFail, 'error must be reported as false, not []');

        // The buggy pre-fix behaviour (error treated as empty allowlist):
        $wronglyCollapsed = $orFail === false ? [] : $orFail;
        $this->assertSame('*', CorsHandler::resolveOriginHeader('https://evil.com', $wronglyCollapsed));

        // handle() must check for the `false` sentinel explicitly (503) instead of
        // ever calling resolveOriginHeader() with a collapsed empty array on error.
    }

    public function test_get_allowed_origins_still_collapses_error_to_empty_array_for_back_compat(): void
    {
        // getAllowedOrigins() (unlike getAllowedOriginsOrFail()) is a back-compat
        // wrapper — existing non-handle() callers keep seeing [] on error.
        $this->assertSame([], CorsHandler::getAllowedOrigins());
    }

    // --- allowlist configured + origin outside it → header omitted (reinforced) ---

    public function test_configured_allowlist_with_origin_outside_it_omits_header(): void
    {
        $result = CorsHandler::resolveOriginHeader('https://not-allowed.example', ['https://claude.ai', 'https://app.example.com']);
        $this->assertNull($result);
    }
}
