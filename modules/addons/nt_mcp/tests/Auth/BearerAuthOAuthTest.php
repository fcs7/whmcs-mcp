<?php

declare(strict_types=1);

namespace NtMcp\Tests\Auth;

use NtMcp\Auth\BearerAuth;
use PHPUnit\Framework\TestCase;

/**
 * Testa o caminho OAuth de BearerAuth::authenticate() via callable injetavel.
 */
class BearerAuthOAuthTest extends TestCase
{
    private const OAUTH_TOKEN = 'oauth_token_abcdef1234567890abcdef1234567890abcdef12';
    private string $oauthHash;
    private string $staticHash;

    protected function setUp(): void
    {
        $this->oauthHash  = hash('sha256', self::OAUTH_TOKEN);
        // Static token hash diferente para que o caminho estatico falhe e caia no OAuth
        $this->staticHash = hash('sha256', 'different_static_token_not_equal_to_oauth');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    private function makeAuth(bool $adminActive = true): BearerAuth
    {
        $auth = new BearerAuth($this->staticHash);
        // B1: tests precisam injetar um validator truthy por padrão, senão
        // validateAdminActive() cai no fail-closed (no DB = false).
        $auth->setAdminValidatorCallable(fn(string $u) => $adminActive);
        $auth->setTokenRevokerCallable(function (int $id): void {});
        return $auth;
    }

    // --- Caminho feliz ---

    public function test_valid_oauth_token_returns_admin_user_from_row(): void
    {
        $auth = $this->makeAuth();
        $row = (object) ['admin_user' => 'john', 'expires_at' => time() + 3600];
        $auth->setOAuthLookupCallable(fn(string $hash) => $hash === $this->oauthHash ? $row : null);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::OAUTH_TOKEN;

        $this->assertSame('john', $auth->authenticate());
    }

    public function test_valid_oauth_token_denies_when_admin_user_empty_and_no_fallback_configured(): void
    {
        // SECURITY FIX (WO-7): empty admin_user on the token row falls
        // through to getFallbackAdmin(), which now fails closed (null)
        // instead of returning the hardcoded 'admin' superadmin when
        // nt_mcp_admin_user isn't configured.
        $auth = $this->makeAuth();
        $row = (object) ['admin_user' => '   ', 'expires_at' => time() + 3600];
        $auth->setOAuthLookupCallable(fn(string $hash) => $row);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::OAUTH_TOKEN;

        $result = $auth->authenticate();
        $this->assertNull($result);
    }

    public function test_valid_oauth_token_without_admin_user_property_denies_without_fallback_configured(): void
    {
        // SECURITY FIX (WO-7): same as above, but for a row that never had
        // the admin_user column at all (pre-migration DBs).
        $auth = $this->makeAuth();
        $row = (object) ['expires_at' => time() + 3600]; // sem admin_user
        $auth->setOAuthLookupCallable(fn(string $hash) => $row);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::OAUTH_TOKEN;

        $result = $auth->authenticate();
        $this->assertNull($result);
    }

    // --- Caminho de rejeicao ---

    public function test_oauth_token_not_found_returns_null(): void
    {
        $auth = $this->makeAuth();
        $auth->setOAuthLookupCallable(fn(string $hash) => null); // token nao existe no DB

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::OAUTH_TOKEN;

        $this->assertNull($auth->authenticate());
    }

    public function test_wrong_oauth_token_returns_null(): void
    {
        $auth = $this->makeAuth();
        $correctHash = hash('sha256', 'correct_token_xxxxxxxxxxxxxxxxxxxxxxxxxxx');
        $row = (object) ['admin_user' => 'bob'];
        // callable so retorna row para o hash correto
        $auth->setOAuthLookupCallable(fn(string $hash) => $hash === $correctHash ? $row : null);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::OAUTH_TOKEN; // hash diferente

        $this->assertNull($auth->authenticate());
    }

    // --- Prioridade: token estatico vence sobre OAuth ---

    public function test_static_token_wins_over_oauth_when_both_configured(): void
    {
        $staticToken = 'static_token_abcdef1234567890abcdef1234567890ab';
        $auth = new BearerAuth(hash('sha256', $staticToken));
        $auth->setAdminValidatorCallable(fn(string $u) => true); // B1

        $row = (object) ['admin_user' => 'oauth_admin'];
        $auth->setOAuthLookupCallable(fn(string $hash) => $row); // OAuth sempre retornaria

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $staticToken;

        // Deve seguir o caminho do token estatico (nao 'oauth_admin'), que
        // cai em getStaticTokenAdmin() -> getFallbackAdmin(). Sem
        // nt_mcp_admin_user configurado (WHMCS\Config\Setting nao existe em
        // testes), o fallback agora nega (null) em vez do antigo 'admin'
        // hardcoded (SECURITY FIX WO-7) -- mas o importante e que NUNCA
        // retorna 'oauth_admin'.
        $result = $auth->authenticate();
        $this->assertNull($result);
    }

    // --- B1: Orphan token defense — admin deletado/disabled em tbladmins ---

    public function test_oauth_token_with_disabled_admin_returns_null(): void
    {
        $auth = new BearerAuth($this->staticHash);
        $row = (object) ['id' => 42, 'admin_user' => 'john', 'expires_at' => time() + 3600];
        $auth->setOAuthLookupCallable(fn(string $hash) => $row);
        // tbladmins retorna false → admin foi deletado ou disabled=1
        $auth->setAdminValidatorCallable(fn(string $u) => false);

        $revokedId = null;
        $auth->setTokenRevokerCallable(function (int $id) use (&$revokedId): void {
            $revokedId = $id;
        });

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::OAUTH_TOKEN;

        $this->assertNull($auth->authenticate(), 'orphan admin must force 401');
        $this->assertSame(42, $revokedId, 'token precisa ser revogado por id');
    }

    public function test_oauth_token_with_active_admin_passes(): void
    {
        $auth = new BearerAuth($this->staticHash);
        $row = (object) ['id' => 7, 'admin_user' => 'alice', 'expires_at' => time() + 3600];
        $auth->setOAuthLookupCallable(fn(string $hash) => $row);

        $validatorCalled = false;
        $auth->setAdminValidatorCallable(function (string $u) use (&$validatorCalled) {
            $validatorCalled = true;
            return $u === 'alice';
        });
        $auth->setTokenRevokerCallable(function (int $id): void {
            throw new \RuntimeException('revoke não deveria rodar quando admin está ativo');
        });

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::OAUTH_TOKEN;

        $this->assertSame('alice', $auth->authenticate());
        $this->assertTrue($validatorCalled, 'validator precisa ser chamado');
    }
}
