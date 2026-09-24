<?php

declare(strict_types=1);

namespace NtMcp\Tests\Admin;

use NtMcp\Admin\ExpiredOAuthTokenCleaner;
use NtMcp\Tests\Support\FakeCapsule;
use PHPUnit\Framework\TestCase;

final class ExpiredOAuthTokenCleanerTest extends TestCase
{
    protected function setUp(): void
    {
        FakeCapsule::reset();
    }

    protected function tearDown(): void
    {
        FakeCapsule::reset();
    }

    public function test_clean_removes_only_tokens_expired_at_or_before_now(): void
    {
        FakeCapsule::withRows('mod_nt_mcp_oauth_tokens', [
            ['id' => 1, 'expires_at' => 999],
            ['id' => 2, 'expires_at' => 1000],
            ['id' => 3, 'expires_at' => 1001],
        ]);

        $deleted = (new ExpiredOAuthTokenCleaner())->clean(1000);

        $this->assertSame(2, $deleted);
        $this->assertSame([3], array_map(
            static fn(object $row): int => (int) $row->id,
            FakeCapsule::$rows['mod_nt_mcp_oauth_tokens']
        ));
        $this->assertContains('where(expires_at,<=)', FakeCapsule::$calls);
        $this->assertSame('DELETE', FakeCapsule::$mutations[0]['verb']);
    }

    public function test_clean_also_removes_expired_refresh_tokens(): void
    {
        FakeCapsule::withRows('mod_nt_mcp_oauth_tokens', [
            ['id' => 1, 'expires_at' => 999],
        ]);
        FakeCapsule::withRows('mod_nt_mcp_oauth_refresh_tokens', [
            ['id' => 1, 'expires_at' => 999],
            ['id' => 2, 'expires_at' => 1000],
            ['id' => 3, 'expires_at' => 1001],
        ]);

        $deleted = (new ExpiredOAuthTokenCleaner())->clean(1000);

        $this->assertSame(3, $deleted);
        $this->assertSame([3], array_map(
            static fn(object $row): int => (int) $row->id,
            FakeCapsule::$rows['mod_nt_mcp_oauth_refresh_tokens']
        ));
        $this->assertSame([], FakeCapsule::$rows['mod_nt_mcp_oauth_tokens']);
    }
}
