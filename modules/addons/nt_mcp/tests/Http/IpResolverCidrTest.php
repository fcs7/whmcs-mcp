<?php

declare(strict_types=1);

namespace NtMcp\Tests\Http;

use NtMcp\Http\IpResolver;
use PHPUnit\Framework\TestCase;

class IpResolverCidrTest extends TestCase
{
    // --- IPv4 ---

    public function test_ipv4_exact_match_slash32(): void
    {
        $this->assertTrue(IpResolver::isInCidr('192.168.1.5', '192.168.1.5/32'));
    }

    public function test_ipv4_in_subnet_slash24(): void
    {
        $this->assertTrue(IpResolver::isInCidr('10.0.0.99', '10.0.0.0/24'));
    }

    public function test_ipv4_not_in_subnet_slash24(): void
    {
        $this->assertFalse(IpResolver::isInCidr('10.0.1.1', '10.0.0.0/24'));
    }

    public function test_ipv4_slash0_matches_all(): void
    {
        $this->assertTrue(IpResolver::isInCidr('8.8.8.8', '0.0.0.0/0'));
    }

    public function test_ipv4_boundary_last_address(): void
    {
        $this->assertTrue(IpResolver::isInCidr('192.168.1.255', '192.168.1.0/24'));
    }

    public function test_ipv4_boundary_first_address(): void
    {
        $this->assertTrue(IpResolver::isInCidr('192.168.1.0', '192.168.1.0/24'));
    }

    // --- IPv6 ---

    public function test_ipv6_exact_match_slash128(): void
    {
        $this->assertTrue(IpResolver::isInCidr('::1', '::1/128'));
    }

    public function test_ipv6_in_subnet_slash64(): void
    {
        $this->assertTrue(IpResolver::isInCidr('2001:db8::1', '2001:db8::/64'));
    }

    public function test_ipv6_not_in_subnet_slash64(): void
    {
        $this->assertFalse(IpResolver::isInCidr('2001:db9::1', '2001:db8::/64'));
    }

    // --- Edge cases ---

    public function test_cross_family_ipv4_against_ipv6_cidr_returns_false(): void
    {
        $this->assertFalse(IpResolver::isInCidr('192.168.1.1', '::1/128'));
    }

    public function test_cross_family_ipv6_against_ipv4_cidr_returns_false(): void
    {
        $this->assertFalse(IpResolver::isInCidr('::1', '192.168.1.0/24'));
    }

    public function test_invalid_ip_returns_false(): void
    {
        $this->assertFalse(IpResolver::isInCidr('not-an-ip', '192.168.1.0/24'));
    }

    public function test_invalid_cidr_no_slash_returns_false(): void
    {
        $this->assertFalse(IpResolver::isInCidr('192.168.1.1', '192.168.1.0'));
    }
}
