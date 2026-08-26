<?php

declare(strict_types=1);

namespace NtMcp\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Smokes HTTP reais do árbitro terminal e do snapshot imutável. */
final class McpEndpointHttpTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function test_mcp_endpoint_does_not_bootstrap_as_client_area_but_oauth_still_does(): void
    {
        $module = dirname(__DIR__, 2);
        $mcp = (string) file_get_contents($module . '/mcp.php');
        $oauth = (string) file_get_contents($module . '/oauth.php');

        $this->assertStringNotContainsString("define('CLIENTAREA', true)", $mcp);
        $this->assertStringContainsString("define('CLIENTAREA', true)", $oauth);
    }

    /** @var list<string> */
    private array $sandboxes = [];

    /** @var list<array{process:resource,pipes:array<int,resource>,port:int}> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            @proc_terminate($server['process']);
            foreach ($server['pipes'] as $pipe) {
                @fclose($pipe);
            }
            @proc_close($server['process']);
        }
        $this->servers = [];

        foreach ($this->sandboxes as $root) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($root);
        }
    }

    #[DataProvider('guardFlowProvider')]
    public function test_real_guard_flows_preserve_closed_status_body_and_headers(
        array $sandboxOptions,
        string $method,
        array $requestHeaders,
        int $expectedStatus,
        string $expectedBody,
        array $expectedHeaders,
        array $absentHeaders = [],
        bool $expectContentLength = true,
    ): void {
        $root = $this->sandbox(...$sandboxOptions);
        $server = $this->startServer($root);
        $response = $this->request($server, $method, $requestHeaders, $method === 'POST' ? '{}' : '');

        $diagnostics = (string) stream_get_contents($server['pipes'][2]);
        $this->assertSame($expectedStatus, $response['status'], json_encode($response) . "\n" . $diagnostics);
        $this->assertSame($expectedBody, $response['body']);
        if ($expectContentLength) {
            $this->assertSame((string) strlen($expectedBody), $response['headers']['content-length'] ?? null);
        } else {
            $this->assertArrayNotHasKey('content-length', $response['headers']);
        }
        foreach ($expectedHeaders as $name => $value) {
            $this->assertSame($value, $response['headers'][strtolower($name)] ?? null, $name);
        }
        foreach ($absentHeaders as $name) {
            $this->assertArrayNotHasKey(strtolower($name), $response['headers']);
        }
        $this->assertStringNotContainsString('Internal server error', $response['body']);
    }

    public static function guardFlowProvider(): array
    {
        return [
            'OPTIONS CORS' => [
                ['settings' => ['nt_mcp_cors_origins' => 'https://client.example']],
                'OPTIONS',
                [
                    'Origin' => 'https://client.example',
                    'Access-Control-Request-Method' => 'POST',
                    'Access-Control-Request-Headers' => 'Content-Type, Authorization',
                ],
                204,
                '',
                [
                    'Access-Control-Allow-Origin' => 'https://client.example',
                    'Access-Control-Allow-Methods' => 'POST, DELETE, OPTIONS',
                    'Access-Control-Expose-Headers' => 'MCP-Session-Id',
                    'Vary' => 'Origin',
                ],
                [],
                false,
            ],
            'TLS 421' => [
                ['secure' => false],
                'POST',
                [],
                421,
                '{"error":"TLS required. Plain HTTP requests are rejected for security."}',
                ['Content-Type' => 'application/json'],
            ],
            'bearer 401' => [
                [],
                'POST',
                [],
                401,
                '{"error":"Unauthorized"}',
                [
                    'Content-Type' => 'application/json',
                    'WWW-Authenticate' => 'Bearer resource_metadata="https://whmcs.example/modules/addons/nt_mcp/oauth.php/resource-metadata"',
                ],
            ],
            'IP 403' => [
                ['settings' => ['nt_mcp_allowed_ips' => '203.0.113.10']],
                'POST',
                [],
                403,
                '{"error":"Forbidden: IP address not in allowlist."}',
                ['Content-Type' => 'application/json'],
            ],
            'CORS 403' => [
                ['settings' => ['nt_mcp_cors_origins' => 'https://client.example']],
                'POST',
                ['Origin' => 'https://evil.example'],
                403,
                '{"error":"Forbidden: origin not allowed."}',
                ['Content-Type' => 'application/json'],
                ['Access-Control-Allow-Origin'],
            ],
            'CORS preflight origin denied' => [
                ['settings' => ['nt_mcp_cors_origins' => 'https://client.example']],
                'OPTIONS',
                [
                    'Origin' => 'https://evil.example',
                    'Access-Control-Request-Method' => 'POST',
                ],
                403,
                '{"error":"Forbidden: origin not allowed."}',
                ['Content-Type' => 'application/json'],
                ['Access-Control-Allow-Origin'],
            ],
            'CORS preflight method denied' => [
                ['settings' => ['nt_mcp_cors_origins' => 'https://client.example']],
                'OPTIONS',
                [
                    'Origin' => 'https://client.example',
                    'Access-Control-Request-Method' => 'PUT',
                ],
                403,
                '{"error":"Forbidden: origin not allowed."}',
                ['Content-Type' => 'application/json'],
                ['Access-Control-Allow-Origin'],
            ],
            'CORS preflight header denied' => [
                ['settings' => ['nt_mcp_cors_origins' => 'https://client.example']],
                'OPTIONS',
                [
                    'Origin' => 'https://client.example',
                    'Access-Control-Request-Method' => 'POST',
                    'Access-Control-Request-Headers' => 'Content-Type, X-Poison',
                ],
                403,
                '{"error":"Forbidden: origin not allowed."}',
                ['Content-Type' => 'application/json'],
                ['Access-Control-Allow-Origin'],
            ],
            'config 503' => [
                ['throwSetting' => 'nt_mcp_cors_origins'],
                'POST',
                [],
                503,
                '{"error":"Service temporarily unavailable."}',
                ['Content-Type' => 'application/json'],
            ],
        ];
    }

    public function test_real_oauth_preflight_omits_content_length(): void
    {
        $root = $this->sandbox(settings: ['nt_mcp_cors_origins' => 'https://client.example']);
        $server = $this->startServer($root);
        $response = $this->request(
            $server,
            'OPTIONS',
            [
                'Origin' => 'https://client.example',
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'Content-Type, Authorization',
            ],
            '',
            'oauth.php',
        );

        $this->assertSame(204, $response['status']);
        $this->assertSame('', $response['body']);
        $this->assertArrayNotHasKey('content-length', $response['headers']);
        $this->assertSame('https://client.example', $response['headers']['access-control-allow-origin'] ?? null);
        $this->assertSame('GET, POST, OPTIONS', $response['headers']['access-control-allow-methods'] ?? null);
        $this->assertSame('Origin', $response['headers']['vary'] ?? null);
    }

    #[DataProvider('realRequestedHeadersProvider')]
    public function test_real_mcp_preflight_canonicalizes_requested_headers(
        ?string $requestedHeaders,
        int $expectedStatus,
        bool $forceSapiValue = false,
    ): void {
        $root = $this->sandbox(
            settings: ['nt_mcp_cors_origins' => 'https://client.example'],
            forcedRequestedHeaders: $forceSapiValue ? $requestedHeaders : null,
        );
        $server = $this->startServer($root);
        $headers = [
            'Origin' => 'https://client.example',
            'Access-Control-Request-Method' => 'POST',
        ];
        if ($requestedHeaders !== null && !$forceSapiValue) {
            $headers['Access-Control-Request-Headers'] = $requestedHeaders;
        }

        $response = $this->request($server, 'OPTIONS', $headers);

        $this->assertSame($expectedStatus, $response['status'], json_encode($response));
        if ($expectedStatus === 204) {
            $this->assertSame('', $response['body']);
            $this->assertArrayNotHasKey('content-length', $response['headers']);
            $this->assertSame('https://client.example', $response['headers']['access-control-allow-origin'] ?? null);
        } else {
            $this->assertSame('{"error":"Forbidden: origin not allowed."}', $response['body']);
            $this->assertArrayNotHasKey('access-control-allow-origin', $response['headers']);
        }
    }

    public static function realRequestedHeadersProvider(): array
    {
        return [
            'absent' => [null, 204],
            'empty' => ['', 403],
            'OWS only' => [" \t ", 403],
            'duplicate exact' => ['Content-Type, Content-Type', 403],
            'duplicate case-insensitive' => ['Content-Type, content-type', 403],
            'duplicate with OWS' => [" Content-Type\t,\t CONTENT-TYPE ", 403],
            'allowed case and OWS' => [" authorization ,\tCONTENT-type, McP-PrOtOcOl-VeRsIoN , mcp-session-ID ", 204],
            'Last-Event-ID remains denied' => ['Last-Event-ID', 403],
            'trailing comma' => ['Content-Type,', 403],
            // O servidor embutido normaliza/rejeita CRLF antes de preencher
            // $_SERVER; o bootstrap força o valor SAPI adversarial e o request
            // ainda atravessa o endpoint HTTP real completo.
            'CRLF SAPI value' => ["Content-Type\r\nX-Poison: yes", 403, true],
        ];
    }

    public function test_real_rate_limit_preserves_429_and_retry_after(): void
    {
        $root = $this->sandbox();
        $server = $this->startServer($root);

        for ($request = 1; $request <= 60; $request++) {
            $response = $this->request($server, 'POST', [], '{}');
            $this->assertSame(401, $response['status'], "request {$request}");
        }
        $limited = $this->request($server, 'POST', [], '{}');

        $this->assertSame(429, $limited['status']);
        $this->assertSame('60', $limited['headers']['retry-after'] ?? null);
        $this->assertSame('application/json', $limited['headers']['content-type'] ?? null);
        $this->assertSame('{"error":"Rate limit exceeded. Try again later."}', $limited['body']);
        $this->assertSame((string) strlen($limited['body']), $limited['headers']['content-length'] ?? null);
    }

    public function test_terminal_snapshot_purges_bootstrap_and_late_shutdown_contamination(): void
    {
        $root = $this->sandbox(contaminateBootstrap: true, contaminateShutdown: true);
        $server = $this->startServer($root);
        $response = $this->request($server, 'POST', [], '{}');

        $this->assertSame(401, $response['status']);
        $this->assertSame('{"error":"Unauthorized"}', $response['body']);
        $this->assertSame((string) strlen($response['body']), $response['headers']['content-length'] ?? null);
        foreach (['x-bootstrap-secret', 'x-late-secret'] as $header) {
            $this->assertArrayNotHasKey($header, $response['headers']);
        }
        foreach (['bootstrap-secret', 'late-secret', '<html>', 'second-json'] as $poison) {
            $this->assertStringNotContainsString($poison, $response['body']);
        }
    }

    public function test_real_initialize_tools_list_and_tools_call_are_snapshotted_exactly(): void
    {
        $root = $this->sandbox(
            settings: ['nt_mcp_cors_origins' => 'https://client.example'],
            contaminateBootstrap: true,
            contaminateShutdown: true,
        );
        $server = $this->startServer($root);
        $headers = [
            'Authorization' => 'Bearer ' . self::TOKEN,
            'Origin' => 'https://client.example',
            'Content-Type' => 'application/json',
        ];

        $initialize = $this->request($server, 'POST', $headers, json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'http-probe', 'version' => '1.0'],
            ],
        ], JSON_UNESCAPED_SLASHES));
        $sessionId = $initialize['headers']['mcp-session-id'] ?? '';
        $this->assertSnapshotResponse($initialize, 1);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._\-]{8,128}\z/', $sessionId);

        $headers['MCP-Session-Id'] = $sessionId;
        $list = $this->request($server, 'POST', $headers, json_encode([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass(),
        ]));
        $listPayload = $this->assertSnapshotResponse($list, 2);
        $this->assertCount(70, $listPayload['result']['tools'] ?? []);
        $this->assertSame($sessionId, $list['headers']['mcp-session-id'] ?? null);

        $call = $this->request($server, 'POST', $headers, json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'whmcs_list_clients', 'arguments' => ['limitnum' => 1]],
        ]));
        $callPayload = $this->assertSnapshotResponse($call, 3);
        $this->assertArrayHasKey('result', $callPayload, json_encode($callPayload));
        $this->assertSame($sessionId, $call['headers']['mcp-session-id'] ?? null);
    }

    /**
     * DELETE encerra a sessão (SDK) e precisa passar pelo preflight do browser:
     * OPTIONS com Access-Control-Request-Method: DELETE → 204 com o perfil
     * POST, DELETE, OPTIONS; DELETE autenticado → 200; POST seguinte → 404.
     */
    public function test_real_delete_session_flow_with_browser_preflight(): void
    {
        $root = $this->sandbox(settings: ['nt_mcp_cors_origins' => 'https://client.example']);
        $server = $this->startServer($root);
        $headers = [
            'Authorization' => 'Bearer ' . self::TOKEN,
            'Origin' => 'https://client.example',
            'Content-Type' => 'application/json',
        ];

        $preflight = $this->request($server, 'OPTIONS', [
            'Origin' => 'https://client.example',
            'Access-Control-Request-Method' => 'DELETE',
            'Access-Control-Request-Headers' => 'authorization, mcp-session-id',
        ]);
        $this->assertSame(204, $preflight['status']);
        $this->assertSame('POST, DELETE, OPTIONS', $preflight['headers']['access-control-allow-methods'] ?? null);

        $initialize = $this->request($server, 'POST', $headers, json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'p', 'version' => '1']],
        ]));
        $payload = $this->assertSnapshotResponse($initialize, 1);
        $this->assertSame('2025-11-25', $payload['result']['protocolVersion'] ?? null);
        $sessionId = $initialize['headers']['mcp-session-id'] ?? '';
        $this->assertNotSame('', $sessionId);

        $headers['MCP-Session-Id'] = $sessionId;
        $delete = $this->request($server, 'DELETE', $headers);
        $this->assertSame(200, $delete['status']);

        $after = $this->request($server, 'POST', $headers, json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']));
        $this->assertSame(404, $after['status']);
        $this->assertSame(-32600, json_decode($after['body'], true)['error']['code'] ?? null);
    }

    public function test_real_invalid_protocol_version_header_is_400(): void
    {
        $root = $this->sandbox(settings: ['nt_mcp_cors_origins' => 'https://client.example']);
        $server = $this->startServer($root);
        $headers = ['Authorization' => 'Bearer ' . self::TOKEN, 'Origin' => 'https://client.example', 'Content-Type' => 'application/json'];

        $initialize = $this->request($server, 'POST', $headers, json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'p', 'version' => '1']],
        ]));
        $headers['MCP-Session-Id'] = $initialize['headers']['mcp-session-id'] ?? '';

        $bad = $this->request($server, 'POST', $headers + ['MCP-Protocol-Version' => 'not-a-version'], json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']));
        $this->assertSame(400, $bad['status']);
        $this->assertSame('application/json', $bad['headers']['content-type'] ?? null);
        $this->assertSame(-32602, json_decode($bad['body'], true)['error']['code'] ?? null);
        $this->assertSame((string) strlen($bad['body']), $bad['headers']['content-length'] ?? null);

        $good = $this->request($server, 'POST', $headers + ['MCP-Protocol-Version' => '2025-11-25'], json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping']));
        $this->assertSnapshotResponse($good, 3);
    }

    public function test_real_server_405_and_413_remain_protocol_responses(): void
    {
        $root = $this->sandbox();
        $server = $this->startServer($root);
        $headers = ['Authorization' => 'Bearer ' . self::TOKEN, 'Content-Type' => 'application/json'];

        $method = $this->request($server, 'GET', $headers);
        $this->assertSame(405, $method['status']);
        $this->assertSame('POST, DELETE', $method['headers']['allow'] ?? null);
        $this->assertSame('{"error":"SSE not supported; use POST"}', $method['body']);
        $this->assertSame((string) strlen($method['body']), $method['headers']['content-length'] ?? null);

        $oversized = $this->request($server, 'POST', $headers, str_repeat('x', 1048577));
        $this->assertSame(413, $oversized['status']);
        $this->assertSame('{"error":"Request body too large (max 1 MB)"}', $oversized['body']);
        $this->assertSame((string) strlen($oversized['body']), $oversized['headers']['content-length'] ?? null);
    }

    /**
     * Batch JSON-RPC (array no topo) e recusado ANTES do SDK: cada request
     * conta uma vez no rate limiter, entao 100 chamadas num corpo so nao
     * podem passar por uma.
     */
    public function test_real_server_rejects_jsonrpc_batch_before_sdk(): void
    {
        $root = $this->sandbox();
        $server = $this->startServer($root);
        $response = $this->request(
            $server,
            'POST',
            ['Authorization' => 'Bearer ' . self::TOKEN, 'Content-Type' => 'application/json'],
            ' [{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}]'
        );

        $this->assertSame(400, $response['status']);
        $this->assertSame('application/json', $response['headers']['content-type'] ?? null);
        $payload = json_decode($response['body'], true);
        $this->assertSame(-32600, $payload['error']['code'] ?? null);
        $this->assertSame('Batch requests are not supported', $payload['error']['message'] ?? null);
        $this->assertSame((string) strlen($response['body']), $response['headers']['content-length'] ?? null);
    }

    public function test_guards_have_no_body_writer_or_exit_token(): void
    {
        $module = dirname(__DIR__, 2);
        foreach ([
            'src/Http/TlsEnforcer.php',
            'src/Http/CorsHandler.php',
            'src/Http/IpAllowlist.php',
            'src/Security/RateLimiter.php',
            'src/Auth/BearerAuth.php',
        ] as $relative) {
            $tokens = token_get_all((string) file_get_contents($module . '/' . $relative));
            $forbidden = array_filter($tokens, static fn (mixed $token): bool => is_array($token)
                && in_array($token[0], [T_ECHO, T_EXIT], true));
            $this->assertSame([], array_values($forbidden), $relative);
        }
    }

    /** @return array<string, mixed> */
    private function assertSnapshotResponse(array $response, int $id): array
    {
        $this->assertSame(200, $response['status']);
        $this->assertSame('application/json', $response['headers']['content-type'] ?? null);
        $this->assertSame((string) strlen($response['body']), $response['headers']['content-length'] ?? null);
        $this->assertSame('https://client.example', $response['headers']['access-control-allow-origin'] ?? null);
        $this->assertSame('Origin', $response['headers']['vary'] ?? null);
        $this->assertArrayNotHasKey('x-bootstrap-secret', $response['headers']);
        $this->assertArrayNotHasKey('x-late-secret', $response['headers']);
        foreach (['bootstrap-secret', 'late-secret', '<html>', 'second-json'] as $poison) {
            $this->assertStringNotContainsString($poison, $response['body']);
        }

        $payload = json_decode($response['body'], true);
        $this->assertIsArray($payload, $response['body']);
        $this->assertSame($id, $payload['id'] ?? null);
        $this->assertSame(1, substr_count($response['body'], '"jsonrpc"'));

        return $payload;
    }

    private function sandbox(
        array $settings = [],
        bool $secure = true,
        ?string $throwSetting = null,
        bool $contaminateBootstrap = false,
        bool $contaminateShutdown = false,
        ?string $forcedRequestedHeaders = null,
    ): string {
        $root = sys_get_temp_dir() . '/nt_mcp_http_' . bin2hex(random_bytes(6));
        $module = $root . '/modules/addons/nt_mcp';
        mkdir($module . '/vendor', 0700, true);
        file_put_contents($module . '/mcp.php', (string) file_get_contents(dirname(__DIR__, 2) . '/mcp.php'));
        file_put_contents($module . '/oauth.php', (string) file_get_contents(dirname(__DIR__, 2) . '/oauth.php'));
        file_put_contents(
            $module . '/vendor/autoload.php',
            '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
        );

        $defaults = [
            'nt_mcp_cors_origins' => '',
            'nt_mcp_allowed_ips' => '',
            'nt_mcp_trusted_proxies' => '',
            'TrustedProxyIps' => '',
            'nt_mcp_bearer_token' => hash('sha256', self::TOKEN),
            'nt_mcp_bearer_token_admin' => 'testadmin',
            'nt_mcp_admin_user' => 'testadmin',
            'nt_mcp_disable_static_bearer' => '',
            'SystemURL' => 'https://whmcs.example',
        ];
        $values = var_export(array_replace($defaults, $settings), true);
        $throw = var_export($throwSetting, true);
        $rateFile = var_export($root . '/transient.json', true);
        $secureCode = $secure ? '$_SERVER[\'HTTPS\'] = \'on\';' : 'unset($_SERVER[\'HTTPS\'], $_SERVER[\'HTTP_X_FORWARDED_PROTO\']);';
        $requestedHeadersCode = $forcedRequestedHeaders === null
            ? ''
            : '$_SERVER[\'HTTP_ACCESS_CONTROL_REQUEST_HEADERS\'] = ' . var_export($forcedRequestedHeaders, true) . ';';
        $bootstrapPoison = $contaminateBootstrap
            ? 'http_response_code(418); header("Content-Type: text/html"); header("Content-Length: 999"); header("X-Bootstrap-Secret: present"); echo "bootstrap-secret<html>";'
            : '';
        $shutdownPoison = $contaminateShutdown
            ? 'register_shutdown_function(static function (): void { http_response_code(418); header("Content-Type: text/html"); header("Content-Length: 999"); header("X-Late-Secret: present"); echo "late-secret<html>{\\"second-json\\":true}"; });'
            : '';

        $bootstrap = '<?php '
            . 'namespace WHMCS\\Config { final class Setting { public static function getValue(string $key): mixed {'
            . '$values = ' . $values . '; $throw = ' . $throw . '; if ($key === $throw) { throw new \\RuntimeException("config poison"); } '
            . 'return $values[$key] ?? ""; } } } '
            . 'namespace Illuminate\\Database\\Capsule { final class Manager { public static function table(string $table): object { '
            . 'return new class { public function where(mixed ...$args): self { return $this; } public function exists(): bool { return true; } }; } } } '
            . 'namespace WHMCS { final class TransientData { private static ?self $instance = null; public static function getInstance(): self { return self::$instance ??= new self(); } '
            . 'public function retrieve(string $key): mixed { $all = is_file(' . $rateFile . ') ? json_decode((string) file_get_contents(' . $rateFile . '), true) : []; return $all[$key] ?? false; } '
            . 'public function store(string $key, string $value, int $ttl): void { $all = is_file(' . $rateFile . ') ? json_decode((string) file_get_contents(' . $rateFile . '), true) : []; '
            . 'if (!is_array($all)) { $all = []; } $all[$key] = $value; file_put_contents(' . $rateFile . ', json_encode($all), LOCK_EX); } } } '
            . 'namespace { ' . $secureCode . $requestedHeadersCode . $bootstrapPoison . $shutdownPoison
            . 'function localAPI(string $command, array $params = [], string $admin = ""): array { return ["result" => "success", "command" => $command, "admin" => $admin]; } }';
        file_put_contents($root . '/init.php', $bootstrap);

        $this->sandboxes[] = $root;

        return $root;
    }

    /** @return array{process:resource,pipes:array<int,resource>,port:int} */
    private function startServer(string $root): array
    {
        $reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($reservation, "porta local indisponível: {$errno} {$error}");
        $address = (string) stream_socket_get_name($reservation, false);
        fclose($reservation);
        $port = (int) substr(strrchr($address, ':'), 1);

        $command = [PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=1', '-S', "127.0.0.1:{$port}", '-t', $root];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $server = ['process' => $process, 'pipes' => $pipes, 'port' => $port];
        $this->servers[] = $server;

        $deadline = microtime(true) + 3.0;
        do {
            $probe = @stream_socket_client("tcp://127.0.0.1:{$port}", $connectErrno, $connectError, 0.1);
            if (is_resource($probe)) {
                fclose($probe);
                return $server;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        $this->fail("servidor HTTP não iniciou: {$connectErrno} {$connectError}");
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function request(
        array $server,
        string $method,
        array $headers = [],
        string $body = '',
        string $script = 'mcp.php',
    ): array
    {
        $client = stream_socket_client("tcp://127.0.0.1:{$server['port']}", $errno, $error, 3);
        $this->assertIsResource($client, "falha HTTP: {$errno} {$error}");
        $headers = ['Host' => '127.0.0.1', 'Connection' => 'close'] + $headers;
        if ($body !== '') {
            $headers['Content-Length'] = (string) strlen($body);
        }
        $rawRequest = $method . " /modules/addons/nt_mcp/{$script} HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            $rawRequest .= $name . ': ' . $value . "\r\n";
        }
        $rawRequest .= "\r\n" . $body;
        $offset = 0;
        while ($offset < strlen($rawRequest)) {
            $written = fwrite($client, substr($rawRequest, $offset));
            $this->assertNotFalse($written);
            $offset += $written;
        }
        stream_set_timeout($client, 30);
        $raw = (string) stream_get_contents($client);
        fclose($client);
        $diagnostics = (string) stream_get_contents($server['pipes'][2]);
        $this->assertNotSame('', $raw, $diagnostics);

        [$headerBlock, $responseBody] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $headerBlock);
        $statusLine = array_shift($lines) ?? '';
        preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $statusLine, $match);
        $responseHeaders = [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }

        return [
            'status' => isset($match[1]) ? (int) $match[1] : 0,
            'headers' => $responseHeaders,
            'body' => $responseBody,
        ];
    }
}
