<?php

declare(strict_types=1);

namespace NtMcp\Admin;

use Illuminate\Database\Capsule\Manager as Capsule;
use NtMcp\Http\IpResolver;
use NtMcp\Security\CsrfProtection;
use NtMcp\Whmcs\AdminSession;

/**
 * OAuth authorization approval controller.
 *
 * SECURITY (V-03 fix — CRITICAL CVSS 9.1): Runs ONLY inside the WHMCS
 * admin panel where admin session is guaranteed by WHMCS itself.
 *
 * Defense in depth (5 layers):
 *  1. WHMCS requires admin login to access configaddonmods.php (native)
 *  2. Explicit admin session verification via AdminSession::getAdminId() (belt-and-suspenders)
 *  3. CSRF token tied to admin session via HMAC (anti-forgery)
 *  4. Pending request expires in 10 minutes (temporal window)
 *  5. Admin ID + IP logged in WHMCS activity log (audit trail)
 */
final class OAuthApprovalController
{
    public function handle(array $vars): void
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Layer 2: Explicit admin session check (belt-and-suspenders)
        $adminId = AdminSession::getAdminId();
        if ($adminId === 0) {
            echo '<div class="alert alert-danger">';
            echo '<strong>Acesso negado.</strong> Sessao de administrador invalida.';
            echo '</div>';
            return;
        }

        $requestId = $_GET['authorize'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/', $requestId)) {
            echo '<div class="alert alert-danger">Request ID invalido.</div>';
            return;
        }

        // Load pending authorization request
        // SECURITY FIX (S2A-01): Lookup by hash — codes are stored hashed
        $pending = Capsule::table('mod_nt_mcp_oauth_codes')
            ->where('code', hash('sha256', 'pending_' . $requestId))
            ->where('used', false)
            ->where('expires_at', '>', time())
            ->first();

        if (!$pending) {
            echo '<div class="alert alert-danger">';
            echo 'Solicitacao de autorizacao nao encontrada, expirada ou ja processada.';
            echo '</div>';
            return;
        }

        // Load client info
        $client = Capsule::table('mod_nt_mcp_oauth_clients')
            ->where('client_id', $pending->client_id)
            ->first();

        $clientName = $client->client_name ?? 'MCP Client';

        // Handle POST: admin clicked Approve or Deny
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['authorize_action'])) {
            $this->handleApproval($pending, $clientName, $adminId, $e);
            return;
        }

        // Render approval form
        $csrf = CsrfProtection::token();
        $timeLeft = $pending->expires_at - time();
        $minutesLeft = max(1, (int) ceil($timeLeft / 60));

        require dirname(__DIR__, 2) . '/templates/admin/oauth-approve.php';
    }

    private function handleApproval(object $pending, string $clientName, int $adminId, \Closure $e): void
    {
        // Layer 3: CSRF validation
        if (!CsrfProtection::verify($_POST['_csrf_token'] ?? '')) {
            echo '<div class="alert alert-danger">';
            echo 'Token CSRF invalido. Recarregue a pagina e tente novamente.';
            echo '</div>';
            return;
        }

        // Mark pending request as used (V-04 atomic: WHERE used=0)
        $affected = Capsule::table('mod_nt_mcp_oauth_codes')
            ->where('id', $pending->id)
            ->where('used', false)
            ->update(['used' => true]);

        if ($affected === 0) {
            echo '<div class="alert alert-danger">Solicitacao ja foi processada.</div>';
            return;
        }

        $redirectUri = $pending->redirect_uri;
        $state       = $pending->state;

        // SECURITY FIX (F3 -- audit): Use proxy-aware IP for forensic value
        $clientIp = IpResolver::resolve();

        if ($_POST['authorize_action'] !== 'approve') {
            // DENIED
            logActivity("NT MCP: OAuth authorization DENIED for client '{$clientName}' by admin ID {$adminId} from IP {$clientIp}");

            $params = http_build_query(array_filter([
                'error'             => 'access_denied',
                'error_description' => 'Administrator denied the authorization request',
                'state'             => $state,
            ]));
            $url = $redirectUri . '?' . $params;
            echo '<script>window.location.href=' . json_encode($url, JSON_UNESCAPED_SLASHES) . ';</script>';
            echo '<noscript><div class="alert alert-warning">Autorizacao negada. ';
            echo '<a href="' . $e($url) . '">Clique aqui para continuar</a>.</div></noscript>';
            return;
        }

        // APPROVED — generate authorization code
        $authCode = bin2hex(random_bytes(32));

        // Resolve admin username for per-token binding
        $adminUsername = 'admin';
        try {
            $adminUsername = Capsule::table('tbladmins')->where('id', $adminId)->value('username') ?? 'admin';
        } catch (\Throwable $ex) {
            error_log('NT MCP OAuth: Failed to look up admin username for ID ' . $adminId . ': ' . $ex->getMessage());
        }

        // SECURITY FIX (S2A-01): Store hash, not plaintext
        // SECURITY FIX (F7 -- audit): Wrap in try/catch
        try {
            $codeData = [
                'code'           => hash('sha256', $authCode),
                'client_id'      => $pending->client_id,
                'code_challenge'  => $pending->code_challenge,
                'redirect_uri'   => $redirectUri,
                'state'          => $state,
                'expires_at'     => time() + 300, // 5 minutes
                'used'           => false,
                'created_at'     => date('Y-m-d H:i:s'),
            ];
            if (Capsule::schema()->hasColumn('mod_nt_mcp_oauth_codes', 'approved_by')) {
                $codeData['approved_by'] = $adminUsername;
            }
            Capsule::table('mod_nt_mcp_oauth_codes')->insert($codeData);
        } catch (\Throwable $dbEx) {
            error_log('NT MCP: Failed to insert authorization code: ' . $dbEx->getMessage());
            echo '<div class="alert alert-danger">Erro interno ao gerar codigo de autorizacao. Tente novamente.</div>';
            return;
        }

        // Layer 5: Audit trail
        logActivity("NT MCP: OAuth authorization APPROVED for client '{$clientName}' (client_id: {$pending->client_id}) by admin ID {$adminId} from IP {$clientIp}");

        $params = http_build_query(array_filter([
            'code'  => $authCode,
            'state' => $state,
        ]));
        $url = $redirectUri . '?' . $params;
        echo '<script>window.location.href=' . json_encode($url, JSON_UNESCAPED_SLASHES) . ';</script>';
        echo '<noscript><div class="alert alert-success">Autorizacao aprovada. ';
        echo '<a href="' . $e($url) . '">Clique aqui para continuar</a>.</div></noscript>';
    }
}
