<?php

declare(strict_types=1);

namespace NtMcp\Tests\Translation;

use NtMcp\Translation\TranslationGuard;
use NtMcp\Tests\Support\ActivityLogSpy;
use NtMcp\Whmcs\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranslationGuardTest extends TestCase
{
    protected function setUp(): void
    {
        ActivityLogSpy::start();
    }

    protected function tearDown(): void
    {
        ActivityLogSpy::stop();
    }

    #[Test]
    public function allows_write_when_enabled_and_not_readonly(): void
    {
        $guard = new TranslationGuard(['write' => true, 'readonly' => false]);

        $guard->assertWriteAllowed('whmcs_translation_email_set');

        $this->assertTrue(true);
    }

    #[Test]
    public function denies_when_master_readonly(): void
    {
        $guard = new TranslationGuard(['write' => true, 'readonly' => true]);

        $this->expectException(AuthorizationException::class);
        try {
            $guard->assertWriteAllowed('whmcs_translation_email_set');
        } finally {
            $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP DB WRITE BLOCKED'));
        }
    }

    #[Test]
    public function denies_when_write_class_disabled(): void
    {
        $guard = new TranslationGuard(['write' => false, 'readonly' => false]);

        $this->expectException(AuthorizationException::class);
        try {
            $guard->assertWriteAllowed('whmcs_translation_email_set');
        } finally {
            $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP DB WRITE BLOCKED'));
        }
    }
}
