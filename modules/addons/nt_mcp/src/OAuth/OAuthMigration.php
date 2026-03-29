<?php

declare(strict_types=1);

namespace NtMcp\OAuth;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Ensures OAuth database tables exist (lazy creation/migration).
 * Safe to call on every request — all operations are idempotent.
 */
final class OAuthMigration
{
    public static function ensureTables(): void
    {
        // SECURITY FIX (F-10): Wrap migration — called every OAuth request,
        // so failures must not propagate and break all OAuth endpoints.
        try {
            $schema = Capsule::schema();

            if (!$schema->hasTable('mod_nt_mcp_oauth_clients')) {
                $schema->create('mod_nt_mcp_oauth_clients', function ($t) {
                    $t->increments('id');
                    $t->string('client_id', 64)->unique();
                    $t->string('client_name', 255)->nullable();
                    $t->text('redirect_uris');
                    $t->timestamp('created_at')->useCurrent();
                });
            }

            if (!$schema->hasTable('mod_nt_mcp_oauth_codes')) {
                $schema->create('mod_nt_mcp_oauth_codes', function ($t) {
                    $t->increments('id');
                    $t->string('code', 128)->unique();
                    $t->string('client_id', 64);
                    $t->string('code_challenge', 128);
                    $t->string('redirect_uri', 2048);
                    $t->string('state', 255)->nullable();
                    $t->integer('expires_at');
                    $t->boolean('used')->default(false);
                    $t->timestamp('created_at')->useCurrent();
                });
            }

            if (!$schema->hasTable('mod_nt_mcp_oauth_tokens')) {
                $schema->create('mod_nt_mcp_oauth_tokens', function ($t) {
                    $t->increments('id');
                    $t->string('token_hash', 64)->unique();
                    $t->string('client_id', 64);
                    $t->integer('expires_at');
                    $t->string('admin_user', 255)->nullable();
                    $t->integer('last_used_at')->nullable();
                    $t->timestamp('created_at')->useCurrent();
                });
            } else {
                // Idempotent migration for existing installations
                if (!$schema->hasColumn('mod_nt_mcp_oauth_tokens', 'admin_user')) {
                    $schema->table('mod_nt_mcp_oauth_tokens', function ($t) {
                        $t->string('admin_user', 255)->nullable()->after('expires_at');
                    });
                }
                if (!$schema->hasColumn('mod_nt_mcp_oauth_tokens', 'last_used_at')) {
                    $schema->table('mod_nt_mcp_oauth_tokens', function ($t) {
                        $t->integer('last_used_at')->nullable()->after('admin_user');
                    });
                }
            }

            // Add approved_by to codes table (for propagating admin to tokens)
            if ($schema->hasTable('mod_nt_mcp_oauth_codes')
                && !$schema->hasColumn('mod_nt_mcp_oauth_codes', 'approved_by')) {
                $schema->table('mod_nt_mcp_oauth_codes', function ($t) {
                    $t->string('approved_by', 255)->nullable()->after('used');
                });
            }
        } catch (\Throwable $e) {
            error_log('NT MCP: OAuth migration failed: ' . $e->getMessage());
        }
    }
}
