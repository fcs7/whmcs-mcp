<?php

declare(strict_types=1);

namespace NtMcp\OAuth;

/**
 * Resultado de `RefreshTokenService::redeem()`.
 *
 * Value object simples — evita que o handler (F4, fora de escopo) precise
 * inspecionar exceções para decidir a resposta OAuth. `errorCode` é sempre
 * `'invalid_grant'` nos dois casos de falha (reuso e negado); o handler usa
 * `reuseDetected` só para escolher o evento de audit
 * (`OAUTH_REFRESH_REUSE_DETECTED` vs `OAUTH_REFRESH_DENIED`).
 */
final class RefreshRedemption
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $reuseDetected,
        public readonly ?string $errorCode,
        public readonly ?string $clientId,
        public readonly ?string $adminUser,
        public readonly ?string $familyId,
    ) {
    }

    public static function ok(string $clientId, ?string $adminUser, string $familyId): self
    {
        return new self(true, false, null, $clientId, $adminUser, $familyId);
    }

    public static function denied(string $errorCode): self
    {
        return new self(false, false, $errorCode, null, null, null);
    }

    public static function reuseDetected(): self
    {
        return new self(false, true, 'invalid_grant', null, null, null);
    }
}
