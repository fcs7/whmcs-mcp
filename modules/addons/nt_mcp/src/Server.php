<?php
// src/Server.php
namespace NtMcp;

use Nyholm\Psr7\Factory\Psr17Factory;
use NtMcp\Mcp\McpSdkAdapter;
use NtMcp\Mcp\ServerAdapterInterface;
use NtMcp\Whmcs\CapsuleClient;
use NtMcp\Whmcs\Diagnostics;
use NtMcp\Whmcs\LocalApiClient;
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

        $adapter = self::$adapterFactory !== null
            ? (self::$adapterFactory)($adminUser)
            : new McpSdkAdapter(new LocalApiClient($adminUser), new CapsuleClient(), __DIR__);

        self::emit($adapter->handle($request));
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
