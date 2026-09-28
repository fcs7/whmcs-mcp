<?php

declare(strict_types=1);

namespace NtMcp\OAuth;

use WHMCS\Database\Capsule;

final class OAuthRevocation
{
    public static function token(int $id): void
    {
        OAuthTransaction::run(static function () use ($id): void {
            $row = Capsule::table('mod_nt_mcp_oauth_tokens')->where('id', $id)->first();
            $family = trim((string) ($row->family_id ?? ''));
            if ($family !== '') {
                (new RefreshTokenService())->revokeFamily($family);
            } else {
                Capsule::table('mod_nt_mcp_oauth_tokens')->where('id', $id)->delete();
            }
        });
    }

    public static function all(): int
    {
        return OAuthTransaction::run(static function (): int {
            $count = Capsule::table('mod_nt_mcp_oauth_tokens')->delete();
            $count += Capsule::table('mod_nt_mcp_oauth_refresh_tokens')->delete();
            // Outstanding approvals/codes must not restore a revoked grant.
            Capsule::table('mod_nt_mcp_oauth_codes')->delete();
            return $count;
        });
    }

    public static function client(string $clientId): void
    {
        OAuthTransaction::run(static function () use ($clientId): void {
            foreach (['tokens', 'refresh_tokens', 'codes', 'clients'] as $suffix) {
                Capsule::table('mod_nt_mcp_oauth_' . $suffix)->where('client_id', $clientId)->delete();
            }
        });
    }
}
