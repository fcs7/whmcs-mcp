<?php

declare(strict_types=1);

namespace NtMcp\Tests\Admin;

use NtMcp\Admin\AdminController;
use NtMcp\Security\CsrfProtection;
use NtMcp\Tests\Support\ActivityLogSpy;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\ErrorLogSpy;
use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\SystemUrl;
use NtMcp\WebMcp\ClientBootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
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
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $this->serverBackup = $_SERVER;
        $this->postBackup = $_POST;
        $this->sessionBackup = $_SESSION ?? [];

        FakeCapsule::reset();
        // The controller uses WHMCS's Illuminate facade. Keep this alias inside
        // each test process so other suites can exercise their own boundaries.
        if (!class_exists('Illuminate\Database\Capsule\Manager')) {
            class_alias(\WHMCS\Database\Capsule::class, 'Illuminate\Database\Capsule\Manager');
        }
        FakeCapsule::withRows('tbladmins', [['id' => 3, 'username' => 'admin-test']]);
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

    public function test_webmcp_can_be_enabled_and_disabled_without_changing_admin_gates_or_credentials(): void
    {
        $_SESSION['adminid'] = 3;
        \WHMCS\Config\Setting::setValue('nt_mcp_readonly', '1');
        \WHMCS\Config\Setting::setValue('nt_mcp_enable_write', '0');
        \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token', 'existing-hash');
        $_POST = [
            '_csrf_token' => CsrfProtection::token(),
            'save_webmcp_config' => '1',
            'webmcp_enabled' => '1',
            // Extra fields cannot smuggle changes into the independent form.
            'gate' => ['nt_mcp_enable_write' => '1'],
            'nt_mcp_bearer_token' => 'replacement',
        ];

        $this->postDashboard();
        $this->assertSame('1', \WHMCS\Config\Setting::getValue(ClientBootstrap::ENABLED_SETTING));
        $this->assertSame('success', $_SESSION['nt_mcp_flash']['class']);
        $this->assertTrue(ActivityLogSpy::hasEntryContaining(ActivityEvent::ADMIN_WEBMCP_CONFIG_CHANGED->value));
        $this->assertTrue(ActivityLogSpy::hasEntryContaining(ClientBootstrap::ENABLED_SETTING));

        unset($_POST['webmcp_enabled']);
        $this->postDashboard();
        $this->assertSame('0', \WHMCS\Config\Setting::getValue(ClientBootstrap::ENABLED_SETTING));
        $this->assertSame('1', \WHMCS\Config\Setting::getValue('nt_mcp_readonly'));
        $this->assertSame('0', \WHMCS\Config\Setting::getValue('nt_mcp_enable_write'));
        $this->assertSame('existing-hash', \WHMCS\Config\Setting::getValue('nt_mcp_bearer_token'));
    }

    #[DataProvider('rejectedWebmcpPosts')]
    public function test_rejected_webmcp_post_preserves_configuration(array $overrides, bool $admin): void
    {
        if ($admin) {
            $_SESSION['adminid'] = 3;
        }
        \WHMCS\Config\Setting::setValue(ClientBootstrap::ENABLED_SETTING, '0');
        $_POST = array_replace([
            '_csrf_token' => CsrfProtection::token(),
            'save_webmcp_config' => '1',
            'webmcp_enabled' => '1',
        ], $overrides);

        $this->postDashboard();

        $this->assertSame('0', \WHMCS\Config\Setting::getValue(ClientBootstrap::ENABLED_SETTING));
        $this->assertSame('danger', $_SESSION['nt_mcp_flash']['class']);
        $this->assertFalse(ActivityLogSpy::hasEntryContaining(ActivityEvent::ADMIN_WEBMCP_CONFIG_CHANGED->value));
    }

    public static function rejectedWebmcpPosts(): array
    {
        return [
            'invalid CSRF' => [['_csrf_token' => 'wrong'], true],
            'missing CSRF' => [['_csrf_token' => null], true],
            'array CSRF' => [['_csrf_token' => []], true],
            'array toggle' => [['webmcp_enabled' => []], true],
            'unknown toggle' => [['webmcp_enabled' => 'yes'], true],
            'empty toggle' => [['webmcp_enabled' => ''], true],
            'no admin session' => [[], false],
        ];
    }

    #[DataProvider('webmcpStates')]
    public function test_audit_sink_failure_does_not_report_a_saved_setting_as_failed(string $value): void
    {
        $_SESSION['adminid'] = 3;
        \WHMCS\Config\Setting::setValue(ClientBootstrap::ENABLED_SETTING, $value === '1' ? '0' : '1');
        $_POST = [
            '_csrf_token' => CsrfProtection::token(),
            'save_webmcp_config' => '1',
            'webmcp_enabled' => $value,
        ];
        ActivityLogSpy::failWith(new \RuntimeException('audit-sink-secret-must-not-leak'));
        ErrorLogSpy::start();
        try {
            $this->postDashboard();

            $this->assertSame($value, \WHMCS\Config\Setting::getValue(ClientBootstrap::ENABLED_SETTING));
            $this->assertSame('success', $_SESSION['nt_mcp_flash']['class']);
            $this->assertTrue(ErrorLogSpy::hasLineContaining('audit_sink_failure'));
            $this->assertStringNotContainsString('audit-sink-secret-must-not-leak', ErrorLogSpy::contents());
        } finally {
            ErrorLogSpy::stop();
        }
    }

    public static function webmcpStates(): array
    {
        return [['1'], ['0']];
    }

    private function postDashboard(): void
    {
        ob_start();
        try {
            (new AdminController())->handle(['modulelink' => 'addonmodules.php?module=nt_mcp']);
        } finally {
            ob_end_clean();
        }
    }
}
