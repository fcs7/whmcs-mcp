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
    public const CATEGORY_TLS = 'tls_policy';
    public const CATEGORY_RUNTIME = 'runtime_failure';

    /** Contextos operacionais sem exceção — todos literais do addon. */
    private const EVENT_CONTEXTS = [
        'admin_user_unresolved',
        'admin_user_not_configured',
        'lock_open_failed',
        'lock_busy',
        'allow_http_bypass',
        'xff_without_trusted_proxies',
        'rate_limit_dir_create_failed',
        'rate_limit_file_open_failed',
    ];

    private const EVENT_CATEGORIES = [
        self::CATEGORY_AUTH,
        self::CATEGORY_NETWORK_CONTEXT,
        self::CATEGORY_TLS,
        self::CATEGORY_RUNTIME,
    ];

    /** Nome da chave HMAC persistida na ativação (D10). */
    public const KEY_SETTING = 'nt_mcp_diagnostics_key';

    /** Formato exigido: 64 hex = 32 bytes. */
    private const KEY_PATTERN = '/^[0-9a-f]{64}\z/';

    /** Rejeita material obviamente previsível/repetitivo persistido. */
    private const KEY_MIN_UNIQUE_BYTES = 16;

    /** Chave HMAC; injetável para teste. `false` = ainda não resolvida. */
    private static string|false|null $key = false;

    /** `null` desabilita o fingerprint; string define a chave. */
    public static function setFingerprintKey(?string $key): void
    {
        self::$key = self::isValidKey($key) ? $key : null;
    }

    /** Limpa o cache para forçar releitura (testes). */
    public static function resetFingerprintKey(): void
    {
        self::$key = false;
    }

    /** Gera uma chave nova no formato canônico. @throws \Throwable se o RNG falhar */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isValidKey(mixed $key): bool
    {
        if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return false;
        }

        $bytes = hex2bin($key);

        // Não é possível medir entropia depois que a chave foi persistida, mas
        // esta barreira rejeita material obviamente fraco (byte/pequeno padrão
        // repetido). Uma chave de random_bytes(32) cruza este limiar com margem
        // enorme; uma chave manual previsível falha fechado.
        return $bytes !== false
            && count(array_unique(str_split($bytes))) >= self::KEY_MIN_UNIQUE_BYTES;
    }

    /**
     * Fingerprint HMAC de 128 bits, estável entre processos e instalações.
     *
     * D10: a chave é gerada e persistida na ativação. Se ela estiver ausente,
     * inválida, ou se o RNG falhar, o fingerprint é OMITIDO — nunca derivado de
     * path, PID, horário ou qualquer dado previsível. O fallback anterior
     * (`hash(__FILE__ . getmypid())`) era reconstruível por quem conhecesse o
     * ambiente, o que transforma o log em oráculo em vez de protegê-lo.
     *
     * @return string|null null quando não há chave utilizável
     */
    public static function fingerprint(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $key = self::key();
        if ($key === null) {
            return null;
        }

        return substr(hash_hmac('sha256', $text, $key), 0, 32);
    }

    private static function key(): ?string
    {
        if (self::$key !== false) {
            return self::$key;
        }

        if (!class_exists('\WHMCS\Config\Setting')) {
            return self::$key = null;
        }

        try {
            $value = \WHMCS\Config\Setting::getValue(self::KEY_SETTING);
        } catch (\Throwable) {
            // Não logamos: seria recursão com o próprio sink.
            return self::$key = null;
        }

        return self::$key = self::isValidKey($value) ? $value : null;
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
        }

        $fingerprint = $e !== null
            ? self::fingerprint($e->getMessage())
            : ($rawText !== null ? self::fingerprint($rawText) : null);

        if ($fingerprint !== null) {
            $parts[] = 'fingerprint=' . $fingerprint;
        }

        self::write($parts);

        return $correlationId;
    }

    /**
     * Evento operacional SEM exceção — o substituto dos `error_log()` diretos.
     *
     * `$context` e `$detail` são shapes fechados. Nada de IP, header, path
     * absoluto ou valor vindo da request consegue atravessar esta API.
     *
     * @param array{timeout_seconds?:int} $detail shape fechado
     */
    public static function event(string $category, string $context, array $detail = []): string
    {
        $correlationId = self::newCorrelationId();

        $parts = [
            '[NT-MCP]',
            "[corr:{$correlationId}]",
            'category=' . (in_array($category, self::EVENT_CATEGORIES, true) ? $category : self::CATEGORY_RUNTIME),
            'context=' . (in_array($context, self::EVENT_CONTEXTS, true) ? $context : 'unknown_event'),
        ];

        foreach ($detail as $key => $value) {
            if ($key === 'timeout_seconds' && is_int($value) && $value >= 0 && $value <= 3600) {
                $parts[] = "timeout_seconds={$value}";
            }
        }

        self::write($parts);

        return $correlationId;
    }

    /**
     * ÚNICO ponto do addon que escreve no `error_log`.
     *
     * Centralizado para que "a fronteira é única" seja verificável por
     * varredura, e não uma afirmação. Os oito `error_log()` que existiam fora
     * daqui incluíam dois com PII: o IP do cliente em `TlsEnforcer` e o path do
     * arquivo de rate limit — que embute o IP — em `RateLimiter`.
     *
     * @param array<int, string> $parts
     */
    private static function write(array $parts): void
    {
        error_log(implode(' ', $parts));
    }

    /**
     * Instala o handler de exceção não capturada.
     *
     * Precisa ser chamado logo após o autoload e ANTES do `init.php`: o
     * bootstrap do WHMCS pode lançar (DB fora do ar, config corrompida) e, sem
     * handler, a mensagem crua chega ao handler do PHP.
     */
    public static function installExceptionHandler(string $context): void
    {
        // Warnings/notices posteriores ao autoload também não voltam ao
        // handler padrão do PHP. A mensagem serve apenas de entrada do HMAC;
        // file/line são deliberadamente ignorados para não registrar paths.
        set_error_handler(static function (int $severity, string $message) use ($context): bool {
            if ((error_reporting() & $severity) === 0) {
                return false; // mantém a semântica de @ sem produzir saída
            }

            self::log(null, self::CATEGORY_RUNTIME, $context, rawText: $message);

            return true;
        });

        set_exception_handler(static function (\Throwable $e) use ($context): void {
            $correlationId = self::report(self::CATEGORY_UNHANDLED, $context, $e);

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json');
            }

            // Só texto nosso e a correlação — nunca a mensagem da exceção.
            echo json_encode([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32603,
                    'message' => 'Internal server error. Correlation id: ' . $correlationId,
                ],
                'id' => null,
            ]);
            exit;
        });
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
        ?string $causeFingerprint,
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
        if ($causeFingerprint !== null && $causeFingerprint !== '') {
            $parts[] = 'fingerprint=' . self::safeToken($causeFingerprint);
        }

        self::write($parts);

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
