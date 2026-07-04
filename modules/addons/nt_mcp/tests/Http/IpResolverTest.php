<?php

declare(strict_types=1);

namespace NtMcp\Tests\Http;

use NtMcp\Http\IpResolver;
use PHPUnit\Framework\TestCase;

/**
 * SECURITY FIX (WO-4): isTrustedProxy() extraction + CIDR support + resolve()
 * XFF client-IP validation (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE).
 *
 * NOTE: \WHMCS\Config\Setting does not exist in the unit test environment
 * (see tests/bootstrap.php), so nt_mcp_trusted_proxies always resolves to ''
 * here and only the hardcoded loopback entries (127.0.0.1, ::1) are trusted.
 * CIDR matching against a *configured* trusted-proxies entry (e.g. the
 * "10.0.0.0/8 matches 10.0.0.5" scenario from the spec) is therefore not
 * directly exercisable in this suite; the CIDR arithmetic itself is fully
 * covered by IpResolverCidrTest, and isTrustedProxy()'s use of it is a thin,
 * already-covered pass-through (str_contains($entry, '/') ? isInCidr(...) :
 * exact match) that this suite documents rather than duplicates.
 */
class IpResolverTest extends TestCase
{
    private ?array $serverBackup = null;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    // --- isTrustedProxy() ---

    public function test_isTrustedProxy_loopback_v4_is_true(): void
    {
        $this->assertTrue(IpResolver::isTrustedProxy('127.0.0.1'));
    }

    public function test_isTrustedProxy_loopback_v6_is_true(): void
    {
        $this->assertTrue(IpResolver::isTrustedProxy('::1'));
    }

    public function test_isTrustedProxy_arbitrary_public_ip_is_false(): void
    {
        $this->assertFalse(IpResolver::isTrustedProxy('8.8.8.8'));
    }

    public function test_isTrustedProxy_private_ip_without_config_is_false(): void
    {
        // Without a configured nt_mcp_trusted_proxies entry, private IPs are
        // NOT implicitly trusted — only loopback is.
        $this->assertFalse(IpResolver::isTrustedProxy('10.0.0.5'));
    }

    // --- resolve() ---

    public function test_resolve_returns_remote_addr_when_not_a_trusted_proxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        $this->assertSame('8.8.8.8', IpResolver::resolve());
    }

    public function test_resolve_returns_remote_addr_when_no_xff(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $this->assertSame('127.0.0.1', IpResolver::resolve());
    }

    public function test_resolve_returns_empty_remote_addr_as_zero_ip(): void
    {
        unset($_SERVER['REMOTE_ADDR']);

        $this->assertSame('0.0.0.0', IpResolver::resolve());
    }

    public function test_resolve_picks_rightmost_untrusted_public_ip_from_xff(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 127.0.0.1';

        $this->assertSame('1.2.3.4', IpResolver::resolve());
    }

    public function test_resolve_ignores_private_xff_client_ip_and_falls_back_to_remote_addr(): void
    {
        // SECURITY FIX (WO-4): a private/reserved-range IP claimed as the
        // client in X-Forwarded-For is never a legitimate public client —
        // FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE rejects it.
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.0.1';

        $this->assertSame('127.0.0.1', IpResolver::resolve());
    }

    public function test_resolve_ignores_malformed_xff_entry(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';

        $this->assertSame('127.0.0.1', IpResolver::resolve());
    }
}
