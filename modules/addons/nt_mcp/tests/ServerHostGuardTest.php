<?php

declare(strict_types=1);

namespace NtMcp\Tests;

use NtMcp\Server;
use PHPUnit\Framework\TestCase;

class ServerHostGuardTest extends TestCase
{
    public function test_expected_host_mismatch_returns_false_for_null_configured(): void
    {
        $result = Server::expectedHostMismatch(null, 'example.com');

        $this->assertFalse($result, 'null configured significa "qualquer hostname aceito"');
    }

    public function test_expected_host_mismatch_returns_false_for_empty_configured(): void
    {
        $result = Server::expectedHostMismatch('', 'example.com');

        $this->assertFalse($result, 'vazio configured significa "qualquer hostname aceito"');
    }

    public function test_expected_host_mismatch_returns_false_for_whitespace_only(): void
    {
        $result = Server::expectedHostMismatch('   ', 'example.com');

        $this->assertFalse($result, 'whitespace-only é tratado como vazio após trim');
    }

    public function test_expected_host_mismatch_returns_false_for_matching_hostnames(): void
    {
        $result = Server::expectedHostMismatch('example.com', 'example.com');

        $this->assertFalse($result, 'hostnames iguais = sem mismatch');
    }

    public function test_expected_host_mismatch_returns_false_for_case_insensitive_match(): void
    {
        $result = Server::expectedHostMismatch('EXAMPLE.COM', 'example.com');

        $this->assertFalse($result, 'comparação é case-insensitive após strtolower');
    }

    public function test_expected_host_mismatch_returns_false_for_configured_with_padding(): void
    {
        $result = Server::expectedHostMismatch('  example.com  ', 'example.com');

        $this->assertFalse($result, 'trim() remove espaçamento de configured');
    }

    public function test_expected_host_mismatch_returns_true_for_different_hostnames(): void
    {
        $result = Server::expectedHostMismatch('prod.example.com', 'desenv.example.com');

        $this->assertTrue($result, 'hostnames diferentes = mismatch');
    }

    public function test_expected_host_mismatch_returns_true_when_configured_is_substring(): void
    {
        // 'example.com' != 'prod.example.com' — substring não significa match
        $result = Server::expectedHostMismatch('example.com', 'prod.example.com');

        $this->assertTrue($result, 'substring não é match válido');
    }

    public function test_expected_host_mismatch_returns_true_when_actual_is_substring(): void
    {
        $result = Server::expectedHostMismatch('prod.example.com', 'example.com');

        $this->assertTrue($result, 'actual sendo substring também é mismatch');
    }

    public function test_expected_host_mismatch_case_insensitive_mismatch(): void
    {
        $result = Server::expectedHostMismatch('PROD.EXAMPLE.COM', 'desenv.example.com');

        $this->assertTrue($result, 'case-insensitive, mas ainda são hostnames diferentes');
    }
}
