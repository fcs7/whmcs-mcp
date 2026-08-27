<?php

declare(strict_types=1);

namespace NtMcp\Admin;

use NtMcp\Whmcs\GateSettings;

/**
 * Valida e canonicaliza o POST do painel de gates ANTES de qualquer escrita.
 *
 * Lógica pura (sem tocar em `\WHMCS\Config\Setting`) pela mesma razão do
 * `ExpiredOAuthTokenCleaner`: o `AdminController` não roda fora de um WHMCS
 * bootstrapado, então tudo que dá pra testar mora aqui.
 *
 * Invariantes de segurança:
 *  - Toggle só sai daqui como `'1'` ou `'0'` — o único par que `ConfigFlag`
 *    reconhece. Gravar qualquer outra coisa criaria estado Invalid fail-closed
 *    silencioso (a UI pareceria ligada e o gate continuaria negando).
 *  - Allowlist só sai como `''` (sem restrição) ou CSV canônico de inteiros
 *    positivos, validado com a MESMA regra de `GateSettings::parseIdCsv()`.
 *    Um token inválido rejeita o formulário INTEIRO — validação parcial
 *    gravaria metade do estado que o operador acha que salvou.
 */
final class GateConfigAction
{
    /** Ordem de exibição do painel; readonly primeiro por ser o master. */
    public const TOGGLE_KEYS = [
        'nt_mcp_readonly',
        'nt_mcp_enable_write',
        'nt_mcp_enable_destructive',
        'nt_mcp_enable_financial',
        'nt_mcp_enable_cost',
        'nt_mcp_enable_comms',
    ];

    public const ALLOWLIST_KEYS = [
        'nt_mcp_write_allowlist_clientids',
        'nt_mcp_write_allowlist_ticketids',
    ];

    /**
     * @param array<string, mixed>   $post    superglobal $_POST bruto
     * @param array<string, ?string> $current valor cru atual por chave de config
     *
     * @return array{ok: bool, error: ?string, changes: array<string, array{old: ?string, new: string}>}
     */
    public static function fromPost(array $post, array $current): array
    {
        $desired = [];

        foreach (self::TOGGLE_KEYS as $key) {
            // Checkbox HTML: presente = marcado. Ausente = desmarcado — inclusive
            // quando o form nem enviou o campo, então desmarcar É gravar '0'.
            $desired[$key] = isset($post['gate'][$key]) ? '1' : '0';
        }

        foreach (self::ALLOWLIST_KEYS as $key) {
            $raw = $post[$key] ?? '';
            if (!is_string($raw)) {
                return ['ok' => false, 'error' => "Valor inesperado em {$key}.", 'changes' => []];
            }
            $trimmed = trim($raw);
            if ($trimmed === '') {
                $desired[$key] = '';
                continue;
            }

            // Mesma regra de leitura do gate (ctype_digit, > 0), mas na
            // variante PURA: typo de formulário é recusa de validação, não o
            // evento CONFIG_INVALID reservado pra storage corrompido. CSV só
            // de vírgulas/espaços vira lista vazia = sem restrição.
            $ids = GateSettings::parseIdCsvOrNull($trimmed);
            if ($ids === null) {
                return [
                    'ok'      => false,
                    'error'   => "Allowlist {$key} contém valor inválido — use apenas ids numéricos separados por vírgula. Nada foi salvo.",
                    'changes' => [],
                ];
            }
            $desired[$key] = implode(',', $ids);
        }

        $changes = [];
        foreach ($desired as $key => $new) {
            $old = $current[$key] ?? null;
            // trim() no atual: '1 ' no banco é o mesmo estado lógico que '1'
            // (ConfigFlag tolera espaços) — regravar seria diff de ruído.
            if ($old !== null && trim($old) === $new) {
                continue;
            }
            if ($old === null && $new === '') {
                continue; // allowlist ausente e campo vazio: nada a canonicalizar
            }
            $changes[$key] = ['old' => $old, 'new' => $new];
        }

        return ['ok' => true, 'error' => null, 'changes' => $changes];
    }
}
