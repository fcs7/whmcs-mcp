<?php

declare(strict_types=1);

namespace NtMcp\Tests\OAuth;

use NtMcp\OAuth\OAuthMigration;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class OAuthMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        class_alias(MigrationCapsule::class, 'Illuminate\\Database\\Capsule\\Manager');
        MigrationCapsule::$schema = new MigrationSchema();
        OAuthMigration::resetForTesting();
        ini_set('error_log', '/dev/null');
    }

    public function testFreshInstallationIncludesRevocableAccessTokenFamily(): void
    {
        self::assertTrue(OAuthMigration::ensureTables());
        self::assertTrue(MigrationCapsule::$schema->hasColumn('mod_nt_mcp_oauth_tokens', 'family_id'));
        self::assertTrue(MigrationCapsule::$schema->hasColumn('mod_nt_mcp_oauth_codes', 'approved_by'));
        self::assertTrue(MigrationCapsule::$schema->hasColumn('mod_nt_mcp_oauth_refresh_tokens', 'used_at'));
    }

    public function testFailedMigrationIsNotCachedAsSuccessful(): void
    {
        MigrationCapsule::$schema->fail = true;
        self::assertFalse(OAuthMigration::ensureTables());
        self::assertFalse(OAuthMigration::ensureTables());
        MigrationCapsule::$schema->fail = false;
        self::assertTrue(OAuthMigration::ensureTables());
        self::assertTrue(MigrationCapsule::$schema->hasColumn('mod_nt_mcp_oauth_tokens', 'family_id'));
    }

    public function testExistingInstallationGetsMissingSecurityColumns(): void
    {
        MigrationCapsule::$schema->tables = [
            'mod_nt_mcp_oauth_clients' => [], 'mod_nt_mcp_oauth_codes' => [],
            'mod_nt_mcp_oauth_tokens' => [], 'mod_nt_mcp_oauth_refresh_tokens' => [],
        ];
        self::assertTrue(OAuthMigration::ensureTables());
        self::assertTrue(MigrationCapsule::$schema->hasColumn('mod_nt_mcp_oauth_tokens', 'family_id'));
        self::assertTrue(MigrationCapsule::$schema->hasColumn('mod_nt_mcp_oauth_tokens', 'admin_user'));
        self::assertTrue(MigrationCapsule::$schema->hasColumn('mod_nt_mcp_oauth_codes', 'approved_by'));
        self::assertTrue(MigrationCapsule::$schema->hasColumn('mod_nt_mcp_oauth_refresh_tokens', 'used_at'));
    }
}

final class MigrationCapsule
{
    public static MigrationSchema $schema;
    public static function schema(): MigrationSchema { return self::$schema; }
}

final class MigrationSchema
{
    public array $tables = [];
    public bool $fail = false;
    public function hasTable(string $name): bool
    {
        if ($this->fail) { throw new \RuntimeException('Database unavailable'); }
        return array_key_exists($name, $this->tables);
    }
    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->tables[$table] ?? [], true);
    }
    public function create(string $table, callable $callback): void
    {
        $this->tables[$table] = [];
        $this->table($table, $callback);
    }
    public function table(string $table, callable $callback): void
    {
        $callback(new MigrationBlueprint($this, $table));
    }
}

final class MigrationBlueprint
{
    public string $engine;
    public function __construct(private MigrationSchema $schema, private string $table) {}
    public function __call(string $method, array $args): self
    {
        if (in_array($method, ['increments', 'string', 'text', 'timestamp', 'integer', 'boolean'], true)) {
            $this->schema->tables[$this->table][] = $args[0];
        }
        return $this;
    }
}
