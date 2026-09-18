<?php

declare(strict_types=1);

namespace NtMcp\Tests\WebMcp;

use NtMcp\Tests\Support\FakeCurrentUser;
use NtMcp\Tests\Support\ErrorLogSpy;
use NtMcp\WebMcp\ClientBootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Config\Setting;

final class ClientBootstrapTest extends TestCase
{
    private const PAGE = [
        'template' => 'ntweb-2026-theme',
        'nt_landing_slug' => 'hospedagem',
        'servedOverSsl' => true,
        'loggedin' => false,
        'WEB_ROOT' => '',
    ];

    protected function setUp(): void
    {
        Setting::reset();
        FakeCurrentUser::reset();
        ErrorLogSpy::start();
    }

    protected function tearDown(): void
    {
        ErrorLogSpy::stop();
        Setting::reset();
        FakeCurrentUser::reset();
    }

    #[DataProvider('disabledSettings')]
    public function test_absent_disabled_or_invalid_configuration_emits_nothing(mixed $value): void
    {
        Setting::setValue(ClientBootstrap::ENABLED_SETTING, $value);

        self::assertSame('', ClientBootstrap::footer(self::PAGE));
        self::assertSame('', ErrorLogSpy::contents());
    }

    public static function disabledSettings(): array
    {
        return array_map(static fn(mixed $value): array => [$value], [null, '', '0', false, 0, 'true', 'on', 2, []]);
    }

    public function test_enabled_anonymous_page_only_emits_our_same_origin_asset(): void
    {
        Setting::setValue(ClientBootstrap::ENABLED_SETTING, '1');

        self::assertSame(
            '<script defer src="/modules/addons/nt_mcp/assets/webmcp.js?v=20260905-1" data-nt-mcp-webmcp></script>',
            ClientBootstrap::footer(self::PAGE + ['token' => 'must-not-leak', 'clientid' => 123])
        );
        self::assertStringContainsString(
            'src="/billing/modules/addons/nt_mcp/assets/webmcp.js?',
            ClientBootstrap::footer(array_replace(self::PAGE, ['WEB_ROOT' => '/billing/']))
        );
    }

    #[DataProvider('excludedPages')]
    public function test_other_pages_or_unsafe_paths_emit_nothing(array $overrides): void
    {
        Setting::setValue(ClientBootstrap::ENABLED_SETTING, '1');

        self::assertSame('', ClientBootstrap::footer(array_replace(self::PAGE, $overrides)));
    }

    public static function excludedPages(): array
    {
        return [
            [['template' => 'twenty-one']],
            [['nt_landing_slug' => 'cloud']],
            [['nt_landing_slug' => null]],
            [['servedOverSsl' => false]],
            [['loggedin' => true]],
            [['WEB_ROOT' => null]],
            [['WEB_ROOT' => '//other.example']],
            [['WEB_ROOT' => 'https://other.example']],
            [['WEB_ROOT' => '/billing/../admin']],
            [['WEB_ROOT' => '/billing" onload="alert(1)']],
            [['WEB_ROOT' => '/billing\\evil']],
        ];
    }

    #[DataProvider('authenticatedActors')]
    public function test_any_authenticated_actor_is_excluded(bool $user, bool $admin, bool $client): void
    {
        Setting::setValue(ClientBootstrap::ENABLED_SETTING, '1');
        FakeCurrentUser::$user = $user;
        FakeCurrentUser::$admin = $admin;
        FakeCurrentUser::$client = $client ? (object) ['id' => 7] : null;

        self::assertSame('', ClientBootstrap::footer(self::PAGE));
    }

    public static function authenticatedActors(): array
    {
        return [
            'user without selected account' => [true, false, false],
            'client' => [true, false, true],
            'admin' => [false, true, false],
            'client masquerade' => [false, true, true],
            'account only' => [false, false, true],
        ];
    }

    public function test_config_or_authentication_failure_does_not_break_the_page(): void
    {
        Setting::$throwOnRead = true;
        self::assertSame('', ClientBootstrap::footer(self::PAGE));

        Setting::$throwOnRead = false;
        Setting::setValue(ClientBootstrap::ENABLED_SETTING, '1');
        FakeCurrentUser::$throwOnRead = true;
        self::assertSame('', ClientBootstrap::footer(self::PAGE));

        self::assertCount(2, ErrorLogSpy::lines());
        self::assertTrue(ErrorLogSpy::hasLineContaining('context=webmcp_footer_unavailable exception=RuntimeException'));
        self::assertStringNotContainsString('simulated config read failure', ErrorLogSpy::contents());
        self::assertStringNotContainsString('Authentication unavailable', ErrorLogSpy::contents());
    }

    public function test_diagnostics_do_not_expose_exception_messages_or_anonymous_class_paths(): void
    {
        Setting::$throwOnRead = true;
        Setting::$readFailure = new class('session-secret-must-not-leak') extends \RuntimeException {};

        self::assertSame('', ClientBootstrap::footer(self::PAGE));
        self::assertTrue(ErrorLogSpy::hasLineContaining('exception=RuntimeExceptionanonymous'));
        self::assertStringNotContainsString('session-secret-must-not-leak', ErrorLogSpy::contents());
        self::assertStringNotContainsString(__FILE__, ErrorLogSpy::contents());
        self::assertStringNotContainsString('fingerprint=', ErrorLogSpy::contents());
    }
}
