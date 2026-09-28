<?php

declare(strict_types=1);

namespace NtMcp\Tests\OAuth;

use NtMcp\Admin\OAuthApprovalController;
use NtMcp\OAuth\Handlers\TokenHandler;
use NtMcp\Security\CsrfProtection;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeSchemaBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class ApprovalBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        class_alias(\WHMCS\Database\Capsule::class, 'Illuminate\\Database\\Capsule\\Manager');
        FakeCapsule::reset();
        FakeCapsule::withRows('mod_nt_mcp_oauth_clients', [['id' => 1, 'client_id' => 'client']]);
    }

    public function testPendingRequestCannotBeExchangedWithValidPkceAndFallbackAdmin(): void
    {
        $code = 'pending_' . str_repeat('a', 32);
        $verifier = str_repeat('x', 43);
        \WHMCS\Config\Setting::$store['nt_mcp_admin_user'] = 'admin';
        FakeCapsule::withRows('mod_nt_mcp_oauth_codes', [[
            'id' => 1, 'code' => hash('sha256', $code), 'used' => false,
            'expires_at' => time() + 600, 'approved_by' => null,
            'client_id' => 'attacker-client', 'redirect_uri' => 'https://example.org/callback',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ]]);
        $method = new \ReflectionMethod(TokenHandler::class, 'handleAuthorizationCode');
        ob_start();
        $method->invoke(null, ['code' => $code, 'code_verifier' => $verifier,
            'client_id' => 'attacker-client', 'redirect_uri' => 'https://example.org/callback']);
        $response = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('invalid_grant', $response['error']);
        self::assertSame(400, http_response_code());
        self::assertSame([], FakeCapsule::$mutations);
    }

    public static function invalidGrants(): array
    {
        return [
            'blank approval' => [['approved_by' => '   '], [], 'invalid_grant'],
            'expired' => [['expires_at' => 1], [], 'invalid_grant'],
            'consumed' => [['used' => true], [], 'invalid_grant'],
            'wrong client' => [[], ['client_id' => 'other'], 'invalid_client'],
            'wrong redirect' => [[], ['redirect_uri' => 'https://evil.example/'], 'invalid_grant'],
            'wrong verifier' => [[], ['code_verifier' => str_repeat('y', 43)], 'invalid_grant'],
            'array code' => [[], ['code' => []], 'invalid_request'],
            'array verifier' => [[], ['code_verifier' => []], 'invalid_request'],
            'short verifier' => [[], ['code_verifier' => 'short'], 'invalid_request'],
        ];
    }

    #[DataProvider('invalidGrants')]
    public function testRejectedGrantDoesNotConsumeCodeOrCreateTokens(array $rowChanges, array $paramChanges, string $error): void
    {
        $code = str_repeat('a', 64);
        $verifier = str_repeat('x', 43);
        FakeCapsule::withRows('mod_nt_mcp_oauth_codes', [array_replace([
            'id' => 1, 'code' => hash('sha256', $code), 'used' => false,
            'expires_at' => time() + 600, 'approved_by' => 'reviewer',
            'client_id' => 'client', 'redirect_uri' => 'https://example.org/callback',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ], $rowChanges)]);
        $method = new \ReflectionMethod(TokenHandler::class, 'handleAuthorizationCode');
        ob_start();
        $method->invoke(null, array_replace(['code' => $code, 'code_verifier' => $verifier,
            'client_id' => 'client', 'redirect_uri' => 'https://example.org/callback'], $paramChanges));
        $response = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($error, $response['error']);
        self::assertSame([], FakeCapsule::$mutations);
    }

    public static function decisions(): array
    {
        return [['deny', false], ['approve', false], ['deny', true], ['approve', true]];
    }

    #[DataProvider('decisions')]
    public function testRedirectCannotCloseScriptElement(string $decision, bool $hasQuery): void
    {
        $uri = 'https://example.org/</script><script>alert(1)</script>' . ($hasQuery ? '?existing=1' : '');
        FakeCapsule::withRows('mod_nt_mcp_oauth_codes', [['id' => 1, 'used' => false, 'expires_at' => time() + 600]]);
        FakeCapsule::withRows('tbladmins', [['id' => 1, 'username' => 'reviewer', 'disabled' => 0]]);
        FakeSchemaBuilder::install(['mod_nt_mcp_oauth_codes' => ['approved_by']]);
        $_POST = ['authorize_action' => $decision, '_csrf_token' => CsrfProtection::token()];
        $method = new \ReflectionMethod(OAuthApprovalController::class, 'handleApproval');
        ob_start();
        $method->invoke(new OAuthApprovalController(), (object) [
            'id' => 1, 'redirect_uri' => $uri, 'state' => '0',
            'client_id' => 'client', 'code_challenge' => str_repeat('x', 43),
        ], 'Client', 1, static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
        $html = ob_get_clean();
        self::assertSame(1, substr_count($html, '</script>'));
        self::assertStringNotContainsString('<script>alert(1)', $html);
        preg_match('/window.location.href=(.*?);<\/script>/', $html, $matches);
        $redirect = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertStringStartsWith($uri . ($hasQuery ? '&' : '?'), $redirect);
        self::assertStringContainsString('&state=0', $redirect);
    }
    public function testInvalidCsrfCannotConsumeRequestOrIssueCode(): void
    {
        $_POST = ['authorize_action' => 'approve', '_csrf_token' => ['invalid']];
        ob_start();
        (new \ReflectionMethod(OAuthApprovalController::class, 'handleApproval'))->invoke(
            new OAuthApprovalController(), (object) ['id' => 1], 'Client', 1,
            static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
        );
        $html = ob_get_clean();
        self::assertStringContainsString('Token CSRF invalido', $html);
        self::assertSame([], FakeCapsule::$mutations);
    }

    public function testApprovedCodeIssuesTokensBoundToApprover(): void
    {
        $code = str_repeat('a', 64);
        $verifier = str_repeat('x', 43);
        FakeSchemaBuilder::install(['mod_nt_mcp_oauth_tokens' => ['admin_user', 'family_id']]);
        FakeCapsule::withRows('tbladmins', [['id' => 1, 'username' => 'reviewer', 'disabled' => 0]]);
        FakeCapsule::withRows('mod_nt_mcp_oauth_codes', [[
            'id' => 1, 'code' => hash('sha256', $code), 'used' => false,
            'expires_at' => time() + 600, 'approved_by' => 'reviewer',
            'client_id' => 'client', 'redirect_uri' => 'https://example.org/callback',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ]]);
        $method = new \ReflectionMethod(TokenHandler::class, 'handleAuthorizationCode');
        ob_start();
        $method->invoke(null, ['code' => $code, 'code_verifier' => $verifier,
            'client_id' => 'client', 'redirect_uri' => 'https://example.org/callback']);
        $response = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('access_token', $response);
        self::assertArrayHasKey('refresh_token', $response);
        $inserts = array_values(array_filter(FakeCapsule::$mutations, fn(array $m): bool => $m['verb'] === 'INSERT'));
        self::assertCount(2, $inserts);
        foreach ($inserts as $insert) {
            self::assertSame('reviewer', $insert['values']['admin_user']);
        }
        self::assertSame($inserts[0]['values']['family_id'], $inserts[1]['values']['family_id']);
    }

}
