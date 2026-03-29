<?php
// tests/Auth/BearerAuthTest.php
namespace NtMcp\Tests\Auth;

use NtMcp\Auth\BearerAuth;
use PHPUnit\Framework\TestCase;

class BearerAuthTest extends TestCase
{
    /** A 64-char hex token (matches bin2hex(random_bytes(32)) format). */
    private const TOKEN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    /** SHA-256 hash of the above token — what gets stored in DB. */
    private string $tokenHash;

    protected function setUp(): void
    {
        $this->tokenHash = hash('sha256', self::TOKEN);
    }

    // --- isValid() backward compat tests ---

    public function test_validates_correct_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
        $auth = new BearerAuth($this->tokenHash);
        $this->assertTrue($auth->isValid());
    }

    public function test_rejects_wrong_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('ff', 32);
        $auth = new BearerAuth($this->tokenHash);
        $this->assertFalse($auth->isValid());
    }

    public function test_rejects_missing_header(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $auth = new BearerAuth($this->tokenHash);
        $this->assertFalse($auth->isValid());
    }

    public function test_rejects_non_bearer_scheme(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
        $auth = new BearerAuth($this->tokenHash);
        $this->assertFalse($auth->isValid());
    }

    // --- Security hardening tests (F1, F17) ---

    public function test_rejects_empty_stored_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';
        $auth = new BearerAuth('');
        $this->assertFalse($auth->isValid());
    }

    public function test_rejects_short_stored_hash(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
        $auth = new BearerAuth('tooshort');
        $this->assertFalse($auth->isValid());
    }

    public function test_rejects_short_presented_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $auth = new BearerAuth($this->tokenHash);
        $this->assertFalse($auth->isValid());
    }

    // --- authenticate() tests ---

    public function test_authenticate_returns_string_for_valid_static_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
        $auth = new BearerAuth($this->tokenHash);
        $result = $auth->authenticate();
        $this->assertIsString($result);
        // Without WHMCS config, falls back to 'admin'
        $this->assertSame('admin', $result);
    }

    public function test_authenticate_returns_null_for_invalid_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('ff', 32);
        $auth = new BearerAuth($this->tokenHash);
        $this->assertNull($auth->authenticate());
    }

    public function test_authenticate_returns_null_for_missing_header(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $auth = new BearerAuth($this->tokenHash);
        $this->assertNull($auth->authenticate());
    }

    public function test_authenticate_returns_null_for_short_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $auth = new BearerAuth($this->tokenHash);
        $this->assertNull($auth->authenticate());
    }

    public function test_authenticate_returns_null_for_empty_hash(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
        $auth = new BearerAuth('');
        $this->assertNull($auth->authenticate());
    }

    public function test_isValid_wraps_authenticate(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
        $auth = new BearerAuth($this->tokenHash);
        // isValid() should return true when authenticate() returns non-null
        $this->assertSame($auth->authenticate() !== null, $auth->isValid());
    }
}
