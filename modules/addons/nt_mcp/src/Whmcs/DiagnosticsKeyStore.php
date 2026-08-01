<?php

declare(strict_types=1);

namespace NtMcp\Whmcs;

use Illuminate\Database\Capsule\Manager as Capsule;

/** Persistência cluster-safe da chave D10 apoiada na unicidade de `setting`. */
final class DiagnosticsKeyStore
{
    /** @var (\Closure(string):mixed)|null */
    private static ?\Closure $claimOverride = null;

    /** Seam explícito para testes de interleaving; nunca usado em produção. */
    public static function setClaimOverrideForTests(?\Closure $override): void
    {
        self::$claimOverride = $override;
    }

    /**
     * Tenta inserir a candidata somente se a setting ainda não existir e relê
     * a vencedora. `tblconfiguration.setting` é único no schema WHMCS; o
     * `insertOrIgnore` vira INSERT IGNORE no MySQL compartilhado e serializa
     * processos/nós sem depender de flock local.
     */
    public static function claim(string $candidate): ?string
    {
        if (!Diagnostics::isValidKey($candidate)) {
            return null;
        }

        try {
            if (self::$claimOverride !== null) {
                $winner = (self::$claimOverride)($candidate);
            } else {
                Capsule::table('tblconfiguration')->insertOrIgnore([
                    'setting' => Diagnostics::KEY_SETTING,
                    'value' => $candidate,
                ]);
                $winner = Capsule::table('tblconfiguration')
                    ->where('setting', Diagnostics::KEY_SETTING)
                    ->value('value');
            }
        } catch (\Throwable) {
            return null;
        }

        return Diagnostics::isValidKey($winner) ? $winner : null;
    }
}
