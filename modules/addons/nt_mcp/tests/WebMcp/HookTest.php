<?php

declare(strict_types=1);

namespace NtMcp\Tests\WebMcp;

use NtMcp\Tests\Support\ErrorLogSpy;
use NtMcp\WebMcp\ClientBootstrap;
use NtMcp\Whmcs\ConfigFlag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WHMCS\Config\Setting;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HookTest extends TestCase
{
    private const ADDON = __DIR__ . '/../..';
    private ?string $fixture = null;

    protected function setUp(): void
    {
        if (!defined('WHMCS')) {
            define('WHMCS', true);
        }
        require __DIR__ . '/fixtures/add-hook.php';
        $GLOBALS['nt_webmcp_test_hooks'] = [];
        unset($GLOBALS['_nt_landing_slug']);
        Setting::reset();
        ErrorLogSpy::start();
    }

    protected function tearDown(): void
    {
        ErrorLogSpy::stop();
        if ($this->fixture !== null) {
            foreach (['hooks.php', 'src/Whmcs/ConfigFlag.php', 'src/WebMcp/ClientBootstrap.php'] as $file) {
                $path = $this->fixture . '/' . $file;
                if (is_file($path)) {
                    chmod($path, 0600);
                    unlink($path);
                }
            }
            foreach (['src/Whmcs', 'src/WebMcp', 'src', ''] as $directory) {
                rmdir($this->fixture . '/' . $directory);
            }
        }
    }

    public function test_registration_does_not_load_runtime_classes(): void
    {
        $before = get_included_files();
        require self::ADDON . '/hooks.php';

        self::assertSame([realpath(self::ADDON . '/hooks.php')], array_values(array_diff(get_included_files(), $before)));
        self::assertCount(1, $GLOBALS['nt_webmcp_test_hooks']);
        self::assertSame('ClientAreaFooterOutput', $GLOBALS['nt_webmcp_test_hooks'][0][0]);
        self::assertSame(1, $GLOBALS['nt_webmcp_test_hooks'][0][1]);
        self::assertFalse(class_exists(ConfigFlag::class, false));
        self::assertFalse(class_exists(ClientBootstrap::class, false));
    }

    public function test_callback_reads_marker_at_output_time_and_overrides_page_variables(): void
    {
        require self::ADDON . '/hooks.php';
        $callback = $GLOBALS['nt_webmcp_test_hooks'][0][2];
        Setting::setValue('nt_mcp_webmcp_enabled', '1');
        $vars = [
            'template' => 'ntweb-2026-theme',
            'servedOverSsl' => true,
            'WEB_ROOT' => '',
            'nt_landing_slug' => 'hospedagem',
        ];

        // A page variable cannot make up for an absent trusted entry-point marker.
        self::assertSame('', $callback($vars));
        $GLOBALS['_nt_landing_slug'] = 'hospedagem';
        $before = get_included_files();
        $output = $callback($vars);
        $after = get_included_files();
        self::assertStringContainsString('data-nt-mcp-webmcp', $output);
        self::assertSame($before, $after);
        $GLOBALS['_nt_landing_slug'] = 'cloud';
        self::assertSame('', $callback($vars));
    }

    #[DataProvider('incompleteUploads')]
    public function test_incomplete_upload_keeps_whmcs_output_working(string $file, string $problem): void
    {
        $this->fixture = sys_get_temp_dir() . '/nt_webmcp_hook_' . bin2hex(random_bytes(8));
        mkdir($this->fixture . '/src/Whmcs', 0700, true);
        mkdir($this->fixture . '/src/WebMcp', 0700, true);
        foreach (['hooks.php', 'src/Whmcs/ConfigFlag.php', 'src/WebMcp/ClientBootstrap.php'] as $source) {
            copy(self::ADDON . '/' . $source, $this->fixture . '/' . $source);
        }
        $path = $this->fixture . '/' . $file;
        if ($problem === 'missing') {
            unlink($path);
        } elseif ($problem === 'unreadable') {
            chmod($path, 0000);
            clearstatcache(true, $path);
            self::assertFalse(is_readable($path), 'Run PHPUnit as an unprivileged user.');
        } else {
            file_put_contents($path, '<?php invalid upload-secret-must-not-leak');
        }
        require $this->fixture . '/hooks.php';
        $callback = $GLOBALS['nt_webmcp_test_hooks'][0][2];

        self::assertSame('', $callback([]));
        self::assertSame('', ErrorLogSpy::contents());
    }

    public static function incompleteUploads(): array
    {
        return [
            ['src/Whmcs/ConfigFlag.php', 'missing'],
            ['src/WebMcp/ClientBootstrap.php', 'missing'],
            ['src/Whmcs/ConfigFlag.php', 'unreadable'],
            ['src/WebMcp/ClientBootstrap.php', 'unreadable'],
            ['src/Whmcs/ConfigFlag.php', 'syntax'],
            ['src/WebMcp/ClientBootstrap.php', 'syntax'],
        ];
    }

    public function test_runtime_failure_loads_only_the_existing_diagnostic_boundary(): void
    {
        require self::ADDON . '/hooks.php';
        $callback = $GLOBALS['nt_webmcp_test_hooks'][0][2];
        $GLOBALS['_nt_landing_slug'] = 'hospedagem';
        Setting::$throwOnRead = true;
        $before = get_included_files();

        $output = $callback([
            'template' => 'ntweb-2026-theme',
            'servedOverSsl' => true,
            'WEB_ROOT' => '',
        ]);
        $after = get_included_files();

        self::assertSame('', $output);
        self::assertSame([
            realpath(self::ADDON . '/src/Whmcs/ConfigFlag.php'),
            realpath(self::ADDON . '/src/WebMcp/ClientBootstrap.php'),
            realpath(self::ADDON . '/src/Whmcs/Diagnostics.php'),
        ], array_values(array_diff($after, $before)));
        self::assertTrue(ErrorLogSpy::hasLineContaining('context=webmcp_footer_unavailable exception=RuntimeException'));
        self::assertStringNotContainsString('simulated config read failure', ErrorLogSpy::contents());
        self::assertStringNotContainsString('fingerprint=', ErrorLogSpy::contents());
    }
}
