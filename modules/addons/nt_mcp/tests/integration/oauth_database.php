<?php
/** Standalone destructive test, ONLY against a disposable nt_mcp_test* database. */
declare(strict_types=1);

require getenv('NT_MCP_DB_VENDOR_AUTOLOAD') ?: '/integration/vendor/autoload.php';
// Standalone Illuminate 8 requires its matching PSR container interface.
class_exists(\Illuminate\Container\Container::class);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use NtMcp\OAuth\Handlers\TokenHandler;
use NtMcp\OAuth\OAuthMigration;
use NtMcp\OAuth\OAuthRevocation;
use NtMcp\OAuth\OAuthTransaction;
use NtMcp\OAuth\RefreshTokenService;

class_alias(Capsule::class, 'WHMCS\\Database\\Capsule');
function logActivity(string $message): void {}
$database = getenv('NT_MCP_TEST_DATABASE') ?: 'nt_mcp_test';
if (!str_starts_with($database, 'nt_mcp_test')) {
    throw new RuntimeException('Refusing a non-test database');
}
$capsule = new Capsule();
$capsule->addConnection([
    'driver' => 'mysql', 'host' => getenv('NT_MCP_TEST_DB_HOST') ?: 'nt-mcp-oauth-db',
    'database' => $database, 'username' => getenv('NT_MCP_TEST_DB_USER') ?: 'root',
    'password' => getenv('NT_MCP_TEST_DB_PASSWORD') ?: '', 'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
]);
$capsule->setAsGlobal();

function check(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}
function grant(array $params): string
{
    return OAuthTransaction::capture(static function () use ($params): void {
        (new ReflectionMethod(TokenHandler::class, 'dispatchGrant'))->invoke(null, $params);
    });
}
function params(): array
{
    return ['grant_type' => 'authorization_code', 'code' => str_repeat('a', 64),
        'client_id' => 'client', 'redirect_uri' => 'https://example.org/callback',
        'code_verifier' => str_repeat('x', 43)];
}
function seed(): void
{
    foreach (['refresh_tokens', 'tokens', 'codes', 'clients'] as $table) {
        Capsule::table('mod_nt_mcp_oauth_' . $table)->delete();
    }
    Capsule::table('mod_nt_mcp_oauth_clients')->insert([
        'client_id' => 'client', 'client_name' => 'Test',
        'redirect_uris' => '["https://example.org/callback"]',
    ]);
    Capsule::table('mod_nt_mcp_oauth_codes')->insert([
        'code' => hash('sha256', str_repeat('a', 64)), 'client_id' => 'client',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('x', 43), true)), '+/', '-_'), '='),
        'redirect_uri' => 'https://example.org/callback', 'expires_at' => time() + 600,
        'used' => false, 'approved_by' => 'reviewer',
    ]);
}
function waitFile(string $path): void
{
    $until = microtime(true) + 15;
    while (!is_file($path)) {
        if (microtime(true) > $until) { throw new RuntimeException('Barrier timeout: ' . basename($path)); }
        usleep(10000);
        clearstatcache(true, $path);
    }
}

$mode = $argv[1] ?? 'suite';
if ($mode === 'holder') {
    $dir = $argv[2];
    OAuthTransaction::capture(static function () use ($dir): void {
        (new ReflectionMethod(TokenHandler::class, 'dispatchGrant'))->invoke(null,
            json_decode(file_get_contents($dir . '/params.json'), true, 512, JSON_THROW_ON_ERROR));
        touch($dir . '/held');
        waitFile($dir . '/release');
    });
    exit(0);
}
if ($mode === 'revoker') {
    $dir = $argv[2];
    touch($dir . '/started');
    if ($argv[3] === 'all') { OAuthRevocation::all(); }
    elseif ($argv[3] === 'client') { OAuthRevocation::client('client'); }
    elseif (str_starts_with($argv[3], 'token:')) { OAuthRevocation::token((int) substr($argv[3], 6)); }
    else { (new RefreshTokenService())->revokeFamily($argv[3]); }
    touch($dir . '/revoked');
    exit(0);
}

check(OAuthMigration::ensureTables(), 'Migration failed');
if (!Capsule::schema()->hasTable('tbladmins')) {
    Capsule::schema()->create('tbladmins', static function ($table): void {
        $table->engine = 'InnoDB'; $table->increments('id');
        $table->string('username'); $table->boolean('disabled')->default(false);
    });
}
Capsule::table('tbladmins')->delete();
Capsule::table('tbladmins')->insert(['username' => 'reviewer', 'disabled' => 0]);

seed();
Capsule::table('mod_nt_mcp_oauth_codes')->update(['approved_by' => null]);
$result = json_decode(grant(params()), true, 512, JSON_THROW_ON_ERROR);
check(($result['error'] ?? '') === 'invalid_grant', 'Pending grant accepted');
check(Capsule::table('mod_nt_mcp_oauth_tokens')->count() === 0, 'Pending grant persisted a token');

seed();
$result = json_decode(grant(params()), true, 512, JSON_THROW_ON_ERROR);
check(isset($result['access_token'], $result['refresh_token']), 'Approved grant failed');
check(Capsule::table('mod_nt_mcp_oauth_tokens')->value('admin_user') === 'reviewer', 'Binding lost');
$replay = json_decode(grant(params()), true, 512, JSON_THROW_ON_ERROR);
check(($replay['error'] ?? '') === 'invalid_grant', 'Code replay accepted');

seed();
Capsule::connection()->unprepared("CREATE TRIGGER reject_refresh BEFORE INSERT ON mod_nt_mcp_oauth_refresh_tokens FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test failure'");
try {
    $failed = false;
    try { grant(params()); } catch (Throwable $e) { $failed = true; }
    check($failed, 'Injected insert failure did not fail');
    check(Capsule::table('mod_nt_mcp_oauth_tokens')->count() === 0, 'Orphan access token after rollback');
    check(!(bool) Capsule::table('mod_nt_mcp_oauth_codes')->value('used'), 'Code consumption survived rollback');
} finally {
    Capsule::connection()->unprepared('DROP TRIGGER reject_refresh');
}

foreach (['all', 'client', 'family', 'token'] as $revoke) {
    seed();
    $initial = json_decode(grant(params()), true, 512, JSON_THROW_ON_ERROR);
    $family = Capsule::table('mod_nt_mcp_oauth_tokens')->value('family_id');
    $dir = sys_get_temp_dir() . '/oauth-race-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    file_put_contents($dir . '/params.json', json_encode([
        'grant_type' => 'refresh_token', 'refresh_token' => $initial['refresh_token'], 'client_id' => 'client',
    ], JSON_THROW_ON_ERROR));
    $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . '/out', 'a'], 2 => ['file', $dir . '/err', 'a']];
    $holder = proc_open([PHP_BINARY, __FILE__, 'holder', $dir], $spec, $pipes);
    waitFile($dir . '/held');
    $target = $revoke === 'family' ? $family : $revoke;
    if ($revoke === 'token') {
        $target = 'token:' . Capsule::table('mod_nt_mcp_oauth_tokens')->value('id');
    }
    $revoker = proc_open([PHP_BINARY, __FILE__, 'revoker', $dir, $target], $spec, $pipes);
    waitFile($dir . '/started');
    usleep(150000);
    check(!is_file($dir . '/revoked'), 'Revoker bypassed the grant transaction');
    touch($dir . '/release');
    check(proc_close($holder) === 0, 'Grant worker failed');
    check(proc_close($revoker) === 0, 'Revoke worker failed');
    check(Capsule::table('mod_nt_mcp_oauth_tokens')->count() === 0, 'Access token survived ' . $revoke);
    check(Capsule::table('mod_nt_mcp_oauth_refresh_tokens')->count() === 0, 'Refresh survived ' . $revoke);
    $after = json_decode(grant(json_decode(file_get_contents($dir . '/params.json'), true, 512, JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
    check(($after['error'] ?? '') === 'invalid_grant', 'Revoked refresh restored a grant');
    foreach (glob($dir . '/*') as $file) { unlink($file); }
    rmdir($dir);
}

seed();
Capsule::connection()->unprepared('ALTER TABLE mod_nt_mcp_oauth_tokens ENGINE=MyISAM');
try {
    $rejected = false;
    try { grant(params()); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, 'Non-transactional storage accepted');
    check(Capsule::table('mod_nt_mcp_oauth_tokens')->count() === 0, 'Token persisted without transactional storage');
} finally {
    Capsule::connection()->unprepared('ALTER TABLE mod_nt_mcp_oauth_tokens ENGINE=InnoDB');
}
echo "PASS: pending denial, approved exchange, replay denial, rollback, concurrent refresh/revoke-all, client removal, family/token revocation, revoked refresh denial, InnoDB enforcement\n";
