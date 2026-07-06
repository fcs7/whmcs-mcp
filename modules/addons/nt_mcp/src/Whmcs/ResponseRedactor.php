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
     * Pay methods: ALLOWLIST alinhada ao payload real de GetPayMethods (WHMCS).
     * Qualquer campo fora desta lista é descartado — inclui remote_token,
     * card_number, cvv e tokens de gateway, que nunca são devolvidos.
     * card_last_four já é mascarado pelo próprio WHMCS (só 4 dígitos).
     */
    private const PAYMETHOD_SAFE_KEYS = [
        'id', 'type', 'description', 'gateway_name',
        'contact_type', 'contact_id', 'card_last_four', 'expiry_date',
        'start_date', 'issue_number', 'card_type', 'last_updated',
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
            $pm = $safe;
        }
        unset($pm);
    }
}
