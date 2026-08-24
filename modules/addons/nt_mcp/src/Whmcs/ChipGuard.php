<?php
// src/Whmcs/ChipGuard.php
namespace NtMcp\Whmcs;

/**
 * Autorização das tools de chip.
 *
 * As tools de chip escrevem direto nas tabelas do nt_chips — não passam pelo
 * `LocalApiClient`, logo não herdam nada do pipeline dele. Este guard existe
 * para que elas fiquem sujeitas às MESMAS decisões: master `nt_mcp_readonly`
 * fail-closed, opt-in `nt_mcp_enable_write` (default DESLIGADO) e a allowlist
 * por alvo do #14. A leitura da config em si é `GateSettings`, compartilhada —
 * sem segunda cópia das regras.
 *
 * Recusa é `AuthorizationException`: o `AuthorizationAwareReferenceHandler`
 * (#29) converte em `CallToolResult::error()` com o motivo preservado, em vez
 * do `-32603` genérico que descartaria a mensagem.
 */
class ChipGuard
{
    /** teste: ['write'=>bool,'readonly'=>bool,'allowlist_clientids'=>?int[]] */
    private ?array $override;

    public function __construct(?array $override = null)
    {
        $this->override = $override;
    }

    /**
     * Gate de classe WRITE. Toda escrita de chip é WRITE — não há classe
     * própria: o operador que liberou escrita no addon liberou escrita, e um
     * quinto opt-in só multiplicaria a chance de ficar ligado por engano.
     */
    public function assertWriteAllowed(string $operation, ?AuditMetadata $metadata = null): void
    {
        if ($this->readonly()) {
            $this->denyGate($operation, $metadata, 'master read-only');
        }
        if (!$this->writeEnabled()) {
            $this->denyGate($operation, $metadata, 'class WRITE disabled by config');
        }
    }

    /**
     * Gate por ALVO (#14) aplicado ao dono do serviço. Allowlist ausente/vazia
     * = sem restrição; configurada porém ilegível = lista vazia = nega tudo.
     * Serviço sem dono resolvível (id inexistente) também nega — sem dono não
     * dá para afirmar que o alvo está autorizado.
     *
     * Divergência DELIBERADA em relação ao #14 do `LocalApiClient`: lá `userid`
     * 0 é ticket guest, caso legítimo que segue sem checagem de cliente. Um
     * `tblhosting.userid` 0 não é guest, é registro órfão — não existe serviço
     * sem dono — então aqui ele cai na negação junto com o resto.
     */
    public function assertClientAllowed(string $operation, ?int $clientId, ?AuditMetadata $metadata = null): void
    {
        $allowlist = $this->clientAllowlist();
        if ($allowlist === null) {
            return;
        }
        if ($clientId === null || !in_array($clientId, $allowlist, true)) {
            LocalApiClient::auditLog(ActivityEvent::API_BLOCKED_TARGET, $metadata, command: $operation);

            throw new AuthorizationException(
                "ChipTools: '{$operation}' is blocked (write_target_not_allowed: clientid fora da allowlist de escrita)."
            );
        }
    }

    private function denyGate(string $operation, ?AuditMetadata $metadata, string $reason): never
    {
        LocalApiClient::auditLog(ActivityEvent::DB_BLOCKED, $metadata, command: $operation);

        throw new AuthorizationException("ChipTools: '{$operation}' is blocked ({$reason}).");
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

    /** @return int[]|null */
    private function clientAllowlist(): ?array
    {
        if ($this->override !== null) {
            $raw = $this->override['allowlist_clientids'] ?? null;
            if ($raw === null) {
                return null;
            }

            return is_array($raw)
                ? array_values(array_map('intval', $raw))
                : GateSettings::parseIdCsv((string) $raw, 'nt_mcp_write_allowlist_clientids');
        }

        return GateSettings::idAllowlist('nt_mcp_write_allowlist_clientids');
    }
}
