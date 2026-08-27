<?php
/**
 * Admin dashboard template — Auth management UI.
 * Included from NtMcp\Admin\AdminController::handle().
 *
 * Variables in scope: $e, $mcpUrl, $currentAdminId, $currentAdminName,
 * $tokenHash, $tokenAdmin, $tokenCreated, $flashPlaintext, $flashMessage,
 * $flashClass, $csrf, $oauthTokens, $oauthClients, $hasAdminUserCol, $hasLastUsedAtCol,
 * $gateToggles (chave => ConfigFlag), $gateAllowlists (chave => CSV cru)
 */

use NtMcp\Whmcs\ConfigFlag;

$escapedUrl      = $e($mcpUrl);
$escapedCsrf     = $e($csrf);
$escapedFlash    = $e($flashMessage);
$escapedFlashCls = $e($flashClass);

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
$expiredCount = 0;
foreach ($oauthTokens as $tok) {
    $isExpired = $tok->expires_at <= $now;
    if ($isExpired) {
        $expiredCount++;
    } else {
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
$cleanExpiredBtn = $expiredCount > 0
    ? '<form method="post" style="display:inline; margin-left:10px;">'
      . '<input type="hidden" name="_csrf_token" value="' . $escapedCsrf . '">'
      . '<button type="submit" name="clean_expired_oauth_tokens" class="btn btn-xs btn-warning"'
      . ' title="Remover permanentemente somente tokens OAuth expirados"'
      . ' onclick="return confirm(\'Remover permanentemente todos os tokens OAuth expirados?\');">'
      . 'Limpar expirados (' . $expiredCount . ')</button></form>'
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
        <p class="text-muted"><small>O hash SHA-256 é armazenado no banco. O token plaintext é exibido apenas uma vez ao regenerar.</small></p>
        <?= $tokenSection ?>
        <table class="table table-bordered table-striped table-condensed" style="margin-bottom:20px;">
            <thead>
                <tr>
                    <th>Admin</th>
                    <th>Criado em</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
<?php if ($tokenHash !== ''): ?>
                <tr>
                    <td><?= $e($tokenAdmin ?: '—') ?></td>
                    <td><?= $e($tokenCreated ?: '—') ?></td>
                    <td><span class="label label-success">Ativo</span></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="_csrf_token" value="<?= $escapedCsrf ?>">
                            <button type="submit" name="regenerate_token" class="btn btn-xs btn-warning"
                                    onclick="return confirm('Tem certeza? O token atual será invalidado.');">Regenerar</button>
                        </form>
                    </td>
                </tr>
<?php else: ?>
                <tr>
                    <td colspan="4" class="text-center text-muted">Nenhum token configurado.</td>
                </tr>
                <tr>
                    <td colspan="4" class="text-center">
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="_csrf_token" value="<?= $escapedCsrf ?>">
                            <button type="submit" name="regenerate_token" class="btn btn-xs btn-success">Gerar Token</button>
                        </form>
                    </td>
                </tr>
<?php endif; ?>
            </tbody>
        </table>

        <hr>
        <h4>Tokens OAuth Ativos <span class="badge"><?= $activeCount ?></span> <?= $revokeAllBtn ?><?= $cleanExpiredBtn ?></h4>
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

        <hr>
        <h4>Gates de Efeito Colateral</h4>
        <p class="text-muted"><small>
            Controlam quais classes de tools o servidor MCP aceita. Tudo que não for READ nasce
            <strong>bloqueado</strong>; ligar aqui grava o opt-in canônico (<code>1</code>) em
            <code>tblconfiguration</code>. "Somente leitura" ligado sobrepõe qualquer gate individual.
        </small></p>
<?php
$gateLabels = [
    'nt_mcp_readonly'           => ['Somente leitura (master)', 'Bloqueia TODAS as classes de escrita, mesmo com gate individual ligado.'],
    'nt_mcp_enable_write'       => ['WRITE', 'Criar/atualizar registros: tickets, projetos, contatos, chips, quotes (update).'],
    'nt_mcp_enable_destructive' => ['DESTRUCTIVE', 'Excluir/cancelar: delete_quote, cancel_order.'],
    'nt_mcp_enable_financial'   => ['FINANCIAL', 'Efeito financeiro: convert_quote_to_invoice (gera fatura).'],
    'nt_mcp_enable_cost'        => ['COST', 'Comandos com custo externo.'],
    'nt_mcp_enable_comms'       => ['COMMS', 'Comandos que disparam comunicação ao cliente.'],
];
$gateRows = '';
foreach ($gateToggles as $gateKey => $gateFlag) {
    [$gateLabel, $gateDesc] = $gateLabels[$gateKey] ?? [$gateKey, ''];
    $stateBadge = match ($gateFlag) {
        ConfigFlag::On      => '<span class="label label-success">Ligado</span>',
        ConfigFlag::Off     => '<span class="label label-default">Desligado</span>',
        ConfigFlag::Absent  => '<span class="label label-default">Desligado (default)</span>',
        ConfigFlag::Invalid => '<span class="label label-danger">Valor inválido — fail-closed</span>',
    };
    // Estado EFETIVO, não o cru: readonly é a única flag fail-closed — Invalid
    // conta como LIGADO, e o checkbox precisa refletir isso (um Save então
    // canonicaliza 'true'→'1' em vez de derrubar o master switch pra '0').
    $effectiveOn = $gateFlag === ConfigFlag::On
        || ($gateKey === 'nt_mcp_readonly' && $gateFlag === ConfigFlag::Invalid);
    $checked = $effectiveOn ? ' checked' : '';
    $gateRows .= '<tr>'
        . '<td><label style="font-weight:normal; margin:0;">'
        . '<input type="checkbox" name="gate[' . $e($gateKey) . ']" value="1"' . $checked . '> '
        . '<strong>' . $e($gateLabel) . '</strong></label></td>'
        . '<td>' . $stateBadge . '</td>'
        . '<td><small class="text-muted">' . $e($gateDesc) . '</small></td>'
        . '</tr>';
}
$clientsAllow = $e($gateAllowlists['nt_mcp_write_allowlist_clientids'] ?? '');
$ticketsAllow = $e($gateAllowlists['nt_mcp_write_allowlist_ticketids'] ?? '');
?>
<?php if ($gateToggles !== []): ?>
        <form method="post">
            <input type="hidden" name="_csrf_token" value="<?= $escapedCsrf ?>">
            <table class="table table-bordered table-condensed" style="margin-bottom:10px;">
                <thead>
                    <tr><th style="width:30%;">Gate</th><th style="width:20%;">Estado atual</th><th>Libera</th></tr>
                </thead>
                <tbody><?= $gateRows ?></tbody>
            </table>
            <div class="row" style="margin-bottom:10px;">
                <div class="col-md-6">
                    <label for="nt-mcp-allow-clients">Allowlist de clientes (ids, CSV)</label>
                    <input type="text" class="form-control" id="nt-mcp-allow-clients"
                           name="nt_mcp_write_allowlist_clientids" value="<?= $clientsAllow ?>"
                           placeholder="ex: 31,42 — vazio = sem restrição de alvo">
                </div>
                <div class="col-md-6">
                    <label for="nt-mcp-allow-tickets">Allowlist de tickets (ids, CSV)</label>
                    <input type="text" class="form-control" id="nt-mcp-allow-tickets"
                           name="nt_mcp_write_allowlist_ticketids" value="<?= $ticketsAllow ?>"
                           placeholder="ex: 30 — vazio = sem restrição de alvo">
                </div>
            </div>
            <p class="text-muted"><small>
                Allowlist preenchida: comandos de escrita só atingem os ids listados; qualquer outro alvo é
                negado (<code>write_target_not_allowed</code>). Toda alteração é registrada no Activity Log.
            </small></p>
            <button type="submit" name="save_gate_config" value="1" class="btn btn-primary"
                    onclick="return confirm('Salvar configuração dos gates?');">Salvar gates</button>
        </form>
<?php else: ?>
        <?php /* Estado de gate ilegível: salvar aqui gravaria '0' em tudo e
                 apagaria allowlists — sem estado carregado, sem botão. */ ?>
        <div class="alert alert-warning">
            Não foi possível carregar o estado dos gates. Recarregue a página antes de qualquer alteração.
        </div>
<?php endif; ?>
    </div>
</div>
