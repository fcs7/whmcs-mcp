<?php

declare(strict_types=1);

namespace NtMcp\Tests\Auth;

use NtMcp\Auth\AdminValidator;
use NtMcp\Tests\Support\FakeCapsule;
use PHPUnit\Framework\TestCase;

final class AdminValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        FakeCapsule::reset();
    }

    protected function tearDown(): void
    {
        FakeCapsule::reset();
    }

    public function test_null_username_is_not_active(): void
    {
        $this->assertFalse((new AdminValidator())->isActive(null));
    }

    public function test_empty_username_is_not_active(): void
    {
        $this->assertFalse((new AdminValidator())->isActive(''));
    }

    public function test_existing_enabled_admin_is_active(): void
    {
        FakeCapsule::withRows('tbladmins', [
            ['id' => 1, 'username' => 'felipe', 'disabled' => 0],
        ]);

        $this->assertTrue((new AdminValidator())->isActive('felipe'));
    }

    public function test_disabled_admin_is_not_active(): void
    {
        FakeCapsule::withRows('tbladmins', [
            ['id' => 1, 'username' => 'felipe', 'disabled' => 1],
        ]);

        $this->assertFalse((new AdminValidator())->isActive('felipe'));
    }

    public function test_missing_admin_is_not_active(): void
    {
        FakeCapsule::withRows('tbladmins', [
            ['id' => 1, 'username' => 'other', 'disabled' => 0],
        ]);

        $this->assertFalse((new AdminValidator())->isActive('felipe'));
    }
}
