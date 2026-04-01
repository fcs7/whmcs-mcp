<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\AdminSession;
use PHPUnit\Framework\TestCase;

class AdminSessionTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure a clean session superglobal for each test
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_returns_zero_when_key_absent(): void
    {
        $this->assertSame(0, AdminSession::getAdminId());
    }

    public function test_returns_zero_for_non_numeric_string(): void
    {
        $_SESSION['adminid'] = 'notanumber';
        $this->assertSame(0, AdminSession::getAdminId());
    }

    public function test_returns_zero_for_string_zero(): void
    {
        $_SESSION['adminid'] = '0';
        // is_numeric('0') is true, so (int)'0' === 0 — still treated as "no session"
        $this->assertSame(0, AdminSession::getAdminId());
    }

    public function test_returns_int_for_numeric_string(): void
    {
        $_SESSION['adminid'] = '42';
        $this->assertSame(42, AdminSession::getAdminId());
    }

    public function test_returns_int_for_integer_value(): void
    {
        $_SESSION['adminid'] = 7;
        $this->assertSame(7, AdminSession::getAdminId());
    }

    public function test_returns_zero_for_null(): void
    {
        $_SESSION['adminid'] = null;
        $this->assertSame(0, AdminSession::getAdminId());
    }
}
