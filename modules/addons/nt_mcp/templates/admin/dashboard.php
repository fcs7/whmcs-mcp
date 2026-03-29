<?php
/**
 * Admin dashboard template — Auth management UI.
 * Included from NtMcp\Admin\AdminController::handle().
 *
 * Variables in scope: $e, $mcpUrl, $currentAdminId, $currentAdminName,
 * $tokenHash, $tokenAdmin, $tokenCreated, $flashPlaintext, $flashMessage,
 * $flashClass, $csrf, $oauthTokens, $oauthClients, $hasAdminUserCol, $hasLastUsedAtCol
 */

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
    $staticInfo = '<span class="text-danger">Não configurado</span>';
}

// Token display after regeneration
$tokenSection = '';
if ($flashPlaintext !== '') {
    $tokenSection = '<div class="alert alert-warning">'
        . '<strong>Novo Bearer Token (exibido apenas uma vez):</strong><br>'
        . '<code>' . $e($flashPlaintext) . '</code></div>'
        . '<h5>Configuração Claude Code (~/.claude.json)</h5>'
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
            . ' title="Revogar este token OAuth"'
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
        . ' title="Remover este client e revogar seus tokens"'
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
?>
<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title">NT MCP Server &mdash; Gestão de Autenticação</h3>
    </div>
    <div class="panel-body">
        <?= $flashHtml ?>

        <div class="alert alert-info" style="margin-bottom:20px;">
            <i class="fas fa-user-shield"></i>
            <strong>Administrador logado:</strong> <?= $e($currentAdminName) ?> (ID #<?= $currentAdminId ?>)
        </div>

        <h4>Endpoint MCP</h4>
        <pre><?= $escapedUrl ?></pre>

        <hr>
        <h4>Bearer Token Estático</h4>
        <p><?= $staticInfo ?></p>
        <p class="text-muted"><small>O hash SHA-256 é armazenado no banco. O token plaintext é exibido apenas uma vez ao regenerar.</small></p>
        <?= $tokenSection ?>
        <form method="post" style="margin-bottom:20px;">
            <input type="hidden" name="_csrf_token" value="<?= $escapedCsrf ?>">
            <button type="submit" name="regenerate_token" class="btn btn-warning"
                    onclick="return confirm('Tem certeza? O token atual será invalidado.');">
                Regenerar Token
            </button>
        </form>

        <hr>
        <h4>Tokens OAuth Ativos <span class="badge"><?= $activeCount ?></span> <?= $revokeAllBtn ?></h4>
        <table class="table table-bordered table-striped table-condensed">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Admin</th>
                    <th>Criado em</th>
                    <th>Expira em</th>
                    <th>Último uso</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody><?= $tokensRows ?></tbody>
        </table>

        <hr>
        <h4>Clients OAuth Registrados <span class="badge"><?= $e((string)count($oauthClients)) ?></span></h4>
        <table class="table table-bordered table-striped table-condensed">
            <thead>
                <tr>
                    <th>Client ID</th>
                    <th>Nome</th>
                    <th>Redirect URIs</th>
                    <th>Criado em</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody><?= $clientsRows ?></tbody>
        </table>
    </div>
</div>
