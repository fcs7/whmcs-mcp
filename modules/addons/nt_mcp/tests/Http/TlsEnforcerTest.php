<?php

declare(strict_types=1);

namespace NtMcp\Tests\Http;

use NtMcp\Http\TlsEnforcer;
use PHPUnit\Framework\TestCase;

/**
 * SECURITY FIX (WO-4): TlsEnforcer::enforce() calls http_response_code()/
 * header()/exit, so it is not directly unit-testable. Its decision logic was
 * extracted into two pure, side-effect-free static methods —
 * isRequestSecure() and isHttpBypassAllowed() — which this suite exercises
 * directly by manipulating $_SERVER / the NT_MCP_ALLOW_HTTP env var.
 */
class TlsEnforcerTest extends TestCase
{
    private ?array $serverBackup = null;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        putenv('NT_MCP_ALLOW_HTTP');
        unset($_ENV['NT_MCP_ALLOW_HTTP']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        putenv('NT_MCP_ALLOW_HTTP');
        unset($_ENV['NT_MCP_ALLOW_HTTP']);
    }

    // --- isRequestSecure() ---

    public function test_isRequestSecure_true_when_https_server_var_on(): void
    {
        $_SERVER['HTTPS'] = 'on';
        unset($_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        $this->assertTrue(TlsEnforcer::isRequestSecure());
    }

    public function test_isRequestSecure_false_when_https_server_var_is_off(): void
    {
        $_SERVER['HTTPS'] = 'off';
        unset($_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        $this->assertFalse(TlsEnforcer::isRequestSecure());
    }

    public function test_isRequestSecure_true_when_server_port_443(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['SERVER_PORT'] = '443';

        $this->assertTrue(TlsEnforcer::isRequestSecure());
    }

    public function test_isRequestSecure_true_when_xfp_https_from_trusted_proxy(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertTrue(TlsEnforcer::isRequestSecure());
    }

    public function test_isRequestSecure_false_when_xfp_https_from_untrusted_remote_addr(): void
    {
        // SECURITY FIX (WO-4): X-Forwarded-Proto is attacker-controlled unless
        // it comes through a trusted proxy — must not be honored otherwise.
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertFalse(TlsEnforcer::isRequestSecure());
    }

    public function test_isRequestSecure_false_when_no_https_signal_present(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        $this->assertFalse(TlsEnforcer::isRequestSecure());
    }

    // --- isHttpBypassAllowed() ---

    public function test_isHttpBypassAllowed_false_when_env_not_set(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->assertFalse(TlsEnforcer::isHttpBypassAllowed());
    }

    public function test_isHttpBypassAllowed_true_when_env_set_and_remote_addr_is_trusted_proxy(): void
    {
        putenv('NT_MCP_ALLOW_HTTP=1');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->assertTrue(TlsEnforcer::isHttpBypassAllowed());
    }

    public function test_isHttpBypassAllowed_true_when_env_set_and_remote_addr_is_private(): void
    {
        putenv('NT_MCP_ALLOW_HTTP=1');
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';

        $this->assertTrue(TlsEnforcer::isHttpBypassAllowed());
    }

    public function test_isHttpBypassAllowed_false_when_env_set_but_remote_addr_is_public(): void
    {
        // SECURITY FIX (WO-4): NT_MCP_ALLOW_HTTP must never let an arbitrary
        // internet client bypass TLS enforcement.
        putenv('NT_MCP_ALLOW_HTTP=1');
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';

        $this->assertFalse(TlsEnforcer::isHttpBypassAllowed());
    }

    public function test_isHttpBypassAllowed_true_via_env_superglobal(): void
    {
        $_ENV['NT_MCP_ALLOW_HTTP'] = '1';
        $_SERVER['REMOTE_ADDR'] = '::1';

        $this->assertTrue(TlsEnforcer::isHttpBypassAllowed());
    }
}
