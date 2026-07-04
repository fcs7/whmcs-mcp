<?php
namespace NtMcp\Whmcs;

/** Redação central de respostas de tools antes de devolver ao chamador MCP. */
final class ResponseRedactor
{
    /** Chaves nunca legítimas numa resposta — removidas em qualquer profundidade. */
    private const ALWAYS_STRIP = ['password', 'password2', 'securityqans'];

    /** Remove recursivamente chaves sensíveis (defense-in-depth). */
    public static function scrubSensitive(array &$data, int $depth = 0): void
    {
        if ($depth > 8) return;
        foreach ($data as $key => &$value) {
            if (in_array(strtolower((string) $key), self::ALWAYS_STRIP, true)) {
                unset($data[$key]);
                continue;
            }
            if (is_array($value)) {
                self::scrubSensitive($value, $depth + 1);
            }
        }
        unset($value);
    }

    /** Remove password (hash do cliente) e securityqans de GetClientsDetails. */
    public static function stripClientDetails(array &$result): void
    {
        self::scrubSensitive($result);
    }

    /** Remove password de products[].product[] (substitui os dois strip duplicados). */
    public static function stripProductPasswords(array &$result): void
    {
        if (isset($result['products']['product']) && is_array($result['products']['product'])) {
            foreach ($result['products']['product'] as &$p) {
                unset($p['password']);
            }
            unset($p);
        }
    }

    /**
     * Pay methods: ALLOWLIST de campos seguros (substitui o denylist frágil que
     * só removia 3 campos e vazava last-four/validade/ACH).
     */
    private const PAYMETHOD_SAFE_KEYS = [
        'id', 'payment_method_type', 'description', 'is_default', 'created_at', 'updated_at',
    ];
    public static function stripPayMethods(array &$result): void
    {
        if (!isset($result['paymethods']) || !is_array($result['paymethods'])) return;
        foreach ($result['paymethods'] as &$pm) {
            if (!is_array($pm)) continue;
            $safe = [];
            foreach (self::PAYMETHOD_SAFE_KEYS as $k) {
                if (array_key_exists($k, $pm)) $safe[$k] = $pm[$k];
            }
            // último dígito mascarado, se existir de forma explícita
            if (isset($pm['card_last_four'])) $safe['card_last_four'] = $pm['card_last_four'];
            $pm = $safe;
        }
        unset($pm);
    }
}
