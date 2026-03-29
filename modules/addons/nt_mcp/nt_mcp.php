<?php
/**
 * NT MCP — WHMCS Addon Module
 * Instalar em: modules/addons/nt_mcp/
 * Ativar em: Setup > Addon Modules > NT MCP
 */

if (!defined('WHMCS')) die('Direct access denied.');

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Metadados e configuracoes do addon
 */
function nt_mcp_config(): array
{
    return [
        'name'        => 'NT MCP Server',
        'description' => 'Model Context Protocol server para integrar Claude Code ao WHMCS.',
        'version'     => '1.0.0',
        'author'      => 'NT Web',
        'language'    => 'english',
        'fields'      => [], // Configuracoes gerenciadas na tela _output
    ];
}

/**
 * Executado na ativacao — gera Bearer Token inicial
 *
 * SECURITY (F17): Only the SHA-256 hash of the token is persisted.
 * The plaintext is shown once in the activation success message; after
 * that it cannot be recovered.
 */
function nt_mcp_activate(): array
{
    $token = bin2hex(random_bytes(32)); // 64 chars hex
    $hash  = hash('sha256', $token);
    \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token', $hash);

    // ------------------------------------------------------------------
    // SECURITY (F10): Seed the admin-user config with a safe default.
    // ------------------------------------------------------------------
    if (!trim(\WHMCS\Config\Setting::getValue('nt_mcp_admin_user') ?? '')) {
        \WHMCS\Config\Setting::setValue('nt_mcp_admin_user', 'admin');
    }

    return [
        'status'      => 'success',
        'description' => 'NT MCP ativado. Bearer Token (copie agora, nao sera exibido novamente): ' . $token,
    ];
}

/**
 * Executado na desativacao
 */
function nt_mcp_deactivate(): array
{
    \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token', '');
    return ['status' => 'success', 'description' => 'NT MCP desativado.'];
}

// -----------------------------------------------------------------------
// CSRF helpers (F6)
// -----------------------------------------------------------------------

/**
 * Generate a cryptographic CSRF nonce bound to the current PHP session.
 *
 * The nonce is a HMAC of a random per-session secret and a static purpose
 * string, so it is both unpredictable and tied to the session.
 */
function nt_mcp_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['nt_mcp_csrf_secret'])) {
        $_SESSION['nt_mcp_csrf_secret'] = bin2hex(random_bytes(32));
    }
    return hash_hmac('sha256', 'nt_mcp_csrf', $_SESSION['nt_mcp_csrf_secret']);
}

/**
 * Validate the CSRF nonce submitted with a POST request.
 */
function nt_mcp_csrf_verify(string $submitted): bool
{
    return hash_equals(nt_mcp_csrf_token(), $submitted);
}

/**
 * Tela administrativa do addon — Gestao de Autenticacao
 */
function nt_mcp_output(array $vars): void
{
    // ------------------------------------------------------------------
    // OAuth Authorization approval — if ?authorize=REQUEST_ID is present,
    // show the OAuth approval form instead of the normal admin UI.
    // This runs INSIDE the WHMCS admin panel, so admin session is guaranteed.
    // ------------------------------------------------------------------
    if (isset($_GET['authorize']) && $_GET['authorize'] !== '') {
        nt_mcp_handle_oauth_authorize($vars);
        return;
    }

    // ------------------------------------------------------------------
    // Escape helper — shorthand for htmlspecialchars (F12 XSS fix).
    // ------------------------------------------------------------------
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // ---------------------------------------------------------------
    // SECURITY FIX (9.3 -- F11): Host header injection prevention.
    // ---------------------------------------------------------------
    $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL') ?? '', '/');
    if ($systemUrl === '') {
        try {
            $systemUrl = rtrim(\App::getSystemURL(), '/');
        } catch (\Throwable $_ex) {
            $systemUrl = '(unable to determine - check WHMCS General Settings)';
        }
    }
    $mcpUrl = $systemUrl . '/modules/addons/nt_mcp/mcp.php';

    // ------------------------------------------------------------------
    // Auto-detect logged-in admin (replaces manual text input)
    // ------------------------------------------------------------------
    $currentAdminId = (int) ($_SESSION['adminid'] ?? 0);
    $currentAdminName = 'admin';
    if ($currentAdminId > 0) {
        try {
            $currentAdminName = Capsule::table('tbladmins')
                ->where('id', $currentAdminId)
                ->value('username') ?? 'admin';
        } catch (\Throwable $e) {
            error_log('NT MCP Admin: Failed to look up admin username for ID ' . $currentAdminId . ': ' . $e->getMessage());
        }
    }

    // Static token metadata
    $tokenAdmin   = trim(\WHMCS\Config\Setting::getValue('nt_mcp_bearer_token_admin') ?? '');
    $tokenCreated = trim(\WHMCS\Config\Setting::getValue('nt_mcp_bearer_token_created') ?? '');
    $tokenHash    = trim(\WHMCS\Config\Setting::getValue('nt_mcp_bearer_token') ?? '');

    // ------------------------------------------------------------------
    // Handle POST actions
    // ------------------------------------------------------------------
    $flashPlaintext = '';
    $flashMessage   = '';
    $flashClass     = 'info';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfOk = nt_mcp_csrf_verify($_POST['_csrf_token'] ?? '');

        if (!$csrfOk) {
            $flashMessage = 'Erro: token CSRF invalido. Recarregue a pagina e tente novamente.';
            $flashClass   = 'danger';
        } elseif (isset($_POST['regenerate_token'])) {
            // ---------------------------------------------------------------
            // Regenerate static bearer token — auto-bind to logged-in admin
            // ---------------------------------------------------------------
            $newToken       = bin2hex(random_bytes(32));
            $hash           = hash('sha256', $newToken);
            \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token', $hash);
            \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token_admin', $currentAdminName);
            \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token_created', date('Y-m-d H:i:s'));
            // Also update legacy global config for backward compat
            \WHMCS\Config\Setting::setValue('nt_mcp_admin_user', $currentAdminName);
            $tokenHash    = $hash;
            $tokenAdmin   = $currentAdminName;
            $tokenCreated = date('Y-m-d H:i:s');
            $flashPlaintext = $newToken;
            $flashMessage   = 'Token regenerado com sucesso. Copie-o agora; ele nao sera exibido novamente.';
            $flashClass     = 'success';
            logActivity("NT MCP: Bearer token regenerated by admin ID {$currentAdminId} ({$currentAdminName})");
        } elseif (isset($_POST['revoke_oauth_token'])) {
            // ---------------------------------------------------------------
            // Revoke a single OAuth token
            // ---------------------------------------------------------------
            $tokenId = (int) ($_POST['token_id'] ?? 0);
            if ($tokenId > 0) {
                try {
                    Capsule::table('mod_nt_mcp_oauth_tokens')->where('id', $tokenId)->delete();
                    $flashMessage = 'Token OAuth revogado com sucesso.';
                    $flashClass   = 'success';
                    logActivity("NT MCP: OAuth token ID {$tokenId} revoked by admin ID {$currentAdminId} ({$currentAdminName})");
                } catch (\Throwable $ex) {
                    // SECURITY (F-NEW-01): Log full error, show generic message
                    error_log('NT MCP: Failed to revoke OAuth token: ' . $ex->getMessage());
                    $flashMessage = 'Erro ao revogar token. Verifique o log de erros.';
                    $flashClass   = 'danger';
                }
            }
        } elseif (isset($_POST['revoke_all_oauth_tokens'])) {
            // ---------------------------------------------------------------
            // Revoke ALL OAuth tokens
            // ---------------------------------------------------------------
            try {
                $deleted = Capsule::table('mod_nt_mcp_oauth_tokens')->delete();
                $flashMessage = $deleted . ' token(s) OAuth revogado(s).';
                $flashClass   = 'success';
                logActivity("NT MCP: All OAuth tokens revoked ({$deleted} total) by admin ID {$currentAdminId} ({$currentAdminName})");
            } catch (\Throwable $ex) {
                error_log('NT MCP: Failed to revoke all OAuth tokens: ' . $ex->getMessage());
                $flashMessage = 'Erro ao revogar tokens. Verifique o log de erros.';
                $flashClass   = 'danger';
            }
        } elseif (isset($_POST['remove_oauth_client'])) {
            // ---------------------------------------------------------------
            // Remove an OAuth client and its associated tokens
            // ---------------------------------------------------------------
            $clientIdToRemove = trim($_POST['client_id_remove'] ?? '');
            if ($clientIdToRemove !== '') {
                try {
                    // SECURITY (F-16 fix): Atomic deletion via transaction
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
                    logActivity("NT MCP: OAuth client '{$clientIdToRemove}' removed by admin ID {$currentAdminId} ({$currentAdminName})");
                } catch (\Throwable $ex) {
                    error_log('NT MCP: Failed to remove OAuth client: ' . $ex->getMessage());
                    $flashMessage = 'Erro ao remover client. Verifique o log de erros.';
                    $flashClass   = 'danger';
                }
            }
        }
    }

    // Generate CSRF nonce (F6)
    $csrf = nt_mcp_csrf_token();

    // ------------------------------------------------------------------
    // Load OAuth data for management tables
    // ------------------------------------------------------------------
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
            // SECURITY (F-NEW-02): Use safe addSelect instead of selectRaw
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
    } catch (\Throwable $e) {
        error_log('NT MCP Admin: Failed to load OAuth data: ' . $e->getMessage());
        if ($flashMessage === '') {
            $flashMessage = 'Aviso: Nao foi possivel carregar dados OAuth. Verifique a conexao com o banco.';
            $flashClass   = 'warning';
        }
    }

    // ------------------------------------------------------------------
    // Render
    // ------------------------------------------------------------------
    $escapedUrl      = $e($mcpUrl);
    $escapedCsrf     = $e($csrf);
    $escapedFlash    = $e($flashMessage);
    $escapedFlashCls = $e($flashClass);

    // Static token status
    if ($tokenHash !== '') {
        $staticInfo = 'Ativo';
        if ($tokenAdmin !== '') {
            $staticInfo .= ' &mdash; vinculado a <strong>' . $e($tokenAdmin) . '</strong>';
        }
        if ($tokenCreated !== '') {
            $staticInfo .= ' (criado em ' . $e($tokenCreated) . ')';
        }
    } else {
        $staticInfo = '<span class="text-danger">Nao configurado</span>';
    }

    // Token display after regeneration
    $tokenSection = '';
    if ($flashPlaintext !== '') {
        $tokenSection = '<div class="alert alert-warning">'
            . '<strong>Novo Bearer Token (exibido apenas uma vez):</strong><br>'
            . '<code>' . $e($flashPlaintext) . '</code></div>'
            . '<h5>Configuracao Claude Code (~/.claude.json)</h5>'
            . '<pre>' . $e(json_encode([
                'mcpServers' => [
                    'whmcs-ntweb' => [
                        'type'    => 'http',
                        'url'     => $mcpUrl,
                        'headers' => ['Authorization' => 'Bearer ' . $flashPlaintext],
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
    }

    // Flash message
    $flashHtml = '';
    if ($flashMessage !== '') {
        $flashHtml = "<div class=\"alert alert-{$escapedFlashCls}\">{$escapedFlash}</div>";
    }

    // OAuth tokens table rows
    $tokensRows = '';
    $now = time();
    $activeCount = 0;
    foreach ($oauthTokens as $tok) {
        $isExpired = $tok->expires_at < $now;
        if (!$isExpired) {
            $activeCount++;
        }
        $statusLabel = $isExpired
            ? '<span class="label label-default">Expirado</span>'
            : '<span class="label label-success">Ativo</span>';
        $clientName  = $e($tok->client_name ?: substr($tok->client_id, 0, 16) . '...');
        $adminCol    = $hasAdminUserCol ? $e(($tok->admin_user ?? '') ?: '—') : '—';
        $createdCol  = $e($tok->created_at);
        $expiresCol  = $e(date('Y-m-d H:i:s', $tok->expires_at));
        $lastUsedCol = ($hasLastUsedAtCol && ($tok->last_used_at ?? null))
            ? $e(date('Y-m-d H:i:s', $tok->last_used_at))
            : '<span class="text-muted">Nunca</span>';
        $revokeBtn = '';
        if (!$isExpired) {
            $revokeBtn = '<form method="post" style="display:inline;">'
                . '<input type="hidden" name="_csrf_token" value="' . $escapedCsrf . '">'
                . '<input type="hidden" name="token_id" value="' . (int)$tok->id . '">'
                . '<button type="submit" name="revoke_oauth_token" class="btn btn-xs btn-danger"'
                . ' onclick="return confirm(\'Revogar este token?\');">Revogar</button></form>';
        }
        $tokensRows .= "<tr><td>{$clientName}</td><td>{$adminCol}</td><td>{$createdCol}</td>"
            . "<td>{$expiresCol}</td><td>{$lastUsedCol}</td><td>{$statusLabel}</td><td>{$revokeBtn}</td></tr>";
    }
    if ($tokensRows === '') {
        $tokensRows = '<tr><td colspan="7" class="text-center text-muted">Nenhum token OAuth encontrado.</td></tr>';
    }

    // OAuth clients table rows
    $clientsRows = '';
    foreach ($oauthClients as $cli) {
        $cliId   = $e($cli->client_id);
        $cliName = $e($cli->client_name ?: '—');
        $cliUris = $e($cli->redirect_uris);
        $cliDate = $e($cli->created_at);
        $clientsRows .= '<tr><td><code>' . $e(substr($cli->client_id, 0, 16)) . '...</code></td>'
            . "<td>{$cliName}</td><td><small>{$cliUris}</small></td><td>{$cliDate}</td>"
            . '<td><form method="post" style="display:inline;">'
            . '<input type="hidden" name="_csrf_token" value="' . $escapedCsrf . '">'
            . '<input type="hidden" name="client_id_remove" value="' . $cliId . '">'
            . '<button type="submit" name="remove_oauth_client" class="btn btn-xs btn-danger"'
            . ' onclick="return confirm(\'Remover este client e todos os seus tokens?\');">Remover</button>'
            . '</form></td></tr>';
    }
    if ($clientsRows === '') {
        $clientsRows = '<tr><td colspan="5" class="text-center text-muted">Nenhum client OAuth registrado.</td></tr>';
    }

    $revokeAllBtn = $activeCount > 0
        ? '<form method="post" style="display:inline; margin-left:10px;">'
          . '<input type="hidden" name="_csrf_token" value="' . $escapedCsrf . '">'
          . '<button type="submit" name="revoke_all_oauth_tokens" class="btn btn-xs btn-danger"'
          . ' onclick="return confirm(\'Revogar TODOS os tokens OAuth?\');">Revogar Todos</button></form>'
        : '';

    echo <<<HTML
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">NT MCP Server &mdash; Gestao de Autenticacao</h3>
        </div>
        <div class="panel-body">
            {$flashHtml}

            <div class="alert alert-info" style="margin-bottom:20px;">
                <i class="fas fa-user-shield"></i>
                <strong>Administrador logado:</strong> {$e($currentAdminName)} (ID #{$currentAdminId})
                <span class="text-muted">&mdash; auto-detectado</span>
            </div>

            <h4>Endpoint MCP</h4>
            <pre>{$escapedUrl}</pre>

            <hr>
            <h4>Bearer Token Estatico</h4>
            <p>{$staticInfo}</p>
            <p class="text-muted"><small>O hash SHA-256 e armazenado no banco. O token plaintext e exibido apenas uma vez ao regenerar.</small></p>
            {$tokenSection}
            <form method="post" style="margin-bottom:20px;">
                <input type="hidden" name="_csrf_token" value="{$escapedCsrf}">
                <button type="submit" name="regenerate_token" class="btn btn-warning"
                        onclick="return confirm('Tem certeza? O token atual sera invalidado e o novo sera vinculado a sua conta ({$e($currentAdminName)}).');">
                    Regenerar Token (vincular a {$e($currentAdminName)})
                </button>
            </form>

            <hr>
            <h4>Tokens OAuth Ativos <span class="badge">{$activeCount}</span> {$revokeAllBtn}</h4>
            <table class="table table-bordered table-striped table-condensed">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Admin</th>
                        <th>Criado em</th>
                        <th>Expira em</th>
                        <th>Ultimo uso</th>
                        <th>Status</th>
                        <th>Acoes</th>
                    </tr>
                </thead>
                <tbody>{$tokensRows}</tbody>
            </table>

            <hr>
            <h4>Clients OAuth Registrados <span class="badge">{$e((string)count($oauthClients))}</span></h4>
            <table class="table table-bordered table-striped table-condensed">
                <thead>
                    <tr>
                        <th>Client ID</th>
                        <th>Nome</th>
                        <th>Redirect URIs</th>
                        <th>Criado em</th>
                        <th>Acoes</th>
                    </tr>
                </thead>
                <tbody>{$clientsRows}</tbody>
            </table>
        </div>
    </div>
    HTML;
}

// =========================================================================
// OAuth Authorization Approval (runs inside WHMCS admin panel)
// =========================================================================

/**
 * Handle OAuth authorization approval inside the WHMCS admin panel.
 *
 * SECURITY (V-03 fix — CRITICAL CVSS 9.1): This function runs ONLY inside
 * the WHMCS admin panel where admin session is guaranteed by WHMCS itself.
 *
 * Defense in depth (5 layers):
 *  1. WHMCS requires admin login to access configaddonmods.php (native)
 *  2. Explicit $_SESSION['adminid'] verification (belt-and-suspenders)
 *  3. CSRF token tied to admin session via HMAC (anti-forgery)
 *  4. Pending request expires in 10 minutes (temporal window)
 *  5. Admin ID + IP logged in WHMCS activity log (audit trail)
 */
function nt_mcp_handle_oauth_authorize(array $vars): void
{
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Layer 2: Explicit admin session check (belt-and-suspenders)
    if (empty($_SESSION['adminid']) || !is_numeric($_SESSION['adminid'])) {
        echo '<div class="alert alert-danger">';
        echo '<strong>Acesso negado.</strong> Sessao de administrador invalida.';
        echo '</div>';
        return;
    }
    $adminId = (int) $_SESSION['adminid'];

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

    // ------------------------------------------------------------------
    // Handle POST: admin clicked Approve or Deny
    // ------------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['authorize_action'])) {
        // Layer 3: CSRF validation
        if (!nt_mcp_csrf_verify($_POST['_csrf_token'] ?? '')) {
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
        $clientIp = function_exists('_oauthGetClientIp') ? _oauthGetClientIp() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

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
        } catch (\Throwable $e) {
            error_log('NT MCP OAuth: Failed to look up admin username for ID ' . $adminId . ': ' . $e->getMessage());
        }

        // SECURITY FIX (S2A-01): Store hash, not plaintext — $authCode sent to
        // client via redirect, only the hash is persisted in the database.
        // SECURITY FIX (F7 -- audit): Wrap in try/catch so a DB failure after
        // consuming the pending request does not leave the flow silently broken.
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
            // Add approved_by if column exists (post-migration)
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
        return;
    }

    // ------------------------------------------------------------------
    // Render approval form
    // ------------------------------------------------------------------
    $csrf = nt_mcp_csrf_token();
    $timeLeft = $pending->expires_at - time();
    $minutesLeft = max(1, (int) ceil($timeLeft / 60));

    echo <<<HTML
    <div class="panel panel-primary">
        <div class="panel-heading">
            <h3 class="panel-title">Autorizar Acesso OAuth MCP</h3>
        </div>
        <div class="panel-body">
            <div class="alert alert-info">
                <i class="fas fa-shield-alt"></i>
                <strong>{$e($clientName)}</strong> solicita acesso ao WHMCS MCP Server.
            </div>

            <table class="table table-bordered table-striped">
                <tr>
                    <td style="width:150px"><strong>Client ID</strong></td>
                    <td><code>{$e($pending->client_id)}</code></td>
                </tr>
                <tr>
                    <td><strong>Redirect URI</strong></td>
                    <td><code>{$e($pending->redirect_uri)}</code></td>
                </tr>
                <tr>
                    <td><strong>Expira em</strong></td>
                    <td><span class="label label-warning">{$minutesLeft} minuto(s)</span></td>
                </tr>
                <tr>
                    <td><strong>Admin</strong></td>
                    <td>ID #{$adminId} (voce)</td>
                </tr>
            </table>

            <div class="alert alert-warning">
                <strong>Atencao:</strong> Ao aprovar, o cliente podera executar ferramentas de
                gerenciamento WHMCS (listar clientes, faturas, tickets, servicos, etc.) via MCP.
            </div>

            <form method="POST" class="text-center">
                <input type="hidden" name="_csrf_token" value="{$e($csrf)}">
                <input type="hidden" name="authorize_action" value="">
                <button type="submit" name="authorize_action" value="deny"
                        class="btn btn-default btn-lg" style="margin-right:15px;">
                    Negar
                </button>
                <button type="submit" name="authorize_action" value="approve"
                        class="btn btn-success btn-lg">
                    Aprovar Acesso MCP
                </button>
            </form>
        </div>
    </div>
    HTML;
}
