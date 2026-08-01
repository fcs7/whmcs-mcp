<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

use NtMcp\Crm\AdminIdentityResolver;
use NtMcp\Crm\CrmException;
use NtMcp\Whmcs\Diagnostics;

/**
 * Resolver injetável. Devolve o id configurado ou falha fechado — nunca
 * inventa um admin, exatamente como a implementação real.
 */
final class FakeAdminIdentityResolver implements AdminIdentityResolver
{
    /** @var array<int, string> */
    public array $calls = [];

    private function __construct(private readonly ?int $adminId)
    {
    }

    public static function resolvingTo(int $adminId): self
    {
        return new self($adminId);
    }

    public static function failing(): self
    {
        return new self(null);
    }

    public function resolveActiveAdminId(string $username): int
    {
        $this->calls[] = $username;

        if ($this->adminId === null) {
            // D12: recusa determinística de identidade é `denied`, não
            // `downstream` — não é transitória e não se corrige com retry.
            throw CrmException::denied(
                Diagnostics::report(Diagnostics::CATEGORY_ADMIN_LOOKUP, 'crm_admin_identity_test')
            );
        }

        return $this->adminId;
    }
}
