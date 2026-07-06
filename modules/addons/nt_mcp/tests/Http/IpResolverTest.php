<?php

declare(strict_types=1);

namespace NtMcp\Tests\Http;

use NtMcp\Http\IpResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SECURITY FIX (WO-4 + WO-TP): isTrustedProxy() extraction + CIDR support,
 * resolve() XFF client-IP validation, and unification with the WHMCS native
 * trusted-proxy resolution.
 *
 * \WHMCS\Config\Setting / \App do not exist in the unit test environment, so
 * the native paths are driven through the test hooks setNativeIpResolverForTests()
 * and setConfigReaderForTests(); resetForTests() in setUp/tearDown keeps the
 * static hooks and the per-request trusted-proxy cache from leaking between
 * tests (the whole suite shares one PHP process). With no hooks configured the
 * resolver behaves exactly as before (only loopback trusted, no native IP).
 */
class IpResolverTest extends TestCase
{
    private ?array $serverBackup = null;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        IpResolver::resetForTests();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        IpResolver::resetForTests();
    }

    /** @param array<string,mixed> $map */
    private function configReturning(array $map): callable
    {
        return fn(string $key) => $map[$key] ?? null;
    }

    // --- isTrustedProxy(): loopback + defaults ---

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
        // Without any configured trusted-proxies entry, private IPs are NOT
        // implicitly trusted — only loopback is.
        $this->assertFalse(IpResolver::isTrustedProxy('10.0.0.5'));
    }

    // --- resolve(): fallback algorithm (no native path) ---

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
        // client in X-Forwarded-For is never a legitimate public client.
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

    // --- resolve(): WHMCS native path (WO-TP) ---

    public function test_resolve_prefers_native_ip_behind_trusted_proxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; // loopback => trusted
        IpResolver::setNativeIpResolverForTests(fn() => '203.0.113.9');

        $this->assertSame('203.0.113.9', IpResolver::resolve());
    }

    public function test_resolve_accepts_native_ip_equal_to_remote_addr(): void
    {
        // No proxy in the path: native == REMOTE_ADDR, accepted even though
        // REMOTE_ADDR is not a trusted proxy.
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        IpResolver::setNativeIpResolverForTests(fn() => '8.8.8.8');

        $this->assertSame('8.8.8.8', IpResolver::resolve());
    }

    public function test_resolve_rejects_native_ip_on_untrusted_direct_connection(): void
    {
        // COHERENCE GUARD: a directly-connected (untrusted) client that manages
        // to influence the native value into a different IP must NOT be honored.
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8'; // not a trusted proxy
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        IpResolver::setNativeIpResolverForTests(fn() => '10.0.0.5');

        $this->assertSame('8.8.8.8', IpResolver::resolve());
    }

    public function test_resolve_accepts_private_native_ip_behind_trusted_proxy(): void
    {
        // Behind a trusted proxy the native path may legitimately return a
        // private IP (own-infra clients via a load balancer).
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        IpResolver::setNativeIpResolverForTests(fn() => '10.1.2.3');

        $this->assertSame('10.1.2.3', IpResolver::resolve());
    }

    #[DataProvider('invalidNativeValueProvider')]
    public function test_resolve_falls_back_on_invalid_native_values(mixed $nativeValue): void
    {
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        IpResolver::setNativeIpResolverForTests(fn() => $nativeValue);

        $this->assertSame('8.8.8.8', IpResolver::resolve());
    }

    public static function invalidNativeValueProvider(): array
    {
        return [
            'empty string' => [''],
            'zero ip'      => ['0.0.0.0'],
            'not an ip'    => ['not-an-ip'],
            'non-string'   => [42],
            'null'         => [null],
        ];
    }

    public function test_resolve_falls_back_when_native_resolver_throws(): void
    {
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        IpResolver::setNativeIpResolverForTests(function () {
            throw new \RuntimeException('boom');
        });

        $this->assertSame('8.8.8.8', IpResolver::resolve());
    }

    // --- isTrustedProxy(): native + addon list parsing (WO-TP) ---

    public function test_native_trusted_proxies_json_strings_cidr(): void
    {
        // Finally exercises CIDR matching against a *configured* entry — the
        // gap documented in the WO-4 round.
        IpResolver::setConfigReaderForTests($this->configReturning([
            'TrustedProxyIps' => '["10.0.0.0/8"]',
        ]));

        $this->assertTrue(IpResolver::isTrustedProxy('10.0.0.5'));
        $this->assertFalse(IpResolver::isTrustedProxy('11.0.0.5'));
    }

    public function test_native_trusted_proxies_json_objects(): void
    {
        IpResolver::setConfigReaderForTests($this->configReturning([
            'TrustedProxyIps' => '[{"ip":"172.16.0.0/12","note":"lb"}]',
        ]));

        $this->assertTrue(IpResolver::isTrustedProxy('172.16.1.1'));
    }

    public function test_native_trusted_proxies_already_array(): void
    {
        IpResolver::setConfigReaderForTests($this->configReturning([
            'TrustedProxyIps' => ['192.0.2.10'],
        ]));

        $this->assertTrue(IpResolver::isTrustedProxy('192.0.2.10'));
    }

    public function test_native_trusted_proxies_php_serialized(): void
    {
        IpResolver::setConfigReaderForTests($this->configReturning([
            'TrustedProxyIps' => serialize(['192.0.2.0/24']),
        ]));

        $this->assertTrue(IpResolver::isTrustedProxy('192.0.2.7'));
    }

    public function test_native_trusted_proxies_plain_csv(): void
    {
        IpResolver::setConfigReaderForTests($this->configReturning([
            'TrustedProxyIps' => "198.51.100.7, 203.0.113.0/24",
        ]));

        $this->assertTrue(IpResolver::isTrustedProxy('198.51.100.7'));
        $this->assertTrue(IpResolver::isTrustedProxy('203.0.113.9'));
    }

    public function test_native_trusted_proxies_garbage_yields_loopback_only(): void
    {
        IpResolver::setConfigReaderForTests($this->configReturning([
            'TrustedProxyIps' => 'not-json-not-ip',
        ]));

        $this->assertFalse(IpResolver::isTrustedProxy('10.0.0.5'));
        $this->assertTrue(IpResolver::isTrustedProxy('127.0.0.1'));
    }

    public function test_invalid_entries_dropped_valid_kept(): void
    {
        IpResolver::setConfigReaderForTests($this->configReturning([
            'TrustedProxyIps' => '["999.999.1.1","10.0.0.0/8"]',
        ]));

        // Invalid entry is dropped; the valid CIDR still matches.
        $this->assertTrue(IpResolver::isTrustedProxy('10.0.0.5'));
        $this->assertFalse(IpResolver::isTrustedProxy('999.999.1.1'));
    }

    public function test_addon_and_native_lists_merge(): void
    {
        IpResolver::setConfigReaderForTests($this->configReturning([
            'nt_mcp_trusted_proxies' => '198.51.100.7',
            'TrustedProxyIps'        => '["10.0.0.0/8"]',
        ]));

        $this->assertTrue(IpResolver::isTrustedProxy('198.51.100.7')); // from addon list
        $this->assertTrue(IpResolver::isTrustedProxy('10.0.0.5'));     // from native list
    }

    public function test_trusted_proxies_cached_per_request(): void
    {
        $calls = ['nt_mcp_trusted_proxies' => 0, 'TrustedProxyIps' => 0];
        IpResolver::setConfigReaderForTests(function (string $key) use (&$calls) {
            $calls[$key] = ($calls[$key] ?? 0) + 1;
            return $key === 'TrustedProxyIps' ? '203.0.113.0/24' : null;
        });

        IpResolver::isTrustedProxy('203.0.113.5');
        IpResolver::isTrustedProxy('203.0.113.9');

        // The merged list is parsed once per request; each key read exactly once.
        $this->assertSame(1, $calls['TrustedProxyIps']);
        $this->assertSame(1, $calls['nt_mcp_trusted_proxies']);
    }
}
