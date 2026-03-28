<?php
// tests/Auth/BearerAuthTest.php
namespace NtMcp\Tests\Auth;

use NtMcp\Auth\BearerAuth;
use PHPUnit\Framework\TestCase;

class BearerAuthTest extends TestCase
{
    public function test_validates_correct_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid-token-123';
        $auth = new BearerAuth('valid-token-123');
        $this->assertTrue($auth->isValid());
    }

    public function test_rejects_wrong_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-token';
        $auth = new BearerAuth('valid-token-123');
        $this->assertFalse($auth->isValid());
    }

    public function test_rejects_missing_header(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $auth = new BearerAuth('valid-token-123');
        $this->assertFalse($auth->isValid());
    }

    public function test_rejects_non_bearer_scheme(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
        $auth = new BearerAuth('valid-token-123');
        $this->assertFalse($auth->isValid());
    }
}
