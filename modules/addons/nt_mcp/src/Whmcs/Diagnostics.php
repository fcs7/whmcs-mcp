<?php
// src/Whmcs/Diagnostics.php
namespace NtMcp\Whmcs;

/**
 * FRONTEIRA ÚNICA de diagnóstico do addon.
 *
 * Regra: nenhum ponto de `src/` escreve `Throwable::getMessage()` — nem
 * concatenado, nem interpolado, nem "só no error_log". Todo caminho de erro
 * passa por aqui, e daqui só sai o que nós controlamos:
 *
 *   - a correlação, que liga esta linha ao Activity Log;
 *   - uma categoria estável (nossa, de enum fechado);
 *   - o contexto (comando/tabela/chave), higienizado;
 *   - a CLASSE da exceção — nome de tipo, não conteúdo;
 *   - um fingerprint HMAC da mensagem.
 *
 * Por que fronteira ÚNICA e não "os pontos que importam": a revisão contou 24
 * concatenações cruas em `src/`, sendo 14 no caminho de uma request MCP —
 * incluindo config, resolução de admin, auth, IP e CORS. Uma `PDOException` de
 * `Setting::getValue()` gravou DSN, senha e token literalmente nos dois sinks.
 * Migrar metade deixaria o padrão antigo vivo para a próxima edição copiar.
 *
 * Fingerprint: HMAC-SHA256 truncado em 128 bits. SHA-256 nu era oráculo de
 * dicionário — mensagens de baixa entropia (`Client Not Found`, um CPF) são
 * reconstruíveis testando candidatos. Com chave, o log deixa de confirmar
 * palpites. O fingerprint continua servindo ao operador para agrupar
 * incidentes idênticos.
 */
final class Diagnostics
{
    // Categorias — enum fechado, sempre nosso.
    public const CATEGORY_API_ERROR = 'downstream_api_error';
    public const CATEGORY_API_EXCEPTION = 'downstream_api_exception';
    public const CATEGORY_API_MALFORMED = 'downstream_api_malformed_response';
    public const CATEGORY_DB_EXCEPTION = 'database_exception';
    public const CATEGORY_AUDIT_SINK = 'audit_sink_failure';
    public const CATEGORY_PARTIAL_EFFECT = 'partial_financial_effect';
    public const CATEGORY_CONFIG_READ = 'config_read_failure';
    public const CATEGORY_ADMIN_LOOKUP = 'admin_lookup_failure';
    public const CATEGORY_AUTH = 'auth_failure';
    public const CATEGORY_NETWORK_CONTEXT = 'network_context_failure';
    public const CATEGORY_OAUTH = 'oauth_failure';
    public const CATEGORY_ADMIN_UI = 'admin_ui_failure';
    public const CATEGORY_MIGRATION = 'migration_failure';
    public const CATEGORY_UNHANDLED = 'unhandled_exception';

    /** Chave HMAC; injetável para teste. Null = ainda não resolvida. */
    private static ?string $key = null;

    public static function setFingerprintKey(?string $key): void
    {
        self::$key = $key;
    }

    /**
     * Fingerprint HMAC de 128 bits, estável dentro do escopo da chave.
     *
     * A chave sai de `nt_mcp_diagnostics_key`. Sem ela, geramos uma por
     * processo: o fingerprint continua agrupando incidentes dentro da mesma
     * execução e nunca vira oráculo — só perde estabilidade entre processos,
     * o que é degradação aceitável e jamais quebra a request.
     */
    public static function fingerprint(?string $text): string
    {
        if ($text === null || $text === '') {
            return 'none';
        }

        return substr(hash_hmac('sha256', $text, self::key()), 0, 32);
    }

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $configured = null;
        if (class_exists('\WHMCS\Config\Setting')) {
            try {
                $value = \WHMCS\Config\Setting::getValue('nt_mcp_diagnostics_key');
                if (is_string($value) && strlen(trim($value)) >= 16) {
                    $configured = trim($value);
                }
            } catch (\Throwable) {
                // Sem chave configurada — cai no fallback por processo. NÃO
                // logamos nada aqui: seria recursão com o próprio sink.
                $configured = null;
            }
        }

        try {
            return self::$key = $configured ?? bin2hex(random_bytes(32));
        } catch (\Throwable) {
            return self::$key = $configured ?? hash('sha256', __FILE__ . getmypid());
        }
    }

    /**
     * Registra um incidente e devolve a correlação usada.
     *
     * @param string          $category uma das CATEGORY_*
     * @param string          $context  comando/tabela/chave — higienizado
     * @param \Throwable|null $e        só classe e fingerprint são usados
     * @param string|null     $rawText  NUNCA é escrito; vira fingerprint
     */
    public static function log(
        ?string $correlationId,
        string $category,
        string $context,
        ?\Throwable $e = null,
        ?string $rawText = null,
    ): string {
        $correlationId ??= self::newCorrelationId();

        $parts = [
            '[NT-MCP]',
            "[corr:{$correlationId}]",
            'category=' . self::safeToken($category),
            'context=' . self::safeToken($context),
        ];

        if ($e !== null) {
            $parts[] = 'exception=' . self::safeToken(get_class($e));
            $parts[] = 'fingerprint=' . self::fingerprint($e->getMessage());
        } elseif ($rawText !== null) {
            $parts[] = 'fingerprint=' . self::fingerprint($rawText);
        }

        error_log(implode(' ', $parts));

        return $correlationId;
    }

    /** Atalho para quem não tem correlação própria. */
    public static function report(string $category, string $context, ?\Throwable $e = null): string
    {
        return self::log(null, $category, $context, $e);
    }

    /**
     * Registra com um fingerprint JÁ CALCULADO da causa original.
     *
     * Serve a quem só tem a wrapper em mãos: fingerprintar a wrapper daria um
     * valor novo a cada execução (a mensagem dela contém uma correlação
     * aleatória), quebrando o agrupamento por causa.
     */
    public static function logWithFingerprint(
        ?string $correlationId,
        string $category,
        string $context,
        string $causeFingerprint,
        string $causeClass = '',
    ): string {
        $correlationId ??= self::newCorrelationId();

        $parts = [
            '[NT-MCP]',
            "[corr:{$correlationId}]",
            'category=' . self::safeToken($category),
            'context=' . self::safeToken($context),
        ];
        if ($causeClass !== '') {
            $parts[] = 'exception=' . self::safeToken($causeClass);
        }
        $parts[] = 'fingerprint=' . self::safeToken($causeFingerprint);

        error_log(implode(' ', $parts));

        return $correlationId;
    }

    public static function newCorrelationId(): string
    {
        try {
            return bin2hex(random_bytes(4));
        } catch (\Throwable) {
            return str_pad(dechex(mt_rand(0, 0xFFFFFFFF)), 8, '0', STR_PAD_LEFT);
        }
    }

    /**
     * Mesmo o contexto é higienizado: hoje vem das nossas allowlists, mas um
     * chamador futuro poderia passar algo inesperado.
     */
    public static function safeToken(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.\\\\\-]/', '', $value) ?? '';

        return $safe === '' ? 'unknown' : substr($safe, 0, 96);
    }
}
