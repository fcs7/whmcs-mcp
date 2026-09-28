<?php

declare(strict_types=1);

namespace NtMcp\OAuth;

use NtMcp\Whmcs\Diagnostics;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Ensures OAuth database tables exist (lazy creation/migration).
 * Safe to call on every request — all operations are idempotent.
 */
final class OAuthMigration
{
    /** Prevents redundant information_schema queries within a single request. */
    private static bool $ensured = false;

    /**
     * Resets the in-request cache. Call in PHPUnit tearDown() to prevent
     * static state from leaking between test cases.
     */
    public static function resetForTesting(): void
    {
        self::$ensured = false;
    }

    /**
     * Ensures all OAuth tables exist, running CREATE/ALTER as needed.
     * Returns true on success, false if the migration failed (error is
     * written to the PHP error log).
     */
    public static function ensureTables(): bool
    {
        if (self::$ensured) {
            return true;
        }

        // SECURITY FIX (F-10): Wrap migration — called every OAuth request,
        // so failures must not propagate and break all OAuth endpoints.
        try {
            $schema = Capsule::schema();

            if (!$schema->hasTable('mod_nt_mcp_oauth_clients')) {
                $schema->create('mod_nt_mcp_oauth_clients', function ($t) {
                    $t->engine = 'InnoDB';
                    $t->increments('id');
                    $t->string('client_id', 64)->unique();
                    $t->string('client_name', 255)->nullable();
                    $t->text('redirect_uris');
                    $t->timestamp('created_at')->useCurrent();
                });
            }

            if (!$schema->hasTable('mod_nt_mcp_oauth_codes')) {
                $schema->create('mod_nt_mcp_oauth_codes', function ($t) {
                    $t->engine = 'InnoDB';
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
                    $t->engine = 'InnoDB';
                    $t->increments('id');
                    $t->string('token_hash', 64)->unique();
                    $t->string('client_id', 64);
                    $t->integer('expires_at');
                    $t->string('admin_user', 255)->nullable();
                    $t->integer('last_used_at')->nullable();
                    $t->string('family_id', 64)->nullable();
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
                // refresh-token-grant (F1): liga um access token à família de
                // refresh que o emitiu, para que revokeFamily() consiga
                // revogar ambos no reuso detectado / admin inativo.
                if (!$schema->hasColumn('mod_nt_mcp_oauth_tokens', 'family_id')) {
                    $schema->table('mod_nt_mcp_oauth_tokens', function ($t) {
                        $t->string('family_id', 64)->nullable()->after('last_used_at');
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

            // refresh-token-grant (F1): tabela de refresh tokens com rotação
            // obrigatória (OAuth 2.1 §6.1) — single-use, família revogável.
            if (!$schema->hasTable('mod_nt_mcp_oauth_refresh_tokens')) {
                $schema->create('mod_nt_mcp_oauth_refresh_tokens', function ($t) {
                    $t->engine = 'InnoDB';
                    $t->increments('id');
                    $t->string('token_hash', 64)->unique();
                    $t->string('client_id', 64);
                    $t->string('admin_user', 255)->nullable();
                    $t->string('family_id', 64);
                    $t->integer('expires_at');
                    $t->boolean('used')->default(false);
                    $t->integer('used_at')->nullable();
                    $t->timestamp('created_at')->useCurrent();
                    $t->index('family_id');
                });
            } elseif (!$schema->hasColumn('mod_nt_mcp_oauth_refresh_tokens', 'used_at')) {
                // refresh-token-grant (C1): janela de graça pra corrida de
                // refresh paralelo — sem isso, `redeem()` não distingue
                // concorrência legítima de reuso de token roubado.
                $schema->table('mod_nt_mcp_oauth_refresh_tokens', function ($t) {
                    $t->integer('used_at')->nullable()->after('used');
                });
            }
            self::$ensured = true;
            return true;
        } catch (\Throwable $e) {
            Diagnostics::report(Diagnostics::CATEGORY_MIGRATION, 'oauth_migration', $e);
            return false;
        }
    }
}
