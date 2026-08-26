<?php
// src/Server.php
namespace NtMcp;

use Nyholm\Psr7\Factory\Psr17Factory;
use NtMcp\Mcp\McpSdkAdapter;
use NtMcp\Mcp\ServerAdapterInterface;
use NtMcp\Mcp\SessionLock;
use NtMcp\Whmcs\Diagnostics;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\SystemUrl;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class Server
{
    /** SECURITY FIX (M-02): teto de corpo — espelhado em McpSdkAdapter::MAX_BODY_BYTES. */
    public const MAX_BODY_BYTES = 1048576;

    /**
     * Fábrica do adapter — substituível em testes (sem WHMCS bootstrapado).
     * @var null|callable(string $adminUser): ServerAdapterInterface
     */
    private static $adapterFactory = null;

    public static function setAdapterFactory(?callable $factory): void
    {
        self::$adapterFactory = $factory;
    }

    /** Diretório data/ (locks de sessão) — substituível em testes. */
    private static ?string $dataDir = null;

    public static function setDataDir(?string $dataDir): void
    {
        self::$dataDir = $dataDir;
    }

    public static function dataDir(): string
    {
        return self::$dataDir ?? (__DIR__ . '/../data');
    }

    /**
     * Verifica se o hostname do request não corresponde ao esperado.
     * Usado para isolamento de desenv/prod quando um token é reaproveitado.
     *
     * @param string|null $configured hostname esperado (da config); trim'd e lowercase.
     *                               Vazio/null significa "qualquer hostname é válido".
     * @param string $actual hostname real do request (já lowercase)
     * @return bool true se houver mismatch (falha-fechada)
     */
    public static function expectedHostMismatch(?string $configured, string $actual): bool
    {
        $configured = trim($configured ?? '');
        if ($configured === '') {
            return false;
        }
        return strtolower($configured) !== $actual;
    }

    public static function run(string $adminUser = ''): void
    {
        // ------------------------------------------------------------------
        // Admin user resolution: prefer per-token admin from authenticate(),
        // fall back to global config. SECURITY (WO-7 consistency): if none is
        // resolvable, fail CLOSED (401) instead of binding the superadmin
        // 'admin' — mirrors BearerAuth::getFallbackAdmin().
        // ------------------------------------------------------------------
        if ($adminUser === '') {
            try {
                $configured = trim(\WHMCS\Config\Setting::getValue('nt_mcp_admin_user') ?? '');
                if ($configured !== '') {
                    $adminUser = $configured;
                }
            } catch (\Throwable $_ex) {
                // Setting not available — leave empty to deny below
            }
            if ($adminUser === '') {
                Diagnostics::event(Diagnostics::CATEGORY_AUTH, 'admin_user_unresolved');
                self::emitJson(401, ['error' => 'Unauthorized: no admin user configured']);
                return;
            }
        }

        // ------------------------------------------------------------------
        // 0b. Isolamento de ambiente: verifica se o hostname do request
        //     corresponde ao esperado na config. Evita que um token
        //     reaproveitado acesse um WHMCS errado sem ninguém perceber.
        // ------------------------------------------------------------------
        if (class_exists('\WHMCS\Config\Setting')) {
            try {
                $expectedHost = trim(\WHMCS\Config\Setting::getValue('nt_mcp_expected_host') ?? '');
                $actualHost = SystemUrl::host();
                if (self::expectedHostMismatch($expectedHost, $actualHost)) {
                    Diagnostics::event(Diagnostics::CATEGORY_CONFIG_READ, 'host_mismatch');
                    self::emitJson(403, ['error' => 'Forbidden: host mismatch', 'code' => 'host_mismatch']);
                    return;
                }
            } catch (\Throwable $_ex) {
                // Config read or host resolution failed — treat as mismatch (fail-closed)
                Diagnostics::event(Diagnostics::CATEGORY_CONFIG_READ, 'host_mismatch');
                self::emitJson(403, ['error' => 'Forbidden: host mismatch', 'code' => 'host_mismatch']);
                return;
            }
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // ------------------------------------------------------------------
        // 1. Método. POST = JSON-RPC; DELETE = encerrar sessão (SDK). GET
        //    (stream SSE) não é oferecido — o endpoint responde só JSON.
        // ------------------------------------------------------------------
        if ($method === 'GET') {
            header('Allow: POST, DELETE');
            self::emitJson(405, ['error' => 'SSE not supported; use POST']);
            return;
        }
        if ($method !== 'POST' && $method !== 'DELETE') {
            header('Allow: POST, DELETE');
            self::emitJson(405, ['error' => 'Method not allowed']);
            return;
        }

        // ------------------------------------------------------------------
        // 2. SECURITY FIX (M-02): corpo limitado a 1 MB, checado pelo header
        //    E pela leitura (cobre Content-Length ausente/mentiroso e chunked).
        // ------------------------------------------------------------------
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > self::MAX_BODY_BYTES) {
            self::emitJson(413, ['error' => 'Request body too large (max 1 MB)']);
            return;
        }
        $input = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if (strlen($input) > self::MAX_BODY_BYTES) {
            self::emitJson(413, ['error' => 'Request body too large (max 1 MB)']);
            return;
        }

        // ------------------------------------------------------------------
        // 3. Batch JSON-RPC (array no topo) é recusado ANTES do SDK: o SDK
        //    aceitaria até 100 chamadas num único request, o que faria 100
        //    tool calls contarem como 1 no RateLimiter.
        // ------------------------------------------------------------------
        if ($method === 'POST' && self::isBatch($input)) {
            self::emitJson(400, [
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => ['code' => -32600, 'message' => 'Batch requests are not supported'],
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 4. PSR-7 → adapter → emissão. Headers/body passam pela fronteira de
        //    output do mcp.php (allowlist de headers).
        // ------------------------------------------------------------------
        $request = self::buildRequest($method, $input);

        // ------------------------------------------------------------------
        // 5. Lock só na era legada. O FileSessionStore do SDK não serializa
        //    requests concorrentes com o mesmo Mcp-Session-Id (lost update e
        //    resposta de A entregue a B). Segura o lock até DEPOIS do emit:
        //    no caminho SSE o body consome a sessão durante a emissão.
        //    initialize e requests stateless 2026-07-28 não carregam esse
        //    header e não precisam de lock. Sem lock quando exigido =
        //    fail-closed (503 + Retry-After), nunca "tenta assim".
        // ------------------------------------------------------------------
        $sessionHeader = trim((string) ($_SERVER['HTTP_MCP_SESSION_ID'] ?? ''));
        $lock = null;
        if ($sessionHeader !== '' || $method === 'DELETE') {
            $lock = new SessionLock(self::dataDir() . '/session-locks');
            if (!$lock->acquire($sessionHeader !== '' ? $sessionHeader : 'delete-without-session')) {
                // mkdir/chmod/fopen quebrados (ownership, disco cheio) e contenção
                // real são causas diferentes; só a segunda se resolve sozinha.
                $context = $lock->lastFailure() === 'timeout' ? 'lock_busy' : 'lock_open_failed';
                Diagnostics::event(Diagnostics::CATEGORY_RUNTIME, $context);
                header('Retry-After: 1');
                self::emitJson(503, ['error' => 'Session busy; retry']);
                return;
            }
        }

        try {
            $adapter = self::$adapterFactory !== null
                ? (self::$adapterFactory)($adminUser)
                : new McpSdkAdapter(new LocalApiClient($adminUser), __DIR__);

            $response = $adapter->handle($request);

            // ------------------------------------------------------------------
            // 6. SSE é a única resposta que pode, ela mesma, esperar por um novo
            //    POST na MESMA sessão (sampling/elicitation via ClientGateway: o
            //    cliente responde num request separado, mesmo Mcp-Session-Id).
            //    Segurar o lock durante a emissão travaria esse POST de volta —
            //    deadlock, não 503 passageiro. Libera ANTES do corpo (stream)
            //    começar a fluir; para respostas JSON normais o lock continua
            //    seguro até depois do emit (é isso que fecha o bug de sessão A).
            // ------------------------------------------------------------------
            if ($lock !== null && str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
                $lock->release();
                $lock = null;
            }

            self::emit($response);
        } finally {
            $lock?->release();
        }
    }

    /** Array JSON no nível raiz (primeiro byte não-branco é `[`). */
    public static function isBatch(string $input): bool
    {
        return preg_match('/^\s*\[/', $input) === 1;
    }

    public static function buildRequest(string $method, string $body, ?array $server = null): ServerRequestInterface
    {
        $server ??= $_SERVER;
        $factory = new Psr17Factory();

        $scheme = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $server['HTTP_HOST'] ?? ($server['SERVER_NAME'] ?? 'localhost');
        $uri    = $scheme . '://' . $host . ($server['REQUEST_URI'] ?? '/');

        $request = $factory->createServerRequest($method, $uri, $server);
        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $request = $request->withHeader($name, $value);
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $request = $request->withHeader(str_replace('_', '-', $key), $value);
            }
        }
        if (!$request->hasHeader('Content-Type') && $method === 'POST') {
            $request = $request->withHeader('Content-Type', 'application/json');
        }
        if (!$request->hasHeader('Accept')) {
            $request = $request->withHeader('Accept', 'application/json');
        }

        return $request->withBody($factory->createStream($body));
    }

    public static function emit(ResponseInterface $response): void
    {
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value, false);
            }
        }
        echo (string) $response->getBody();
    }

    private static function emitJson(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
