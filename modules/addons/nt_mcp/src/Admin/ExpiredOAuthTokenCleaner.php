<?php

declare(strict_types=1);

namespace NtMcp\Admin;

use WHMCS\Database\Capsule;

/** Remove somente tokens OAuth que já não podem mais autenticar. */
final class ExpiredOAuthTokenCleaner
{
    public function clean(int $now): int
    {
        return Capsule::table('mod_nt_mcp_oauth_tokens')
            ->where('expires_at', '<=', $now)
            ->delete();
    }
}
