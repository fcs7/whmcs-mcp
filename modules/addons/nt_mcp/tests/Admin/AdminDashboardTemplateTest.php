<?php

declare(strict_types=1);

namespace NtMcp\Tests\Admin;

use PHPUnit\Framework\TestCase;

final class AdminDashboardTemplateTest extends TestCase
{
    public function test_cleanup_button_is_shown_with_expired_count(): void
    {
        $html = $this->renderDashboard([
            $this->token(1, time() - 60),
            $this->token(2, time() + 3600),
        ]);

        $this->assertStringContainsString('name="clean_expired_oauth_tokens"', $html);
        $this->assertStringContainsString('Limpar expirados (1)', $html);
        $this->assertStringContainsString('name="_csrf_token" value="csrf-test"', $html);
    }

    public function test_cleanup_button_is_hidden_when_no_token_is_expired(): void
    {
        $html = $this->renderDashboard([
            $this->token(1, time() + 3600),
        ]);

        $this->assertStringNotContainsString('clean_expired_oauth_tokens', $html);
        $this->assertStringNotContainsString('Limpar expirados', $html);
    }

    /** @param array<int, object> $oauthTokens */
    private function renderDashboard(array $oauthTokens): string
    {
        $e = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $mcpUrl = 'https://example.test/modules/addons/nt_mcp/mcp.php';
        $currentAdminId = 3;
        $currentAdminName = 'admin-test';
        $tokenHash = '';
        $tokenAdmin = '';
        $tokenCreated = '';
        $flashPlaintext = '';
        $flashMessage = '';
        $flashClass = 'info';
        $csrf = 'csrf-test';
        $oauthClients = [];
        $hasAdminUserCol = true;
        $hasLastUsedAtCol = true;

        ob_start();
        require dirname(__DIR__, 2) . '/templates/admin/dashboard.php';

        return (string) ob_get_clean();
    }

    private function token(int $id, int $expiresAt): object
    {
        return (object) [
            'id' => $id,
            'client_id' => 'client-' . $id,
            'client_name' => 'Client ' . $id,
            'admin_user' => 'admin-test',
            'created_at' => '2026-08-22 12:00:00',
            'expires_at' => $expiresAt,
            'last_used_at' => null,
        ];
    }
}
