<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\SystemUrl;
use PHPUnit\Framework\TestCase;

class SystemUrlTest extends TestCase
{
    protected function setUp(): void
    {
        SystemUrl::reset();
    }

    protected function tearDown(): void
    {
        SystemUrl::reset();
    }

    public function test_host_extracts_hostname_from_resolved_url(): void
    {
        $host = SystemUrl::host();

        // host() sempre devolve lowercase; esperamos um hostname válido
        $this->assertIsString($host);
        $this->assertNotEmpty($host);
        $this->assertSame($host, strtolower($host), 'hostname deve ser lowercase');
    }

    public function test_host_extracts_from_https_url(): void
    {
        // resolve() devolve a URL configurada (normalmente https)
        $url = SystemUrl::resolve();
        $host = SystemUrl::host();

        // Verifica que o hostname extraído bate com parse_url
        $expectedHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $this->assertSame($expectedHost, $host);
    }

    public function test_reset_clears_cache(): void
    {
        $host1 = SystemUrl::host();
        SystemUrl::reset();
        $host2 = SystemUrl::host();

        // Ambas devem ser iguais (mesma resolução)
        $this->assertSame($host1, $host2);
    }

    public function test_admin_folder_uses_custom_path(): void
    {
        SystemUrl::setAdminFolderResolverForTesting(fn () => 'gestor');

        $this->assertSame('gestor', SystemUrl::adminFolder());
    }

    public function test_admin_folder_falls_back_to_admin_when_unresolvable(): void
    {
        SystemUrl::setAdminFolderResolverForTesting(fn () => null);

        $this->assertSame('admin', SystemUrl::adminFolder());
    }

    public function test_admin_folder_falls_back_to_admin_on_invalid_value(): void
    {
        SystemUrl::setAdminFolderResolverForTesting(fn () => '../x');

        $this->assertSame('admin', SystemUrl::adminFolder());
    }

    public function test_admin_authorize_url_uses_custom_admin_folder(): void
    {
        SystemUrl::setAdminFolderResolverForTesting(fn () => 'gestor');

        $url = SystemUrl::adminAuthorizeUrl('req-123');

        $this->assertStringContainsString('/gestor/addonmodules.php?module=nt_mcp&authorize=req-123', $url);
        $this->assertStringNotContainsString('/admin/addonmodules.php', $url);
    }

    public function test_admin_url_defaults_to_admin_folder(): void
    {
        SystemUrl::setAdminFolderResolverForTesting(fn () => null);

        $url = SystemUrl::adminUrl('addonmodules.php?module=nt_mcp');

        $this->assertStringContainsString('/admin/addonmodules.php?module=nt_mcp', $url);
    }
}
