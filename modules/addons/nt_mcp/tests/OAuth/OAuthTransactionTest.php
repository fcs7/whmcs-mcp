<?php

declare(strict_types=1);
namespace NtMcp\Tests\OAuth;

use NtMcp\OAuth\OAuthRevocation;
use NtMcp\OAuth\OAuthTransaction;
use NtMcp\Tests\Support\FakeCapsule;
use PHPUnit\Framework\TestCase;

final class OAuthTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        FakeCapsule::reset();
        FakeCapsule::withRows('mod_nt_mcp_oauth_clients', [['id' => 1, 'client_id' => 'client']]);
    }
    protected function tearDown(): void { FakeCapsule::reset(); }

    public function testResponseIsReturnedOnlyAfterCommit(): void
    {
        $result = OAuthTransaction::capture(static function (): void {
            self::assertContains('lockForUpdate()', FakeCapsule::$calls);
            self::assertNotContains('write:commit', FakeCapsule::$snapshotCalls);
            echo 'credential';
        });
        self::assertSame('credential', $result);
        self::assertContains('write:commit', FakeCapsule::$snapshotCalls);
    }

    public function testFailedCommitDoesNotExposeCredential(): void
    {
        FakeCapsule::$snapshotFailures['commit'] = new \RuntimeException('Commit failed');
        ob_start();
        try {
            echo OAuthTransaction::capture(static function (): void { echo 'credential'; });
            self::fail('Commit failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame('Commit failed', $e->getMessage());
        } finally {
            self::assertSame('', ob_get_clean());
        }
    }

    public function testNonTransactionalStorageRejectsOperation(): void
    {
        FakeCapsule::$defaultEngine = 'MyISAM';
        $this->expectException(\RuntimeException::class);
        OAuthTransaction::run(static function (): void { self::fail('Operation must not run'); });
    }

    public function testRevokeAllInvalidatesPendingAndApprovedCodesToo(): void
    {
        foreach (['tokens', 'refresh_tokens', 'codes'] as $suffix) {
            FakeCapsule::withRows('mod_nt_mcp_oauth_' . $suffix, [['id' => 1]]);
        }
        self::assertSame(2, OAuthRevocation::all());
        foreach (['tokens', 'refresh_tokens', 'codes'] as $suffix) {
            self::assertSame([], FakeCapsule::$rows['mod_nt_mcp_oauth_' . $suffix]);
        }
        self::assertContains('lockForUpdate()', FakeCapsule::$calls);
        self::assertContains('write:commit', FakeCapsule::$snapshotCalls);
    }
}
