<?php

declare(strict_types=1);

namespace NtMcp\OAuth;

use NtMcp\Crm\CapsuleEngineProbe;
use WHMCS\Database\Capsule;

/** Serializes grant issuance and administrative revocation on the database writer. */
final class OAuthTransaction
{
    private static int $depth = 0;

    public static function run(callable $operation): mixed
    {
        if (self::$depth > 0) {
            return $operation();
        }
        $connection = Capsule::connection();
        if ($connection->transactionLevel() !== 0) {
            throw new \RuntimeException('OAuth requires an independently committed transaction');
        }
        $probe = new CapsuleEngineProbe();
        foreach (['clients', 'codes', 'tokens', 'refresh_tokens'] as $suffix) {
            if (!$probe->isInnoDb('mod_nt_mcp_oauth_' . $suffix)->isPresent()) {
                throw new \RuntimeException('OAuth requires InnoDB storage');
            }
        }

        return $connection->transaction(static function () use ($operation): mixed {
            // At most 50 registrations. Lock them in a consistent order BEFORE
            // reading grants or deleting credentials. All grants require an
            // existing client, so a revoked client cannot recreate credentials.
            // InnoDB locking reads also use the writer under repeatable-read.
            Capsule::table('mod_nt_mcp_oauth_clients')->orderBy('id')->lockForUpdate()->get();
            self::$depth++;
            try {
                return $operation();
            } finally {
                self::$depth--;
            }
        });
    }

    /** Do not expose a credential/redirect until its transaction has committed. */
    public static function capture(callable $operation): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            self::run($operation);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
