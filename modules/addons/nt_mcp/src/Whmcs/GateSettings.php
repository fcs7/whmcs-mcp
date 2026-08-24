<?php
// src/Whmcs/GateSettings.php
namespace NtMcp\Whmcs;

/**
 * Leitura das flags de gate em `tblconfiguration` — ponto ÚNICO.
 *
 * Existia só dentro do `LocalApiClient`, como métodos privados, porque só a
 * LocalAPI tinha efeito a proteger. `ChipTools` escreve direto nas tabelas do
 * addon nt_chips, sem passar por comando WHMCS nenhum, e precisa das MESMAS
 * decisões: master `nt_mcp_readonly` fail-closed, opt-in por classe e allowlist
 * por alvo. Uma segunda cópia dessas regras é como elas divergem — a que for
 * esquecida vira o bypass. Então a leitura mora aqui e os dois chamam.
 *
 * O que NÃO mudou de lugar: os overrides de teste e a classificação de comando
 * continuam no `LocalApiClient`; aqui só se lê config real.
 */
final class GateSettings
{
    /** Classe de efeito → chave de opt-in. Fail-closed: classe sem entrada nunca libera. */
    private const CLASS_KEYS = [
        'WRITE'       => 'nt_mcp_enable_write',
        'DESTRUCTIVE' => 'nt_mcp_enable_destructive',
        'FINANCIAL'   => 'nt_mcp_enable_financial',
        'COST'        => 'nt_mcp_enable_cost',
        'COMMS'       => 'nt_mcp_enable_comms',
    ];

    public static function keyForClass(string $class): ?string
    {
        return self::CLASS_KEYS[$class] ?? null;
    }

    /**
     * Master switch. FAIL-CLOSED em três frentes: falha de leitura bloqueia,
     * ausente segue o default do rollout, e valor presente porém não canônico
     * (`'true'`, `'yes'`, `2`) bloqueia e é auditado.
     */
    public static function readonlyEnabled(): bool
    {
        // Fora de um WHMCS bootstrapado (testes) não há config a proteger.
        if (!class_exists('\WHMCS\Config\Setting')) {
            return false;
        }
        try {
            $raw = \WHMCS\Config\Setting::getValue('nt_mcp_readonly');
        } catch (\Throwable $e) {
            // A mensagem NÃO é concatenada: uma PDOException aqui carrega DSN,
            // credencial e SQL, e este ramo grava nos dois sinks.
            self::auditConfig('readonly config read failed — failing closed', $e);

            return true;
        }

        return ConfigFlag::parse($raw)->resolve(
            default: false,
            failClosed: true,
            key: 'nt_mcp_readonly',
            auditor: self::auditConfig(...),
        );
    }

    /**
     * Flag booleana de gate. Ausente usa o default; presente e inválida falha
     * FECHADO (gate desligado) e é auditada.
     */
    public static function boolSetting(string $key, bool $default): bool
    {
        if (!class_exists('\WHMCS\Config\Setting')) {
            return $default;
        }
        try {
            $raw = \WHMCS\Config\Setting::getValue($key);
        } catch (\Throwable $e) {
            return $default;
        }

        return ConfigFlag::parse($raw)->resolve(
            default: $default,
            failClosed: false,
            key: $key,
            auditor: self::auditConfig(...),
        );
    }

    /**
     * Allowlist de ids (CSV). null = chave ausente/vazia = sem restrição;
     * `[]` = configurada porém ilegível/inválida = nega tudo.
     *
     * @return int[]|null
     */
    public static function idAllowlist(string $key): ?array
    {
        if (!class_exists('\WHMCS\Config\Setting')) {
            return null;
        }
        try {
            $raw = \WHMCS\Config\Setting::getValue($key);
        } catch (\Throwable $e) {
            self::auditConfig('target allowlist read failed — failing closed', $e);

            return [];
        }
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return self::parseIdCsv((string) $raw, $key);
    }

    /** @return int[] lista vazia quando qualquer token é inválido (nega tudo) */
    public static function parseIdCsv(string $raw, string $key): array
    {
        $ids = [];
        foreach (explode(',', $raw) as $tok) {
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }
            if (!ctype_digit($tok) || (int) $tok <= 0) {
                self::auditConfig("invalid id in {$key} — failing closed");

                return [];
            }
            $ids[] = (int) $tok;
        }

        return $ids;
    }

    /**
     * Config corrompida é evento de segurança: texto ESTÁVEL nosso no Activity
     * Log, diagnóstico estrutural (classe + fingerprint) no error_log,
     * correlacionados. A mensagem da exceção não entra em nenhum dos dois.
     */
    public static function auditConfig(string $_message, ?\Throwable $e = null): void
    {
        $correlationId = Diagnostics::report(Diagnostics::CATEGORY_CONFIG_READ, 'nt_mcp_config', $e);
        LocalApiClient::auditLog(ActivityEvent::CONFIG_INVALID, null, $correlationId);
    }
}
