<?php
// src/Whmcs/ConfigFlag.php
namespace NtMcp\Whmcs;

/**
 * Parser TRI-STATE compartilhado para as flags booleanas do addon
 * (`nt_mcp_readonly`, `nt_mcp_enable_*`).
 *
 * O problema que ele resolve: uma leitura ingênua do tipo `$v === '1'` trata
 * QUALQUER valor não reconhecido como "desligado". Se `nt_mcp_readonly` for
 * gravado como `'true'`, `'yes'`, `'garbage'` ou `2` — por edição manual, import
 * ou bug de terceiro — o master switch se desliga silenciosamente.
 *
 * Aqui há três desfechos distintos, e cada chamador decide o que fazer com eles:
 *
 *  - `Absent`  — chave inexistente/vazia: usa o default decidido para a flag.
 *  - `On`/`Off`— representação canônica reconhecida: usa o valor.
 *  - `Invalid` — valor PRESENTE porém não reconhecido: falha FECHADO e audita.
 *
 * Canônicos aceitos (deliberadamente estreitos): `true`, `1`, `'1'` para ligado;
 * `false`, `0`, `'0'` para desligado. Strings como `'true'`/`'yes'`/`'on'` NÃO
 * são aceitas — é exatamente esse tipo de coerção frouxa que produziu o
 * bypass. Espaços em volta são tolerados.
 */
enum ConfigFlag
{
    case On;
    case Off;
    case Absent;
    case Invalid;

    public static function parse(mixed $raw): self
    {
        if ($raw === null) {
            return self::Absent;
        }

        if (is_bool($raw)) {
            return $raw ? self::On : self::Off;
        }

        if (is_int($raw)) {
            return match ($raw) {
                1 => self::On,
                0 => self::Off,
                default => self::Invalid,
            };
        }

        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return self::Absent;
            }

            return match ($trimmed) {
                '1' => self::On,
                '0' => self::Off,
                default => self::Invalid,
            };
        }

        // float, array, object, resource... nada disso é uma flag válida.
        return self::Invalid;
    }

    /**
     * Resolve a flag para booleano.
     *
     * @param bool     $default    valor usado quando a chave está ausente
     * @param bool     $failClosed valor usado quando a chave tem lixo dentro
     * @param string   $key        nome da chave, só para auditoria
     * @param callable $auditor    fn(string $message): void
     */
    public function resolve(bool $default, bool $failClosed, string $key, callable $auditor): bool
    {
        return match ($this) {
            self::On => true,
            self::Off => false,
            self::Absent => $default,
            self::Invalid => self::failClosed($key, $failClosed, $auditor),
        };
    }

    private static function failClosed(string $key, bool $value, callable $auditor): bool
    {
        $auditor(sprintf(
            'NT MCP config: "%s" tem valor não reconhecido — falhando fechado (%s). '
            . 'Valores aceitos: 1/0 (ou boolean).',
            $key,
            $value ? 'true' : 'false'
        ));

        return $value;
    }
}
