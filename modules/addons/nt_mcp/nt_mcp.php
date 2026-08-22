<?php
/**
 * NT MCP — WHMCS Addon Module
 * Instalar em: modules/addons/nt_mcp/
 * Ativar em: Setup > Addon Modules > NT MCP
 */

if (!defined('WHMCS')) die('Direct access denied.');

require_once __DIR__ . '/vendor/autoload.php';

use NtMcp\Admin\AdminController;
use NtMcp\Admin\OAuthApprovalController;
use NtMcp\OAuth\OAuthMigration;
use NtMcp\Security\CsrfProtection;
use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\ActivityLog;
use NtMcp\Whmcs\Diagnostics;
use NtMcp\Whmcs\DiagnosticsKeyStore;

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
    // FASE 4b (6A): guarda de ordem de autoload. Se vendor/autoload.php não
    // carregou antes do init.php do WHMCS, as classes do SDK mcp/sdk
    // (psr/log v3) não resolvem e o runtime falha silenciosamente. Detecta na
    // ativação, onde o erro é visível ao operador.
    if (!class_exists(\Mcp\Server::class)) {
        return [
            'status'      => 'error',
            'description' => 'NT MCP: autoloader nao inicializado corretamente '
                . '(vendor/autoload.php deve carregar ANTES de init.php). Ativacao abortada.',
        ];
    }

    // FASE 4c (9.3): o servidor grava sessoes + cache de discovery em data/. Se o
    // diretorio nao for gravavel, o addon fica inoperante em runtime (HTTP 500
    // sem contexto). Valida agora, na ativacao, em vez de por request.
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0700, true);
    }
    foreach (['cache', 'sessions'] as $sub) {
        if (!is_dir($dataDir . '/' . $sub)) {
            @mkdir($dataDir . '/' . $sub, 0700, true);
        }
    }
    if (!is_dir($dataDir) || !is_writable($dataDir)) {
        return [
            'status'      => 'error',
            'description' => 'NT MCP: diretorio data/ nao gravavel (' . $dataDir . '). '
                . 'Ajuste as permissoes e reative.',
        ];
    }

    $token = bin2hex(random_bytes(32)); // 64 chars hex
    $hash  = hash('sha256', $token);
    \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token', $hash);

    // SECURITY (F10): Seed the admin-user config with a safe default.
    if (!trim(\WHMCS\Config\Setting::getValue('nt_mcp_admin_user') ?? '')) {
        \WHMCS\Config\Setting::setValue('nt_mcp_admin_user', 'admin');
    }

    nt_mcp_provision_diagnostics_key();

    return [
        'status'      => 'success',
        'description' => 'NT MCP ativado. Bearer Token (copie agora, nao sera exibido novamente): ' . $token,
    ];
}

/**
 * D10 — chave HMAC do fingerprint de diagnóstico, por instalação.
 *
 * Gera 32 bytes e persiste SOMENTE quando ausente. Qualquer valor existente,
 * válido ou inválido, nunca é rotacionado em silêncio: uma chave inválida faz
 * o runtime omitir fingerprint até reparo explícito.
 *
 * A chave não é devolvida nem exibida em lugar nenhum — diferente do bearer
 * token, o operador não precisa dela; só o processo precisa. Dev e produção
 * geram chaves distintas naturalmente, porque cada instalação ativa a sua.
 *
 * Se o RNG falhar, NÃO há fallback: o fingerprint simplesmente é omitido (ver
 * `Diagnostics`). Derivar de path/PID/horário produziria um valor previsível,
 * que é pior que não ter fingerprint nenhum.
 */
function nt_mcp_provision_diagnostics_key(?callable $generator = null): ?string
{
    try {
        $candidate = $generator !== null
            ? $generator()
            : Diagnostics::generateKey();
    } catch (\Throwable) {
        Diagnostics::resetFingerprintKey();
        return null;
    }

    $winner = is_string($candidate) ? DiagnosticsKeyStore::claim($candidate) : null;
    Diagnostics::setFingerprintKey($winner);

    return $winner;
}

/**
 * Executado na desativacao
 */
function nt_mcp_deactivate(): array
{
    // A chave de diagnóstico NÃO é apagada aqui: desativar e reativar o addon
    // não deve invalidar o agrupamento histórico de incidentes no log.
    \WHMCS\Config\Setting::setValue('nt_mcp_bearer_token', '');
    return ['status' => 'success', 'description' => 'NT MCP desativado.'];
}

/**
 * Executado pelo WHMCS quando a versao do addon muda — roda migracoes de schema.
 */
function nt_mcp_upgrade(array $vars): array
{
    $version = $vars['version'] ?? 'unknown';

    // FASE 2/4c (9.4): invalida o cache de elementos (tool registry). O cache é
    // persistido SEM TTL (nunca expira), então uma versão nova com tools
    // alteradas precisa forçar novo discovery — senão o servidor continuaria
    // servindo o registro antigo. Deletar o arquivo também zera o estado de
    // sessão (compartilha o mesmo arquivo); aceitável no upgrade — clientes
    // reinicializam e o próximo request re-descobre e regrava.
    foreach (['mcp_elements.json', 'mcp_state.json' /* legado php-mcp/server */] as $cacheFile) {
        $registryCache = __DIR__ . '/data/cache/' . $cacheFile;
        if (is_file($registryCache)) {
            @unlink($registryCache);
        }
    }

    // D10: instalações que já existiam antes desta versão não têm a chave;
    // provisiona no upgrade também. Chave válida existente é preservada.
    nt_mcp_provision_diagnostics_key();

    if (!OAuthMigration::ensureTables()) {
        ActivityLog::record(ActivityEvent::ADDON_MIGRATION_FAILED);
        return ['status' => 'error', 'description' => 'NT MCP: falha na migracao de schema. Verifique o log de erros do PHP.'];
    }
    ActivityLog::record(ActivityEvent::ADDON_MIGRATION_OK);
    return ['status' => 'success', 'description' => 'NT MCP schema atualizado.'];
}

// -----------------------------------------------------------------------
// CSRF helpers (F6) — delegated to NtMcp\Security\CsrfProtection
// -----------------------------------------------------------------------
function nt_mcp_csrf_token(): string { return CsrfProtection::token(); }
function nt_mcp_csrf_verify(string $submitted): bool { return CsrfProtection::verify($submitted); }

/**
 * Tela administrativa do addon.
 * Delegates to AdminController (dashboard) or OAuthApprovalController (OAuth approval).
 */
function nt_mcp_output(array $vars): void
{
    if (isset($_GET['authorize']) && $_GET['authorize'] !== '') {
        (new OAuthApprovalController())->handle($vars);
        return;
    }

    (new AdminController())->handle($vars);
}
