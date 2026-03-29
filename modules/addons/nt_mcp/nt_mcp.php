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
 * Tela administrativa do addon
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
    // $_SERVER['HTTP_HOST'] is attacker-controlled.  Derive the URL
    // from WHMCS's own configured System URL instead.
    // ---------------------------------------------------------------
    $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL') ?? '', '/');
    if ($systemUrl === '') {
        // Fallback: use App helper if available (WHMCS 7.x+)
        try {
            $systemUrl = rtrim(\App::getSystemURL(), '/');
        } catch (\Throwable $_ex) {
            $systemUrl = '(unable to determine - check WHMCS General Settings)';
        }
    }
    $mcpUrl = $systemUrl . '/modules/addons/nt_mcp/mcp.php';

    // Current admin-user config (F10)
    $adminUser = trim(\WHMCS\Config\Setting::getValue('nt_mcp_admin_user') ?? '') ?: 'admin';

    // ------------------------------------------------------------------
    // Handle POST actions (token regeneration, admin-user update)
    // ------------------------------------------------------------------
    $flashPlaintext = '';       // Will hold plaintext token ONLY on regeneration
    $flashMessage   = '';       // Success/error feedback
    $flashClass     = 'info';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfOk = nt_mcp_csrf_verify($_POST['_csrf_token'] ?? '');

        if (!$csrfOk) {
            // ---------------------------------------------------------------
            // SECURITY (F6): Block requests with missing/invalid CSRF token.
            // ---------------------------------------------------------------
            $flashMessage = 'Erro: token CSRF invalido. Recarregue a pagina e tente novamente.';
            $flashClass   = 'danger';
        } elseif (isset($_POST['regenerate_token'])) {
            // ---------------------------------------------------------------
            // SECURITY (F17): Store only the SHA-256 hash. Show the plaintext
            // exactly once in this response.
            // ---------------------------------------------------------------
            $newToken       = bin2hex(random_bytes(32));
            $hash           = hash('sha256', $newToken);
            \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token', $hash);
            $flashPlaintext = $newToken;
            $flashMessage   = 'Token regenerado com sucesso. Copie-o agora; ele nao sera exibido novamente.';
            $flashClass     = 'success';
        } elseif (isset($_POST['save_admin_user'])) {
            // ---------------------------------------------------------------
            // SECURITY (F10): Persist configurable admin user.
            // ---------------------------------------------------------------
            $newAdmin = trim($_POST['admin_user'] ?? '');
            if ($newAdmin === '' || !preg_match('/^[a-zA-Z0-9_.\-@]+$/', $newAdmin)) {
                $flashMessage = 'Nome de admin invalido. Use apenas letras, numeros, _, ., - ou @.';
                $flashClass   = 'danger';
            } else {
                \WHMCS\Config\Setting::setValue('nt_mcp_admin_user', $newAdmin);
                $adminUser    = $newAdmin;
                $flashMessage = 'Admin user atualizado para: ' . $newAdmin;
                $flashClass   = 'success';
            }
        }
    }

    // Generate CSRF nonce for the forms rendered below (F6)
    $csrf = nt_mcp_csrf_token();

    // ------------------------------------------------------------------
    // Render — every dynamic value is escaped (F12)
    // ------------------------------------------------------------------
    $escapedUrl       = $e($mcpUrl);
    $escapedAdmin     = $e($adminUser);
    $escapedCsrf      = $e($csrf);
    $escapedFlash     = $e($flashMessage);
    $escapedFlashCls  = $e($flashClass);

    // Token display section
    if ($flashPlaintext !== '') {
        $tokenSection = '<div class="alert alert-warning">'
            . '<strong>Novo Bearer Token (exibido apenas uma vez):</strong><br>'
            . '<code>' . $e($flashPlaintext) . '</code></div>'
            . '<h4>Configuracao Claude Code (~/.claude.json)</h4>'
            . '<pre>' . $e(json_encode([
                'mcpServers' => [
                    'whmcs-ntweb' => [
                        'type'    => 'http',
                        'url'     => $mcpUrl,
                        'headers' => ['Authorization' => 'Bearer ' . $flashPlaintext],
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
    } else {
        $tokenSection = '<p class="text-muted">O token e armazenado como hash SHA-256. '
            . 'Clique em &quot;Regenerar Token&quot; para gerar um novo e visualiza-lo uma unica vez.</p>';
    }

    // Flash message bar
    $flashHtml = '';
    if ($flashMessage !== '') {
        $flashHtml = "<div class=\"alert alert-{$escapedFlashCls}\">{$escapedFlash}</div>";
    }

    echo <<<HTML
    <div class="panel panel-default">
        <div class="panel-heading"><h3 class="panel-title">NT MCP Server &mdash; Configuracao</h3></div>
        <div class="panel-body">
            {$flashHtml}

            <h4>Endpoint MCP</h4>
            <pre>{$escapedUrl}</pre>

            <h4>Bearer Token</h4>
            {$tokenSection}

            <form method="post" style="margin-bottom:20px;">
                <input type="hidden" name="_csrf_token" value="{$escapedCsrf}">
                <button type="submit" name="regenerate_token" class="btn btn-warning"
                        onclick="return confirm('Tem certeza? O token atual sera invalidado.');">
                    Regenerar Token
                </button>
            </form>

            <hr>
            <h4>Admin User para API Local (F10)</h4>
            <form method="post" class="form-inline">
                <input type="hidden" name="_csrf_token" value="{$escapedCsrf}">
                <div class="form-group">
                    <label for="admin_user" class="sr-only">Admin User</label>
                    <input type="text" id="admin_user" name="admin_user"
                           class="form-control" value="{$escapedAdmin}"
                           pattern="[a-zA-Z0-9_.@\-]+" required
                           title="Letras, numeros, _, ., - ou @">
                </div>
                <button type="submit" name="save_admin_user" class="btn btn-primary">
                    Salvar Admin User
                </button>
            </form>
            <p class="help-block">
                Usuario WHMCS admin utilizado nas chamadas <code>localAPI()</code>.
                Deve corresponder a um administrador ativo.
            </p>
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

        if ($_POST['authorize_action'] !== 'approve') {
            // DENIED
            logActivity("NT MCP: OAuth authorization DENIED for client '{$clientName}' by admin ID {$adminId} from IP {$_SERVER['REMOTE_ADDR']}");

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

        // SECURITY FIX (S2A-01): Store hash, not plaintext — $authCode sent to
        // client via redirect, only the hash is persisted in the database.
        Capsule::table('mod_nt_mcp_oauth_codes')->insert([
            'code'           => hash('sha256', $authCode),
            'client_id'      => $pending->client_id,
            'code_challenge'  => $pending->code_challenge,
            'redirect_uri'   => $redirectUri,
            'state'          => $state,
            'expires_at'     => time() + 300, // 5 minutes
            'used'           => false,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        // Layer 5: Audit trail
        logActivity("NT MCP: OAuth authorization APPROVED for client '{$clientName}' (client_id: {$pending->client_id}) by admin ID {$adminId} from IP {$_SERVER['REMOTE_ADDR']}");

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
