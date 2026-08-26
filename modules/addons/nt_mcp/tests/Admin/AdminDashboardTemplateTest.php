<?php

declare(strict_types=1);

namespace NtMcp\Tests\Admin;

use NtMcp\Admin\GateConfigAction;
use NtMcp\Whmcs\ConfigFlag;
use PHPUnit\Framework\TestCase;

final class AdminDashboardTemplateTest extends TestCase
{
    public function test_gate_panel_renders_toggles_badges_and_allowlists(): void
    {
        $html = $this->renderDashboard(
            [],
            gateToggles: [
                'nt_mcp_readonly'           => ConfigFlag::Absent,
                'nt_mcp_enable_write'       => ConfigFlag::On,
                'nt_mcp_enable_destructive' => ConfigFlag::Off,
                'nt_mcp_enable_financial'   => ConfigFlag::Invalid,
                'nt_mcp_enable_cost'        => ConfigFlag::Absent,
                'nt_mcp_enable_comms'       => ConfigFlag::Absent,
            ],
            gateAllowlists: [
                'nt_mcp_write_allowlist_clientids' => '31,42',
                'nt_mcp_write_allowlist_ticketids' => '',
            ],
        );

        $this->assertStringContainsString('name="save_gate_config"', $html);
        // WRITE ligado vem marcado; DESTRUCTIVE desligado vem desmarcado.
        $this->assertStringContainsString('name="gate[nt_mcp_enable_write]" value="1" checked', $html);
        $this->assertStringContainsString('name="gate[nt_mcp_enable_destructive]" value="1">', $html);
        // Valor Invalid aparece como fail-closed, nunca como "desligado" comum.
        $this->assertStringContainsString('fail-closed', $html);
        $this->assertStringContainsString('name="nt_mcp_write_allowlist_clientids" value="31,42"', $html);
    }

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

    /**
     * @param array<int, object> $oauthTokens
     * @param array<string, ConfigFlag> $gateToggles
     * @param array<string, string> $gateAllowlists
     */
    private function renderDashboard(
        array $oauthTokens,
        ?array $gateToggles = null,
        ?array $gateAllowlists = null,
    ): string {
        $gateToggles ??= array_fill_keys(GateConfigAction::TOGGLE_KEYS, ConfigFlag::Absent);
        $gateAllowlists ??= array_fill_keys(GateConfigAction::ALLOWLIST_KEYS, '');
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
