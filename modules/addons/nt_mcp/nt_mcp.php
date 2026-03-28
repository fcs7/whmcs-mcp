<?php
/**
 * NT MCP — WHMCS Addon Module
 * Instalar em: modules/addons/nt_mcp/
 * Ativar em: Setup > Addon Modules > NT MCP
 */

if (!defined('WHMCS')) die('Direct access denied.');

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
