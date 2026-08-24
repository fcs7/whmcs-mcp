<?php

declare(strict_types=1);

namespace NtMcp\Crm;

use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\AuditMetadata;
use NtMcp\Whmcs\ConfigFlag;
use NtMcp\Whmcs\Diagnostics;
use NtMcp\Whmcs\LocalApiClient;

/**
 * Gate de escrita futuro do domínio CRM, alinhado ao contrato do
 * `LocalApiClient`. Não existe rota executável que o consuma neste release;
 * ele permanece como fundação do CRM-3. O contrato fail-closed exige:
 *
 *  - `nt_mcp_readonly` é master switch e falha de leitura dele BLOQUEIA;
 *  - `nt_mcp_enable_write` tem default DESLIGADO;
 *  - valor presente porém não canônico (`'true'`, `'yes'`, `2`) bloqueia e audita.
 *
 * D12: a recusa é `denied`, não `validation`. O gate fechado não é erro de
 * input do chamador — nenhuma correção de campo o abre — e não é transitório,
 * então também não é `downstream`.
 *
 * Neste release este objeto é apenas uma DECISÃO: não existe executor gravável
 * no addon para ele guardar. CRM-3 poderá consumi-lo antes da autoria OAuth.
 */
final class CrmWriteGate
{
    /** @param bool|null $override injeção de teste; null = lê a configuração */
    public function __construct(private readonly ?bool $override = null)
    {
    }

    /** @throws CrmException `denied` */
    public function assertWritable(): void
    {
        if ($this->isWritable()) {
            return;
        }

        $correlationId = Diagnostics::report(Diagnostics::CATEGORY_CONFIG_READ, 'crm_write_gate_closed');
        LocalApiClient::auditLog(ActivityEvent::DB_BLOCKED, AuditMetadata::none(), $correlationId);

        throw CrmException::denied($correlationId);
    }

    public function isWritable(): bool
    {
        if ($this->override !== null) {
            return $this->override;
        }

        if (!class_exists('\WHMCS\Config\Setting')) {
            // Fora de um WHMCS bootstrapado não há configuração a proteger; o
            // seam de teste decide. Sem seam, fecha.
            return false;
        }

        if ($this->isReadonly()) {
            return false;
        }

        return $this->boolConfig('nt_mcp_enable_write', false, failClosed: false);
    }

    private function isReadonly(): bool
    {
        return $this->boolConfig('nt_mcp_readonly', false, failClosed: true);
    }

    private function boolConfig(string $key, bool $default, bool $failClosed): bool
    {
        try {
            $raw = \WHMCS\Config\Setting::getValue($key);
        } catch (\Throwable $e) {
            self::auditConfig($e);

            return $failClosed ? true : $default;
        }

        return ConfigFlag::parse($raw)->resolve(
            default: $default,
            failClosed: $failClosed,
            key: $key,
            auditor: static fn(string $_message) => self::auditConfig(null),
        );
    }

    private static function auditConfig(?\Throwable $e): void
    {
        $correlationId = Diagnostics::report(Diagnostics::CATEGORY_CONFIG_READ, 'nt_mcp_config', $e);
        LocalApiClient::auditLog(ActivityEvent::CONFIG_INVALID, null, $correlationId);
    }
}
