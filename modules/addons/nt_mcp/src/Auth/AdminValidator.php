<?php

declare(strict_types=1);

namespace NtMcp\Auth;

use NtMcp\Whmcs\Diagnostics;

use WHMCS\Database\Capsule;

/**
 * Confirma que um admin ainda existe em `tbladmins` e não está desabilitado.
 *
 * Extraído de `BearerAuth::validateAdminActive()` (SECURITY FIX B1) —
 * reusado por `RefreshTokenService::redeem()` (refresh-token-grant, F3), que
 * precisa re-checar o admin a cada rotação: sem isso, um admin desativado
 * renasceria a cada rotação por até 30 dias (TTL do refresh).
 */
final class AdminValidator
{
    /**
     * Fail-closed em erro de DB — disponibilidade de `tbladmins` é
     * pré-requisito de qualquer forma. `null`/`''` também é fail-closed
     * (nenhum admin válido para checar).
     */
    public function isActive(?string $username): bool
    {
        if ($username === null || $username === '') {
            return false;
        }

        try {
            // FakeCapsule (tests) não implementa exists(); count() > 0 é
            // equivalente e cobre também o driver real.
            return Capsule::table('tbladmins')
                ->where('username', $username)
                ->where('disabled', 0)
                ->count() > 0;
        } catch (\Throwable $e) {
            Diagnostics::report(Diagnostics::CATEGORY_AUTH, 'tbladmins_validation', $e);
            return false;
        }
    }
}
