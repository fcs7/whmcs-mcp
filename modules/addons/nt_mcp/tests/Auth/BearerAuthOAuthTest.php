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

    private function makeAuth(): BearerAuth
    {
        return new BearerAuth($this->staticHash);
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

    public function test_valid_oauth_token_falls_back_to_admin_when_admin_user_empty(): void
    {
        $auth = $this->makeAuth();
        $row = (object) ['admin_user' => '   ', 'expires_at' => time() + 3600];
        $auth->setOAuthLookupCallable(fn(string $hash) => $row);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::OAUTH_TOKEN;

        $result = $auth->authenticate();
        $this->assertSame('admin', $result);
    }

    public function test_valid_oauth_token_without_admin_user_property_uses_fallback(): void
    {
        $auth = $this->makeAuth();
        $row = (object) ['expires_at' => time() + 3600]; // sem admin_user
        $auth->setOAuthLookupCallable(fn(string $hash) => $row);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::OAUTH_TOKEN;

        $result = $auth->authenticate();
        $this->assertSame('admin', $result);
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

        $row = (object) ['admin_user' => 'oauth_admin'];
        $auth->setOAuthLookupCallable(fn(string $hash) => $row); // OAuth sempre retornaria

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $staticToken;

        // Deve retornar o admin do token estatico (nao 'oauth_admin')
        // getStaticTokenAdmin() -> getFallbackAdmin() -> 'admin' (WHMCS\Config\Setting nao disponivel em tests)
        $result = $auth->authenticate();
        $this->assertSame('admin', $result);
    }
}
