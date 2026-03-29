<?php
/**
 * OAuth authorization approval form template.
 * Included from NtMcp\Admin\OAuthApprovalController::handle().
 *
 * Variables in scope: $e, $csrf, $pending, $clientName, $adminId, $minutesLeft
 */
?>
<div class="panel panel-primary">
    <div class="panel-heading">
        <h3 class="panel-title">Autorizar Acesso OAuth MCP</h3>
    </div>
    <div class="panel-body">
        <div class="alert alert-info">
            <i class="fas fa-shield-alt"></i>
            <strong><?= $e($clientName) ?></strong> solicita acesso ao WHMCS MCP Server.
        </div>

        <table class="table table-bordered table-striped">
            <tr>
                <td style="width:150px"><strong>Client ID</strong></td>
                <td><code><?= $e($pending->client_id) ?></code></td>
            </tr>
            <tr>
                <td><strong>Redirect URI</strong></td>
                <td><code><?= $e($pending->redirect_uri) ?></code></td>
            </tr>
            <tr>
                <td><strong>Expira em</strong></td>
                <td><span class="label label-warning"><?= $minutesLeft ?> minuto(s)</span></td>
            </tr>
            <tr>
                <td><strong>Admin</strong></td>
                <td>ID #<?= $adminId ?> (você)</td>
            </tr>
        </table>

        <div class="alert alert-warning">
            <strong>Atenção:</strong> Ao aprovar, o cliente poderá executar ferramentas de
            gerenciamento WHMCS (listar clientes, faturas, tickets, serviços, etc.) via MCP.
        </div>

        <form method="POST" class="text-center">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
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
