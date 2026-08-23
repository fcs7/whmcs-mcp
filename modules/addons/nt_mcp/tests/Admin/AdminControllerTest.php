<?php

declare(strict_types=1);

namespace NtMcp\Tests\Admin;

use NtMcp\Admin\AdminController;
use NtMcp\Security\CsrfProtection;
use NtMcp\Tests\Support\ActivityLogSpy;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\SystemUrl;
use PHPUnit\Framework\TestCase;

final class AdminControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    /** @var array<string, mixed> */
    private array $postBackup;

    /** @var array<string, mixed> */
    private array $sessionBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->postBackup = $_POST;
        $this->sessionBackup = $_SESSION ?? [];

        FakeCapsule::reset();
        \WHMCS\Config\Setting::reset();
        ActivityLogSpy::start();
        SystemUrl::reset();
        $_SESSION = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        \WHMCS\Config\Setting::setValue('SystemURL', 'https://example.test');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_POST = $this->postBackup;
        $_SESSION = $this->sessionBackup;
        SystemUrl::reset();
        ActivityLogSpy::stop();
        \WHMCS\Config\Setting::reset();
        FakeCapsule::reset();
    }

    public function test_cleanup_post_deletes_expired_tokens_and_records_flash_and_audit(): void
    {
        FakeCapsule::withRows('mod_nt_mcp_oauth_tokens', [
            ['id' => 1, 'expires_at' => time() - 60],
            ['id' => 2, 'expires_at' => time() + 3600],
        ]);
        $_POST = [
            '_csrf_token' => CsrfProtection::token(),
            'clean_expired_oauth_tokens' => '1',
        ];

        ob_start();
        (new AdminController())->handle(['modulelink' => 'addonmodules.php?module=nt_mcp']);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('window.location.replace', $output);
        $this->assertSame('1 token(s) OAuth expirado(s) removido(s).', $_SESSION['nt_mcp_flash']['message']);
        $this->assertSame('success', $_SESSION['nt_mcp_flash']['class']);
        $this->assertSame([2], array_map(
            static fn(object $row): int => (int) $row->id,
            FakeCapsule::$rows['mod_nt_mcp_oauth_tokens']
        ));
        $this->assertTrue(ActivityLogSpy::hasEntryContaining(
            ActivityEvent::ADMIN_OAUTH_EXPIRED_CLEANED->value
        ));
    }
}
