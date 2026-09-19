<?php

declare(strict_types=1);

namespace NtMcp\Translation;

use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\AuditMetadata;
use NtMcp\Whmcs\AuthorizationException;
use NtMcp\Whmcs\GateSettings;
use NtMcp\Whmcs\LocalApiClient;

/**
 * Autorização das tools de tradução. Mesmo desenho de `NtMcp\Whmcs\ChipGuard`:
 * as tools de tradução também não passam pelo `LocalApiClient` (não existe
 * comando WHMCS de escrita para `tblemailtemplates`), então ficam sujeitas às
 * MESMAS decisões via `GateSettings` — master `nt_mcp_readonly` fail-closed e
 * opt-in `nt_mcp_enable_write` (default desligado).
 *
 * Sem allowlist de cliente: a escrita não tem um "dono" de cliente a
 * verificar — não se aplica ao domínio de templates de e-mail.
 */
final class TranslationGuard
{
    /** teste: ['write'=>bool,'readonly'=>bool] */
    private ?array $override;

    public function __construct(?array $override = null)
    {
        $this->override = $override;
    }

    public function assertWriteAllowed(string $operation, ?AuditMetadata $metadata = null): void
    {
        if ($this->readonly()) {
            $this->denyGate($operation, $metadata, 'master read-only');
        }
        if (!$this->writeEnabled()) {
            $this->denyGate($operation, $metadata, 'class WRITE disabled by config');
        }
    }

    private function denyGate(string $operation, ?AuditMetadata $metadata, string $reason): never
    {
        LocalApiClient::auditLog(ActivityEvent::DB_BLOCKED, $metadata, command: $operation);

        throw new AuthorizationException("TranslationTools: '{$operation}' is blocked ({$reason}).");
    }

    private function readonly(): bool
    {
        if ($this->override !== null) {
            return (bool) ($this->override['readonly'] ?? false);
        }

        return GateSettings::readonlyEnabled();
    }

    private function writeEnabled(): bool
    {
        if ($this->override !== null) {
            return (bool) ($this->override['write'] ?? false);
        }

        return GateSettings::boolSetting('nt_mcp_enable_write', false);
    }
}
