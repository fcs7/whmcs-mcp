<?php

declare(strict_types=1);

namespace NtMcp\Whmcs;

/**
 * Centralizes access to the WHMCS admin session, insulating the rest of the
 * addon from the internal $_SESSION key name used by WHMCS.
 *
 * If WHMCS renames the session key in a future release, only this class needs
 * to be updated — no other code has to change.
 */
final class AdminSession
{
    /**
     * Returns the current admin ID from the WHMCS admin session, or 0 if no
     * valid admin session exists.
     *
     * WHMCS stores the logged-in admin ID in $_SESSION['adminid']. This is an
     * internal implementation detail, not a documented stable API.
     */
    public static function getAdminId(): int
    {
        $id = $_SESSION['adminid'] ?? null;
        if ($id === null || !is_numeric($id)) {
            return 0;
        }
        return (int) $id;
    }
}
