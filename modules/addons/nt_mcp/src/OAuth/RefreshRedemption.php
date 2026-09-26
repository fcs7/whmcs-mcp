<?php

declare(strict_types=1);

namespace NtMcp\OAuth;

use NtMcp\Whmcs\ActivityEvent;

/**
 * Resultado de `RefreshTokenService::redeem()`.
 *
 * Value object simples — evita que o handler (F4, fora de escopo) precise
 * inspecionar exceções para decidir a resposta OAuth. `errorCode` é sempre
 * `'invalid_grant'` nos dois casos de falha (reuso e negado); o handler usa
 * `reuseDetected` só para escolher a MENSAGEM de erro devolvida ao cliente
 * ("the token family has been revoked"). A resposta HTTP continua genérica
 * em todos os motivos de negação — só `deniedEvent` (C2) distingue o motivo,
 * e só no Activity Log do servidor, nunca no corpo da resposta.
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
        public readonly ?ActivityEvent $deniedEvent,
    ) {
    }

    public static function ok(string $clientId, ?string $adminUser, string $familyId): self
    {
        return new self(true, false, null, $clientId, $adminUser, $familyId, null);
    }

    /**
     * `$event` é sempre um case de `ActivityEvent` (enum fechado) — nunca
     * texto livre. É isso que permite ao handler logar o motivo da negação
     * sem violar a allowlist estrita do D7 (`AuditMetadata`).
     */
    public static function denied(ActivityEvent $event): self
    {
        return new self(false, false, 'invalid_grant', null, null, null, $event);
    }

    public static function reuseDetected(): self
    {
        return new self(false, true, 'invalid_grant', null, null, null, ActivityEvent::OAUTH_REFRESH_REUSE_DETECTED);
    }
}
