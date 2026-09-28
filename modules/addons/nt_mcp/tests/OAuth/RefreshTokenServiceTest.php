<?php

declare(strict_types=1);

namespace NtMcp\Tests\OAuth;

use NtMcp\OAuth\RefreshTokenService;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeSchemaBuilder;
use NtMcp\Whmcs\ActivityEvent;
use PHPUnit\Framework\TestCase;

final class RefreshTokenServiceTest extends TestCase
{
    private const TABLE = 'mod_nt_mcp_oauth_refresh_tokens';
    private const TOKENS_TABLE = 'mod_nt_mcp_oauth_tokens';

    protected function setUp(): void
    {
        FakeCapsule::reset();
        FakeSchemaBuilder::reset();
        // Schema já migrado (C1: `used_at` lazy) — o caso de linha LEGADA
        // (coluna existe, valor null) é exercitado à parte, via dado da
        // linha, não via ausência de coluna.
        FakeSchemaBuilder::install([
            self::TABLE => ['id', 'token_hash', 'client_id', 'admin_user', 'family_id', 'expires_at', 'used', 'used_at', 'created_at'],
        ]);
    }

    protected function tearDown(): void
    {
        FakeCapsule::reset();
        FakeSchemaBuilder::reset();
    }

    public function test_issue_persists_hash_never_plaintext_and_returns_plaintext(): void
    {
        FakeCapsule::withRows(self::TABLE, []);

        $plaintext = (new RefreshTokenService())->issue('client1', 'felipe', 'fam1', 1000);

        $this->assertSame(64, strlen($plaintext));
        $this->assertTrue(ctype_xdigit($plaintext));

        $mutation = FakeCapsule::$mutations[0];
        $this->assertSame('INSERT', $mutation['verb']);
        $this->assertSame(self::TABLE, $mutation['table']);
        $this->assertSame(hash('sha256', $plaintext), $mutation['values']['token_hash']);
        $this->assertSame('client1', $mutation['values']['client_id']);
        $this->assertSame('felipe', $mutation['values']['admin_user']);
        $this->assertSame('fam1', $mutation['values']['family_id']);
        $this->assertFalse($mutation['values']['used']);
        $this->assertSame(1000 + 30 * 24 * 60 * 60, $mutation['values']['expires_at']);
        $this->assertNotContains($plaintext, $mutation['values']);
    }

    public function test_issue_generates_family_id_when_null(): void
    {
        FakeCapsule::withRows(self::TABLE, []);

        (new RefreshTokenService())->issue('client1', null, null, 1000);

        $familyId = FakeCapsule::$mutations[0]['values']['family_id'];
        $this->assertSame(32, strlen($familyId));
        $this->assertTrue(ctype_xdigit($familyId));
    }

    public function test_redeem_unknown_token_is_invalid_grant(): void
    {
        FakeCapsule::withRows(self::TABLE, []);

        $result = (new RefreshTokenService())->redeem('does-not-exist', 'client1', 1000);

        $this->assertFalse($result->ok);
        $this->assertFalse($result->reuseDetected);
        $this->assertSame('invalid_grant', $result->errorCode);
        $this->assertSame(ActivityEvent::OAUTH_REFRESH_NOT_FOUND, $result->deniedEvent);
    }

    public function test_redeem_happy_rotation_returns_client_admin_and_family(): void
    {
        FakeCapsule::withRows(self::TABLE, [
            [
                'id' => 1,
                'token_hash' => hash('sha256', 'plain-refresh'),
                'client_id' => 'client1',
                'admin_user' => 'felipe',
                'family_id' => 'fam1',
                'expires_at' => 2000,
                'used' => false,
            ],
        ]);
        FakeCapsule::withRows('tbladmins', [
            ['id' => 1, 'username' => 'felipe', 'disabled' => 0],
        ]);

        $result = (new RefreshTokenService())->redeem('plain-refresh', 'client1', 1000);

        $this->assertTrue($result->ok);
        $this->assertFalse($result->reuseDetected);
        $this->assertSame('client1', $result->clientId);
        $this->assertSame('felipe', $result->adminUser);
        $this->assertSame('fam1', $result->familyId);

        $this->assertContains('where(id)', FakeCapsule::$calls);
        $this->assertContains('where(used)', FakeCapsule::$calls);
        $this->assertContains('update()', FakeCapsule::$calls);
        // C1: attemptConsume grava `used_at` junto (schema já migrado) —
        // base da janela de graça contra reuso aparente de corrida paralela.
        $this->assertSame(['used' => true, 'used_at' => 1000], FakeCapsule::$mutations[0]['values']);
    }

    public function test_redeem_null_admin_user_denies_and_revokes_family(): void
    {
        FakeCapsule::withRows(self::TABLE, [
            [
                'id' => 1,
                'token_hash' => hash('sha256', 'plain-refresh'),
                'client_id' => 'client1',
                'admin_user' => null,
                'family_id' => 'fam1',
                'expires_at' => 2000,
                'used' => false,
            ],
        ]);

        $result = (new RefreshTokenService())->redeem('plain-refresh', 'client1', 1000);

        $this->assertFalse($result->ok);
        $this->assertSame(ActivityEvent::OAUTH_REFRESH_ADMIN_INACTIVE, $result->deniedEvent);
        $this->assertSame([], FakeCapsule::$rows[self::TABLE]);
        $this->assertNull($result->adminUser);
    }

    public function test_redeem_expired_token_is_invalid_grant_and_does_not_consume(): void
    {
        FakeCapsule::withRows(self::TABLE, [
            [
                'id' => 1,
                'token_hash' => hash('sha256', 'plain-refresh'),
                'client_id' => 'client1',
                'admin_user' => null,
                'family_id' => 'fam1',
                'expires_at' => 500,
                'used' => false,
            ],
        ]);

        $result = (new RefreshTokenService())->redeem('plain-refresh', 'client1', 1000);

        $this->assertFalse($result->ok);
        $this->assertFalse($result->reuseDetected);
        $this->assertSame('invalid_grant', $result->errorCode);
        $this->assertSame([], FakeCapsule::$mutations);
        $this->assertSame(ActivityEvent::OAUTH_REFRESH_EXPIRED, $result->deniedEvent);
    }

    public function test_redeem_client_id_mismatch_is_invalid_grant_and_does_not_consume(): void
    {
        FakeCapsule::withRows(self::TABLE, [
            [
                'id' => 1,
                'token_hash' => hash('sha256', 'plain-refresh'),
                'client_id' => 'client1',
                'admin_user' => null,
                'family_id' => 'fam1',
                'expires_at' => 2000,
                'used' => false,
            ],
        ]);

        $result = (new RefreshTokenService())->redeem('plain-refresh', 'someone-else', 1000);

        $this->assertFalse($result->ok);
        $this->assertFalse($result->reuseDetected);
        $this->assertSame('invalid_grant', $result->errorCode);
        $this->assertSame([], FakeCapsule::$mutations);
        $this->assertSame(ActivityEvent::OAUTH_REFRESH_CLIENT_MISMATCH, $result->deniedEvent);
    }

    public function test_redeem_reuse_detected_revokes_whole_family_in_both_tables(): void
    {
        FakeCapsule::withRows(self::TABLE, [
            [
                'id' => 1,
                'token_hash' => hash('sha256', 'already-used'),
                'client_id' => 'client1',
                'admin_user' => null,
                'family_id' => 'fam1',
                'expires_at' => 2000,
                'used' => true,
            ],
            [
                'id' => 2,
                'token_hash' => hash('sha256', 'sibling-in-family'),
                'client_id' => 'client1',
                'admin_user' => null,
                'family_id' => 'fam1',
                'expires_at' => 2000,
                'used' => false,
            ],
            [
                'id' => 3,
                'token_hash' => hash('sha256', 'other-family'),
                'client_id' => 'client1',
                'admin_user' => null,
                'family_id' => 'fam2',
                'expires_at' => 2000,
                'used' => false,
            ],
        ]);
        FakeCapsule::withRows(self::TOKENS_TABLE, [
            ['id' => 10, 'token_hash' => 'access-fam1', 'family_id' => 'fam1', 'expires_at' => 2000],
            ['id' => 11, 'token_hash' => 'access-fam2', 'family_id' => 'fam2', 'expires_at' => 2000],
        ]);

        $result = (new RefreshTokenService())->redeem('already-used', 'client1', 1000);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->reuseDetected);
        $this->assertSame('invalid_grant', $result->errorCode);
        $this->assertSame(ActivityEvent::OAUTH_REFRESH_REUSE_DETECTED, $result->deniedEvent);

        $remainingRefresh = array_map(static fn(object $r): int => (int) $r->id, FakeCapsule::$rows[self::TABLE]);
        $this->assertSame([3], $remainingRefresh);

        $remainingTokens = array_map(static fn(object $r): int => (int) $r->id, FakeCapsule::$rows[self::TOKENS_TABLE]);
        $this->assertSame([11], $remainingTokens);

        $this->assertContains('write:begin', FakeCapsule::$snapshotCalls);
        $this->assertContains('write:commit', FakeCapsule::$snapshotCalls);
    }

    /**
     * C1: corrida de refresh paralelo — o claude.ai dispara 2+ tool calls ao
     * mesmo tempo, todas mandam o MESMO refresh token. A primeira já
     * rotacionou (used=true, used_at=970) quando esta segunda lê a linha,
     * 30s depois (now=1000, dentro dos 60s de graça). NÃO é reuso: nega sem
     * revogar, e a família (dos dois lados) continua intacta.
     */
    public function test_redeem_reuse_within_grace_window_denies_without_revoking_family(): void
    {
        FakeCapsule::withRows(self::TABLE, [
            [
                'id' => 1,
                'token_hash' => hash('sha256', 'already-used-recently'),
                'client_id' => 'client1',
                'admin_user' => null,
                'family_id' => 'fam1',
                'expires_at' => 2000,
                'used' => true,
                'used_at' => 970,
            ],
        ]);
        FakeCapsule::withRows(self::TOKENS_TABLE, [
            ['id' => 10, 'token_hash' => 'access-fam1', 'family_id' => 'fam1', 'expires_at' => 2000],
        ]);

        $result = (new RefreshTokenService())->redeem('already-used-recently', 'client1', 1000);

        $this->assertFalse($result->ok);
        $this->assertFalse($result->reuseDetected);
        $this->assertSame('invalid_grant', $result->errorCode);
        $this->assertSame(ActivityEvent::OAUTH_REFRESH_RACE_LOST, $result->deniedEvent);

        // Família INTACTA nas duas tabelas — este é o ponto central do fix.
        $this->assertSame([1], array_map(static fn(object $r): int => (int) $r->id, FakeCapsule::$rows[self::TABLE]));
        $this->assertSame([10], array_map(static fn(object $r): int => (int) $r->id, FakeCapsule::$rows[self::TOKENS_TABLE]));
    }

    /**
     * Reuso FORA da janela de graça: mesma linha `used=true`, mas `used_at`
     * está a 61s no passado — é reuso genuíno, não corrida. Revoga a família
     * inteira, como o comportamento pré-C1.
     */
    public function test_redeem_reuse_outside_grace_window_revokes_family(): void
    {
        FakeCapsule::withRows(self::TABLE, [
            [
                'id' => 1,
                'token_hash' => hash('sha256', 'already-used-long-ago'),
                'client_id' => 'client1',
                'admin_user' => null,
                'family_id' => 'fam1',
                'expires_at' => 2000,
                'used' => true,
                'used_at' => 939,
            ],
        ]);
        FakeCapsule::withRows(self::TOKENS_TABLE, [
            ['id' => 10, 'token_hash' => 'access-fam1', 'family_id' => 'fam1', 'expires_at' => 2000],
        ]);

        $result = (new RefreshTokenService())->redeem('already-used-long-ago', 'client1', 1000);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->reuseDetected);
        $this->assertSame(ActivityEvent::OAUTH_REFRESH_REUSE_DETECTED, $result->deniedEvent);
        $this->assertSame([], FakeCapsule::$rows[self::TABLE]);
        $this->assertSame([], FakeCapsule::$rows[self::TOKENS_TABLE]);
    }

    /**
     * Linha legada (gravada antes desta migration): `used_at` existe como
     * coluna mas veio `null` para esta linha. Sem sinal de tempo, não há como
     * confirmar concorrência — trata como reuso genuíno (revoga), fail-safe.
     */
    public function test_redeem_reuse_with_null_used_at_is_treated_as_genuine_reuse(): void
    {
        FakeCapsule::withRows(self::TABLE, [
            [
                'id' => 1,
                'token_hash' => hash('sha256', 'already-used-legacy'),
                'client_id' => 'client1',
                'admin_user' => null,
                'family_id' => 'fam1',
                'expires_at' => 2000,
                'used' => true,
                'used_at' => null,
            ],
        ]);
        FakeCapsule::withRows(self::TOKENS_TABLE, [
            ['id' => 10, 'token_hash' => 'access-fam1', 'family_id' => 'fam1', 'expires_at' => 2000],
        ]);

        $result = (new RefreshTokenService())->redeem('already-used-legacy', 'client1', 1000);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->reuseDetected);
        $this->assertSame(ActivityEvent::OAUTH_REFRESH_REUSE_DETECTED, $result->deniedEvent);
        $this->assertSame([], FakeCapsule::$rows[self::TABLE]);
        $this->assertSame([], FakeCapsule::$rows[self::TOKENS_TABLE]);
    }

    public function test_redeem_inactive_admin_denies_and_revokes_family(): void
    {
        FakeCapsule::withRows(self::TABLE, [
            [
                'id' => 1,
                'token_hash' => hash('sha256', 'plain-refresh'),
                'client_id' => 'client1',
                'admin_user' => 'deleted-admin',
                'family_id' => 'fam2',
                'expires_at' => 2000,
                'used' => false,
            ],
        ]);
        FakeCapsule::withRows(self::TOKENS_TABLE, [
            ['id' => 10, 'token_hash' => 'access-fam2', 'family_id' => 'fam2', 'expires_at' => 2000],
        ]);
        // tbladmins vazio => AdminValidator::isActive('deleted-admin') = false
        FakeCapsule::withRows('tbladmins', []);

        $result = (new RefreshTokenService())->redeem('plain-refresh', 'client1', 1000);

        $this->assertFalse($result->ok);
        $this->assertFalse($result->reuseDetected);
        $this->assertSame('invalid_grant', $result->errorCode);
        $this->assertSame(ActivityEvent::OAUTH_REFRESH_ADMIN_INACTIVE, $result->deniedEvent);
        $this->assertSame([], FakeCapsule::$rows[self::TABLE]);
        $this->assertSame([], FakeCapsule::$rows[self::TOKENS_TABLE]);
    }

    public function test_revoke_family_deletes_rows_in_both_tables_inside_transaction(): void
    {
        FakeCapsule::withRows(self::TABLE, [
            ['id' => 1, 'family_id' => 'fam1'],
            ['id' => 2, 'family_id' => 'fam2'],
        ]);
        FakeCapsule::withRows(self::TOKENS_TABLE, [
            ['id' => 10, 'family_id' => 'fam1'],
            ['id' => 11, 'family_id' => 'fam2'],
        ]);

        (new RefreshTokenService())->revokeFamily('fam1');

        $this->assertSame([2], array_map(static fn(object $r): int => (int) $r->id, FakeCapsule::$rows[self::TABLE]));
        $this->assertSame([11], array_map(static fn(object $r): int => (int) $r->id, FakeCapsule::$rows[self::TOKENS_TABLE]));
        $this->assertContains('write:begin', FakeCapsule::$snapshotCalls);
        $this->assertContains('write:commit', FakeCapsule::$snapshotCalls);
    }

    public function test_purge_expired_deletes_only_expired_rows(): void
    {
        FakeCapsule::withRows(self::TABLE, [
            ['id' => 1, 'expires_at' => 999],
            ['id' => 2, 'expires_at' => 1000],
            ['id' => 3, 'expires_at' => 1001],
        ]);

        $deleted = (new RefreshTokenService())->purgeExpired(1000);

        $this->assertSame(2, $deleted);
        $this->assertSame([3], array_map(static fn(object $r): int => (int) $r->id, FakeCapsule::$rows[self::TABLE]));
    }

    /**
     * "Consumo concorrente" (H-04): não é reproduzível ponta-a-ponta via
     * redeem() com um fake síncrono de banco — o pré-check de reuso (passo
     * 2) sempre intercepta `used=true` antes de chegar no update atômico; a
     * corrida real só existe entre dois processos concorrentes disputando o
     * MESMO UPDATE no banco de verdade. Este teste isola o método privado
     * que faz o `where(id)->where(used,false)->update(used=true)` e prova
     * que, quando a linha já está marcada `used=true` no momento do UPDATE
     * (não importa por que), o resultado é "perdeu a corrida" (false) — a
     * garantia central do padrão H-04.
     */
    public function test_attempt_consume_returns_false_when_row_already_used(): void
    {
        FakeCapsule::withRows(self::TABLE, [
            ['id' => 1, 'used' => true],
        ]);

        $service = new RefreshTokenService();
        $method = new \ReflectionMethod($service, 'attemptConsume');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, 1, 1000));

        $this->assertContains('where(id)', FakeCapsule::$calls);
        $this->assertContains('where(used)', FakeCapsule::$calls);
        $this->assertContains('update()', FakeCapsule::$calls);
        $this->assertSame(['used' => true, 'used_at' => 1000], FakeCapsule::$mutations[0]['values']);
    }
}
