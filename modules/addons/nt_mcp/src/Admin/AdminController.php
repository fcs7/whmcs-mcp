<?php

declare(strict_types=1);

namespace NtMcp\Admin;

use NtMcp\Whmcs\Diagnostics;
use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\ActivityLog;
use NtMcp\Whmcs\AuditMetadata;

use Illuminate\Database\Capsule\Manager as Capsule;
use NtMcp\Security\CsrfProtection;
use NtMcp\Whmcs\AdminSession;
use NtMcp\Whmcs\ConfigFlag;
use NtMcp\Whmcs\SystemUrl;

/**
 * Admin dashboard controller — Auth management UI.
 */
final class AdminController
{
    public function handle(array $vars): void
    {
        // Ensure session is started (needed for flash messages before CSRF token call)
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mcpUrl = SystemUrl::mcpUrl();

        // Auto-detect logged-in admin
        $currentAdminId = AdminSession::getAdminId();
        $currentAdminName = 'admin';
        if ($currentAdminId > 0) {
            try {
                $currentAdminName = Capsule::table('tbladmins')
                    ->where('id', $currentAdminId)
                    ->value('username') ?? 'admin';
            } catch (\Throwable $ex) {
                Diagnostics::report(Diagnostics::CATEGORY_ADMIN_LOOKUP, 'tbladmins', $ex);
            }
        }

        // Static token metadata
        $tokenAdmin   = trim(\WHMCS\Config\Setting::getValue('nt_mcp_bearer_token_admin') ?? '');
        $tokenCreated = trim(\WHMCS\Config\Setting::getValue('nt_mcp_bearer_token_created') ?? '');
        $tokenHash    = trim(\WHMCS\Config\Setting::getValue('nt_mcp_bearer_token') ?? '');

        // Handle POST actions — PRG via session flash + JS redirect
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $flashMessage   = '';
            $flashClass     = 'info';
            $flashPlaintext = '';

            $csrfOk = CsrfProtection::verify($_POST['_csrf_token'] ?? '');

            if (!$csrfOk) {
                $flashMessage = 'Erro: token CSRF invalido. Recarregue a pagina e tente novamente.';
                $flashClass   = 'danger';
            } elseif (isset($_POST['regenerate_token'])) {
                $newToken       = bin2hex(random_bytes(32));
                $hash           = hash('sha256', $newToken);
                \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token', $hash);
                \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token_admin', $currentAdminName);
                \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token_created', date('Y-m-d H:i:s'));
                \WHMCS\Config\Setting::setValue('nt_mcp_admin_user', $currentAdminName);
                $flashPlaintext = $newToken;
                $flashMessage   = 'Token regenerado com sucesso. Copie-o agora; ele nao sera exibido novamente.';
                $flashClass     = 'success';
                ActivityLog::record(ActivityEvent::ADMIN_BEARER_REGENERATED, AuditMetadata::ids(['adminid' => $currentAdminId]));
            } elseif (isset($_POST['revoke_oauth_token'])) {
                $tokenId = (int) ($_POST['token_id'] ?? 0);
                if ($tokenId > 0) {
                    try {
                        Capsule::table('mod_nt_mcp_oauth_tokens')->where('id', $tokenId)->delete();
                        $flashMessage = 'Token OAuth revogado com sucesso.';
                        $flashClass   = 'success';
                        ActivityLog::record(ActivityEvent::ADMIN_OAUTH_TOKEN_REVOKED, AuditMetadata::ids(['id' => $tokenId, 'adminid' => $currentAdminId]));
                    } catch (\Throwable $ex) {
                        Diagnostics::report(Diagnostics::CATEGORY_ADMIN_UI, 'oauth_token_revoke', $ex);
                        $flashMessage = 'Erro ao revogar token. Verifique o log de erros.';
                        $flashClass   = 'danger';
                    }
                }
            } elseif (isset($_POST['clean_expired_oauth_tokens'])) {
                try {
                    $deleted = (new ExpiredOAuthTokenCleaner())->clean(time());
                    if ($deleted > 0) {
                        $flashMessage = $deleted . ' token(s) OAuth expirado(s) removido(s).';
                        $flashClass   = 'success';
                        ActivityLog::record(
                            ActivityEvent::ADMIN_OAUTH_EXPIRED_CLEANED,
                            AuditMetadata::ids(['adminid' => $currentAdminId])
                        );
                    } else {
                        $flashMessage = 'Nenhum token OAuth expirado encontrado.';
                        $flashClass   = 'info';
                    }
                } catch (\Throwable $ex) {
                    Diagnostics::report(Diagnostics::CATEGORY_ADMIN_UI, 'oauth_token_clean_expired', $ex);
                    $flashMessage = 'Erro ao limpar tokens expirados. Verifique o log de erros.';
                    $flashClass   = 'danger';
                }
            } elseif (isset($_POST['revoke_all_oauth_tokens'])) {
                try {
                    $deleted = Capsule::table('mod_nt_mcp_oauth_tokens')->delete();
                    $flashMessage = $deleted . ' token(s) OAuth revogado(s).';
                    $flashClass   = 'success';
                    ActivityLog::record(ActivityEvent::ADMIN_OAUTH_ALL_REVOKED, AuditMetadata::ids(['adminid' => $currentAdminId]));
                } catch (\Throwable $ex) {
                    Diagnostics::report(Diagnostics::CATEGORY_ADMIN_UI, 'oauth_token_revoke_all', $ex);
                    $flashMessage = 'Erro ao revogar tokens. Verifique o log de erros.';
                    $flashClass   = 'danger';
                }
            } elseif (isset($_POST['remove_oauth_client'])) {
                $clientIdToRemove = trim($_POST['client_id_remove'] ?? '');
                if ($clientIdToRemove !== '') {
                    try {
                        Capsule::connection()->transaction(function () use ($clientIdToRemove) {
                            Capsule::table('mod_nt_mcp_oauth_tokens')
                                ->where('client_id', $clientIdToRemove)->delete();
                            Capsule::table('mod_nt_mcp_oauth_codes')
                                ->where('client_id', $clientIdToRemove)->delete();
                            Capsule::table('mod_nt_mcp_oauth_clients')
                                ->where('client_id', $clientIdToRemove)->delete();
                        });
                        $flashMessage = 'Client OAuth removido junto com seus tokens.';
                        $flashClass   = 'success';
                        ActivityLog::record(ActivityEvent::ADMIN_OAUTH_CLIENT_REMOVED, AuditMetadata::ids(['adminid' => $currentAdminId]));
                    } catch (\Throwable $ex) {
                        Diagnostics::report(Diagnostics::CATEGORY_ADMIN_UI, 'oauth_client_remove', $ex);
                        $flashMessage = 'Erro ao remover client. Verifique o log de erros.';
                        $flashClass   = 'danger';
                    }
                }
            } elseif (isset($_POST['save_gate_config'])) {
                $keys = array_merge(GateConfigAction::TOGGLE_KEYS, GateConfigAction::ALLOWLIST_KEYS);
                $currentValues = [];
                foreach ($keys as $key) {
                    $currentValues[$key] = \WHMCS\Config\Setting::getValue($key);
                }

                $result = GateConfigAction::fromPost($_POST, $currentValues);
                if (!$result['ok']) {
                    $flashMessage = 'Erro: ' . $result['error'];
                    $flashClass   = 'danger';
                } elseif ($result['changes'] === []) {
                    $flashMessage = 'Nenhuma alteracao nos gates.';
                    $flashClass   = 'info';
                } else {
                    try {
                        foreach ($result['changes'] as $key => $change) {
                            \WHMCS\Config\Setting::setValue($key, $change['new']);
                        }

                        // Auditoria: valor NOVO de cada toggle alterado (bool) e
                        // TAMANHO das allowlists alteradas — nunca os ids em si.
                        $auditParams = ['adminid' => $currentAdminId];
                        foreach ($result['changes'] as $key => $change) {
                            if (in_array($key, GateConfigAction::ALLOWLIST_KEYS, true)) {
                                $auditParams[$key] = $change['new'] === '' ? [] : explode(',', $change['new']);
                            } else {
                                $auditParams[$key] = $change['new'] === '1';
                            }
                        }
                        ActivityLog::record(
                            ActivityEvent::ADMIN_GATE_CONFIG_CHANGED,
                            AuditMetadata::forParams($auditParams)
                        );

                        $flashMessage = 'Gates atualizados: ' . implode(', ', array_keys($result['changes'])) . '.';
                        $flashClass   = 'success';
                    } catch (\Throwable $ex) {
                        Diagnostics::report(Diagnostics::CATEGORY_ADMIN_UI, 'gate_config_save', $ex);
                        $flashMessage = 'Erro ao salvar gates. Verifique o log de erros.';
                        $flashClass   = 'danger';
                    }
                }
            }

            // Store flash in session and redirect via JS (headers already sent in WHMCS _output)
            $_SESSION['nt_mcp_flash'] = [
                'message'   => $flashMessage,
                'class'     => $flashClass,
                'plaintext' => $flashPlaintext,
                '_ts'       => time(),
            ];
            $redirectUrl = self::addonUrl($vars);
            echo '<script>window.location.replace(' . json_encode($redirectUrl, JSON_HEX_TAG | JSON_HEX_AMP) . ');</script>';
            echo '<noscript><div class="alert alert-info">Processando... ';
            echo '<a href="' . htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Clique aqui se nao for redirecionado.</a></div></noscript>';
            return;
        }

        // GET: read flash from session (expire after 30s to limit plaintext exposure)
        $flashPlaintext = '';
        $flashMessage   = '';
        $flashClass     = 'info';
        if (isset($_SESSION['nt_mcp_flash'])) {
            $flash = $_SESSION['nt_mcp_flash'];
            unset($_SESSION['nt_mcp_flash']);
            if (time() - ($flash['_ts'] ?? 0) <= 30) {
                $flashMessage   = $flash['message'] ?? '';
                $flashClass     = $flash['class'] ?? 'info';
                $flashPlaintext = $flash['plaintext'] ?? '';
            }
        }

        $csrf = CsrfProtection::token();

        // Load OAuth data
        $oauthTokens  = [];
        $oauthClients = [];
        $hasAdminUserCol  = false;
        $hasLastUsedAtCol = false;
        try {
            if (Capsule::schema()->hasTable('mod_nt_mcp_oauth_tokens')) {
                $hasAdminUserCol  = Capsule::schema()->hasColumn('mod_nt_mcp_oauth_tokens', 'admin_user');
                $hasLastUsedAtCol = Capsule::schema()->hasColumn('mod_nt_mcp_oauth_tokens', 'last_used_at');

                $query = Capsule::table('mod_nt_mcp_oauth_tokens')
                    ->leftJoin('mod_nt_mcp_oauth_clients', 'mod_nt_mcp_oauth_tokens.client_id', '=', 'mod_nt_mcp_oauth_clients.client_id')
                    ->select(
                        'mod_nt_mcp_oauth_tokens.id',
                        'mod_nt_mcp_oauth_tokens.client_id',
                        'mod_nt_mcp_oauth_tokens.expires_at',
                        'mod_nt_mcp_oauth_tokens.created_at',
                        'mod_nt_mcp_oauth_clients.client_name'
                    );
                if ($hasAdminUserCol) {
                    $query->addSelect('mod_nt_mcp_oauth_tokens.admin_user');
                }
                if ($hasLastUsedAtCol) {
                    $query->addSelect('mod_nt_mcp_oauth_tokens.last_used_at');
                }
                $oauthTokens = $query
                    ->orderBy('mod_nt_mcp_oauth_tokens.created_at', 'desc')
                    ->get()
                    ->all();
            }
            if (Capsule::schema()->hasTable('mod_nt_mcp_oauth_clients')) {
                $oauthClients = Capsule::table('mod_nt_mcp_oauth_clients')
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->all();
            }
        } catch (\Throwable $ex) {
            Diagnostics::report(Diagnostics::CATEGORY_ADMIN_UI, 'oauth_data_load', $ex);
            if ($flashMessage === '') {
                $flashMessage = 'Aviso: Nao foi possivel carregar dados OAuth. Verifique a conexao com o banco.';
                $flashClass   = 'warning';
            }
        }

        // Painel de gates: estado cru → ConfigFlag por toggle (a UI mostra
        // Invalid como fail-closed em vez de fingir "desligado"), CSV cru das
        // allowlists. Fora de um WHMCS bootstrapado nada disso é alcançado.
        $gateToggles    = [];
        $gateAllowlists = [];
        try {
            foreach (GateConfigAction::TOGGLE_KEYS as $key) {
                $gateToggles[$key] = ConfigFlag::parse(\WHMCS\Config\Setting::getValue($key));
            }
            foreach (GateConfigAction::ALLOWLIST_KEYS as $key) {
                $gateAllowlists[$key] = trim((string) (\WHMCS\Config\Setting::getValue($key) ?? ''));
            }
        } catch (\Throwable $ex) {
            Diagnostics::report(Diagnostics::CATEGORY_ADMIN_UI, 'gate_config_load', $ex);
            $gateToggles    = [];
            $gateAllowlists = [];
        }

        // Deliberate choice: PHP-native template (not Smarty) for explicit
        // htmlspecialchars() escaping with ENT_QUOTES|ENT_SUBSTITUTE. Avoids
        // relying on Smarty's auto-escape behavior and its specific version
        // bundled by WHMCS. Bootstrap 3 classes (panel, btn, alert) from the
        // admin panel context are reused for visual consistency.
        require dirname(__DIR__, 2) . '/templates/admin/dashboard.php';
    }

    private static function addonUrl(array $vars = []): string
    {
        if (!empty($vars['modulelink'])) {
            return $vars['modulelink'];
        }
        return SystemUrl::resolve() . '/admin/addonmodules.php?module=nt_mcp';
    }
}
