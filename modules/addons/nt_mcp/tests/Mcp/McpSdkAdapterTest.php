<?php

declare(strict_types=1);

namespace NtMcp\Tests\Mcp;

use NtMcp\Crm\MgCrmRepository;
use NtMcp\Mcp\McpSdkAdapter;
use NtMcp\Tests\Support\FakeAdminIdentityResolver;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeCrmQueryPort;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use NtMcp\Whmcs\AuthorizationException;
use NtMcp\Whmcs\LocalApiClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpSdkAdapterTest extends TestCase
{
    private string $tempDir;
    private LocalApiClient $api;
    private string $baseDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mcp-test-' . bin2hex(random_bytes(8));
        @mkdir($this->tempDir, 0700, true);

        $this->api = new LocalApiClient('testadmin');
        $this->api->setGates(['write' => true, 'destructive' => false]);
        $this->api->setCallable(static fn($cmd, $params) => ['result' => 'success', 'cmd' => $cmd]);
        $this->api->setAdminIdResolver(static fn($_) => 7);

        $this->baseDir = __DIR__ . '/../../src';

        FakeCapsule::reset();
    }

    protected function tearDown(): void
    {
        array_map(static fn($f) => @unlink($f), glob($this->tempDir . '/**/*', GLOB_NOSORT));
        @rmdir($this->tempDir . '/sessions');
        @rmdir($this->tempDir . '/cache');
        @rmdir($this->tempDir);
    }

    /**
     * O servidor expõe SÓ tools. A autodetecção do SDK anuncia prompts,
     * resources, logging e completions só porque usamos discovery por atributo
     * ("fonte opaca") — e o cliente então renderiza seções vazias, que o
     * usuário lê como feature quebrada (reportado no Cursor). Este teste trava
     * o contrato: se alguém registrar um prompt/resource de verdade, ligar a
     * flag em McpSdkAdapter::capabilities() e ajustar aqui — de propósito, não
     * por acidente.
     */
    #[Test]
    public function initialize_advertises_only_the_capabilities_actually_implemented(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        $request = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
                ],
            ])));

        $body = json_decode((string) $adapter->handle($request)->getBody(), true);
        $advertised = $body['result']['capabilities'] ?? null;

        self::assertIsArray($advertised);
        self::assertArrayHasKey('tools', $advertised);
        foreach (['prompts', 'resources', 'logging', 'completions'] as $unimplemented) {
            self::assertArrayNotHasKey(
                $unimplemented,
                $advertised,
                sprintf('"%s" nao esta implementado e nao pode ser anunciado', $unimplemented)
            );
        }
    }

    #[Test]
    public function initialize_returns_200_with_session_id_and_protocol_version(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        $request = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
                ],
            ])));

        $response = $adapter->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($response->getHeaderLine('Mcp-Session-Id'));
        $sessionId = $response->getHeaderLine('Mcp-Session-Id');
        // UUID format check
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $sessionId);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('result', $body);
        $this->assertArrayHasKey('protocolVersion', $body['result']);
    }

    #[Test]
    public function tools_list_returns_exactly_70_tools_with_whmcs_prefix(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        $initRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ])));

        $initResponse = $adapter->handle($initRequest);
        $sessionId = $initResponse->getHeaderLine('Mcp-Session-Id');

        $listRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
            ])));

        $listResponse = $adapter->handle($listRequest);
        $body = json_decode((string) $listResponse->getBody(), true);

        $tools = $body['result']['tools'] ?? [];
        $this->assertCount(70, $tools);

        foreach ($tools as $tool) {
            $this->assertStringStartsWith('whmcs_', $tool['name']);
        }

        $byName = array_column($tools, null, 'name');
        foreach (['whmcs_crm_list_contacts', 'whmcs_crm_get_contact', 'whmcs_crm_list_followups', 'whmcs_crm_get_kanban'] as $read) {
            $this->assertArrayHasKey($read, $byName);
        }
        foreach (['whmcs_crm_create_lead', 'whmcs_crm_update_contact', 'whmcs_crm_add_followup', 'whmcs_crm_add_note'] as $removed) {
            $this->assertArrayNotHasKey($removed, $byName);
        }

        foreach ([
            'whmcs_list_clients', 'whmcs_get_client', 'whmcs_get_client_invoices',
            'whmcs_list_invoices', 'whmcs_list_tickets',
            'whmcs_list_orders', 'whmcs_get_order', 'whmcs_get_products',
            'whmcs_list_quotes', 'whmcs_get_quote',
        ] as $withFields) {
            $schema = $byName[$withFields]['inputSchema']['properties']['fields'] ?? null;
            $this->assertIsArray($schema, "{$withFields} não publicou fields");
            $this->assertSame(['lite', 'full'], $schema['enum'] ?? null, "{$withFields} publicou enum incorreto");
            $this->assertSame('lite', $schema['default'] ?? null, "{$withFields} publicou default incorreto");
        }

        $includeUrls = $byName['whmcs_get_products']['inputSchema']['properties']['include_urls'] ?? null;
        $this->assertIsArray($includeUrls, 'whmcs_get_products não publicou include_urls');
        $this->assertSame('boolean', $includeUrls['type'] ?? null);
        $this->assertFalse($includeUrls['default'] ?? true);

        $completed = $byName['whmcs_list_projects']['inputSchema']['properties']['completed'] ?? null;
        $this->assertIsArray($completed, 'whmcs_list_projects não publicou completed');
        $this->assertNotSame(false, $completed['default'] ?? null, 'completed omitido não pode publicar default=false');
    }

    #[Test]
    public function tools_call_whmcs_get_client_invokes_localapi(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        $initRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ])));

        $initResponse = $adapter->handle($initRequest);
        $sessionId = $initResponse->getHeaderLine('Mcp-Session-Id');

        $callRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'whmcs_get_client',
                    'arguments' => ['clientid' => 5, 'fields' => 'full'],
                ],
            ])));

        $callResponse = $adapter->handle($callRequest);
        $this->assertSame(200, $callResponse->getStatusCode());

        $body = json_decode((string) $callResponse->getBody(), true);
        $this->assertArrayHasKey('result', $body);
        $this->assertArrayHasKey('content', $body['result']);
        $this->assertCount(1, $body['result']['content']);

        $content = $body['result']['content'][0];
        $this->assertSame('text', $content['type']);
        // isError vive no nível do result (CallToolResult), não no content;
        // a chave PRECISA existir — `?? false` deixaria passar a ausência dela.
        $this->assertArrayHasKey('isError', $body['result']);
        $this->assertFalse($body['result']['isError']);

        $text = json_decode($content['text'], true);
        $this->assertArrayHasKey('cmd', $text);
        $this->assertSame('GetClientsDetails', $text['cmd']);
    }

    #[Test]
    public function crm_read_tools_publish_schema_with_additional_properties_false(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        $initRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ])));

        $adapter->handle($initRequest);

        $listRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', 'dummy')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
            ])));

        // We need to capture the session ID from the init response
        $initRequest2 = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ])));

        $initResp = $adapter->handle($initRequest2);
        $sessionId = $initResp->getHeaderLine('Mcp-Session-Id');

        $listRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
            ])));

        $listResponse = $adapter->handle($listRequest);
        $body = json_decode((string) $listResponse->getBody(), true);
        $tools = $body['result']['tools'] ?? [];

        $crmTools = [
            'whmcs_crm_list_contacts',
            'whmcs_crm_get_contact',
            'whmcs_crm_list_followups',
            'whmcs_crm_get_kanban',
        ];

        foreach ($crmTools as $toolName) {
            $tool = array_values(array_filter($tools, fn($t) => $t['name'] === $toolName))[0] ?? null;
            $this->assertNotNull($tool, "Tool $toolName not found");

            $schema = $tool['inputSchema'] ?? [];
            $this->assertFalse($schema['additionalProperties'] ?? true, "$toolName should have additionalProperties=false");
        }
    }

    #[Test]
    public function crm_read_tools_have_minimum_bounds(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        $initRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ])));

        $initResp = $adapter->handle($initRequest);
        $sessionId = $initResp->getHeaderLine('Mcp-Session-Id');

        $listRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
            ])));

        $listResponse = $adapter->handle($listRequest);
        $body = json_decode((string) $listResponse->getBody(), true);
        $tools = $body['result']['tools'] ?? [];

        $kanban = array_values(array_filter($tools, fn($t) => $t['name'] === 'whmcs_crm_get_kanban'))[0] ?? null;
        $this->assertNotNull($kanban);

        $schema = $kanban['inputSchema'];
        $this->assertSame(1, $schema['properties']['type_id']['minimum'] ?? null);
        $this->assertSame(1, $schema['properties']['limit_per_status']['minimum'] ?? null);
        $this->assertSame(1, $schema['properties']['status_limit']['minimum'] ?? null);
        $this->assertSame(25, $schema['properties']['status_limit']['maximum'] ?? null);
        $this->assertSame(0, $schema['properties']['status_offset']['minimum'] ?? null);
    }

    #[Test]
    public function unknown_argument_returns_json_rpc_error_32602(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        $initRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ])));

        $initResp = $adapter->handle($initRequest);
        $sessionId = $initResp->getHeaderLine('Mcp-Session-Id');

        $callRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'whmcs_crm_get_kanban',
                    'arguments' => ['type' => 'lead'], // unknown arg, should be type_id
                ],
            ])));

        $response = $adapter->handle($callRequest);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('error', $body);
        $this->assertSame(-32602, $body['error']['code']);
        // The error message should mention either additionalProperties or the invalid parameter
        $message = $body['error']['message'];
        $this->assertTrue(
            str_contains($message, 'additionalProperties') || str_contains($message, 'Additional'),
            "Error message should mention additionalProperties: {$message}"
        );
    }

    #[Test]
    public function resource_id_zero_returns_json_rpc_error_32602(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        $initRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ])));

        $initResp = $adapter->handle($initRequest);
        $sessionId = $initResp->getHeaderLine('Mcp-Session-Id');

        $callRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'whmcs_crm_get_contact',
                    'arguments' => ['resource_id' => 0],
                ],
            ])));

        $response = $adapter->handle($callRequest);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('error', $body);
        $this->assertSame(-32602, $body['error']['code']);
    }

    #[Test]
    public function crm_repository_exception_returns_error_with_error_code(): void
    {
        // Repositório CRM REAL com port que falha: a tool whmcs_crm_get_contact
        // deve devolver um CallToolResult isError:true com o envelope canônico
        // (error_code), sem SQLSTATE, path, segredo ou mensagem crua.
        $raw = new \RuntimeException(
            'SQLSTATE[HY000] [1045] Access denied for user \'crm\'@\'10.0.0.5\' (using password: YES) '
            . 'in /var/www/html/modules/addons/nt_mcp/src/Crm/CapsuleQueryPort.php'
        );
        $port = (new FakeCrmQueryPort())->failWithRaw($raw);
        $repo = new MgCrmRepository(
            new \NtMcp\Crm\CrmSchemaGuard(FakeCrmSchemaProbe::healthy()),
            $port,
            FakeAdminIdentityResolver::resolvingTo(7),
        );

        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir, $repo);
        $sessionId = $this->initialize($adapter);

        $response = $adapter->handle($this->call($sessionId, 3, 'whmcs_crm_get_contact', ['resource_id' => 1]));
        $this->assertSame(200, $response->getStatusCode());
        $full = (string) $response->getBody();
        $body = json_decode($full, true);

        $this->assertSame('2.0', $body['jsonrpc'] ?? null);
        $this->assertSame(3, $body['id'] ?? null);
        $this->assertArrayHasKey('result', $body);
        $this->assertTrue($body['result']['isError'] ?? false);
        $envelope = json_decode((string) ($body['result']['content'][0]['text'] ?? ''), true);
        $this->assertSame('error', $envelope['result'] ?? null);
        $this->assertSame('downstream', $envelope['error_code'] ?? null);
        $this->assertNotEmpty($envelope['correlation_id'] ?? '');

        foreach (['SQLSTATE', 'Access denied', 'password', '/var/www', 'CapsuleQueryPort', '10.0.0.5', 'RuntimeException'] as $leak) {
            $this->assertStringNotContainsString($leak, $full, $leak);
        }
    }

    #[Test]
    public function crm_exception_from_repository_keeps_canonical_error_code(): void
    {
        // Port saudável e vazio: o repositório real lança resourceNotFound.
        $port = new FakeCrmQueryPort();
        $repo = new MgCrmRepository(
            new \NtMcp\Crm\CrmSchemaGuard(FakeCrmSchemaProbe::healthy()),
            $port,
            FakeAdminIdentityResolver::resolvingTo(7),
        );
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir, $repo);
        $sessionId = $this->initialize($adapter);

        $body = json_decode((string) $adapter->handle($this->call($sessionId, 4, 'whmcs_crm_get_contact', ['resource_id' => 99]))->getBody(), true);
        $this->assertTrue($body['result']['isError'] ?? false);
        $envelope = json_decode((string) $body['result']['content'][0]['text'], true);
        $this->assertSame('crm_resource_not_found', $envelope['error_code'] ?? null);
    }

    #[Test]
    public function products_lite_caps_the_real_json_rpc_body_below_40kb(): void
    {
        $products = [];
        for ($i = 1; $i <= 20; $i++) {
            $pricing = [];
            foreach (['BRL', 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF'] as $currency) {
                $pricing[$currency] = [
                    'prefix' => $currency . ' ', 'suffix' => '',
                    'msetupfee' => '0.00', 'qsetupfee' => '0.00', 'ssetupfee' => '0.00',
                    'asetupfee' => '0.00', 'bsetupfee' => '0.00', 'tsetupfee' => '0.00',
                    'monthly' => '19.90', 'quarterly' => '55.00', 'semiannually' => '105.00',
                    'annually' => '199.00', 'biennially' => '379.00', 'triennially' => '539.00',
                ];
            }
            $products[] = [
                'pid' => $i,
                'gid' => 2,
                'type' => 'hostingaccount',
                'name' => "Plano {$i}",
                'description' => '<p>' . str_repeat('Descrição ampla ', 30) . '</p>',
                'module' => 'plesk',
                'paytype' => 'recurring',
                'pricing' => $pricing,
            ];
        }

        $this->api->setCallable(static fn(string $cmd) => $cmd === 'GetProducts'
            ? ['result' => 'success', 'totalresults' => 20, 'products' => ['product' => $products]]
            : ['result' => 'success']);

        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $sessionId = $this->initialize($adapter);
        $response = $adapter->handle($this->call($sessionId, 3, 'whmcs_get_products', []));
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $toolResult = json_decode((string) ($decoded['result']['content'][0]['text'] ?? ''), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertLessThanOrEqual(40000, strlen($body));
        $this->assertTrue($toolResult['payload_capped'] ?? false);
        $this->assertLessThan(20, $toolResult['numreturned'] ?? 20);
        $this->assertSame($toolResult['numreturned'], $toolResult['next_limitstart']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function initialize(McpSdkAdapter $adapter, string $clientVersion = '2025-06-18', array $headers = []): string
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $request = $request->withBody($factory->createStream(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => $clientVersion,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'test', 'version' => '1'],
            ],
        ])));
        $response = $adapter->handle($request);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $response->getHeaderLine('Mcp-Session-Id');
    }

    private function call(string $sessionId, int $id, string $tool, array $arguments, array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->rpc($sessionId, ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $headers);
    }

    private function rpc(string $sessionId, array $payload, array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request->withBody($factory->createStream(json_encode($payload)));
    }

    // ------------------------------------------------------------------
    // MCP-Protocol-Version (B) e versão efetiva (D)
    // ------------------------------------------------------------------

    #[Test]
    public function invalid_protocol_version_header_is_rejected_with_400(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $sessionId = $this->initialize($adapter);

        $response = $adapter->handle($this->rpc($sessionId, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'], ['MCP-Protocol-Version' => 'not-a-version']));
        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32602, $body['error']['code'] ?? null);
        $this->assertStringContainsString('Unsupported', $body['error']['message'] ?? '');

        $future = $adapter->handle($this->rpc($sessionId, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'], ['MCP-Protocol-Version' => '2026-07-28']));
        $this->assertSame(400, $future->getStatusCode(), 'versão inexistente no SDK 0.7.1 não é aceita');
    }

    #[Test]
    public function every_sdk_protocol_version_is_accepted_in_header(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $sessionId = $this->initialize($adapter);
        $cases = array_map(static fn(\Mcp\Schema\Enum\ProtocolVersion $v) => $v->value, \Mcp\Schema\Enum\ProtocolVersion::cases());
        $this->assertSame(['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'], $cases);

        foreach ($cases as $version) {
            $response = $adapter->handle($this->rpc($sessionId, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'], ['MCP-Protocol-Version' => $version]));
            $this->assertSame(200, $response->getStatusCode(), $version);
            $body = json_decode((string) $response->getBody(), true);
            $this->assertSame(2, $body['id'] ?? null, $version);
            $this->assertArrayHasKey('result', $body, $version);
        }
    }

    #[Test]
    public function absent_protocol_version_header_is_tolerated(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $sessionId = $this->initialize($adapter);
        $response = $adapter->handle($this->rpc($sessionId, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']));
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function initialize_always_answers_the_configured_protocol_version(): void
    {
        foreach (['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'] as $asked) {
            $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
            $factory = new Psr17Factory();
            $response = $adapter->handle($factory->createServerRequest('POST', 'https://localhost/mcp.php')
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode([
                    'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                    'params' => ['protocolVersion' => $asked, 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']],
                ]))));
            $body = json_decode((string) $response->getBody(), true);
            $this->assertSame('2025-11-25', $body['result']['protocolVersion'] ?? null, "cliente pediu {$asked}");
            $this->assertSame(McpSdkAdapter::PROTOCOL_VERSION->value, $body['result']['protocolVersion']);
            $this->assertSame(McpSdkAdapter::SERVER_VERSION, $body['result']['serverInfo']['version'] ?? null);
        }
    }

    // ------------------------------------------------------------------
    // Sessões: permissões (C) e DELETE (E)
    // ------------------------------------------------------------------

    #[Test]
    public function session_files_are_private_and_directory_is_0700(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $sessionId = $this->initialize($adapter);
        $adapter->handle($this->rpc($sessionId, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])); // força discovery → cache
        clearstatcache();
        $this->assertSame(0700, fileperms($this->tempDir . '/sessions') & 0777);
        $this->assertSame(0600, fileperms($this->tempDir . '/sessions/' . $sessionId) & 0777);
        $this->assertSame(0700, fileperms($this->tempDir . '/cache') & 0777);
        $this->assertSame(0600, fileperms($this->tempDir . '/cache/' . McpSdkAdapter::ELEMENTS_CACHE_FILE) & 0777);
    }

    #[Test]
    public function delete_ends_session_and_later_post_is_404(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $sessionId = $this->initialize($adapter);
        $factory = new Psr17Factory();

        $delete = $adapter->handle($factory->createServerRequest('DELETE', 'https://localhost/mcp.php')->withHeader('Mcp-Session-Id', $sessionId));
        $this->assertSame(200, $delete->getStatusCode());
        $this->assertFileDoesNotExist($this->tempDir . '/sessions/' . $sessionId);

        $after = $adapter->handle($this->rpc($sessionId, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']));
        $this->assertSame(404, $after->getStatusCode());
    }

    #[Test]
    public function generic_exception_produces_json_rpc_error_32603(): void
    {
        $api = new LocalApiClient('testadmin');
        $api->setGates(['write' => true]);
        $api->setCallable(static function ($cmd, $params) {
            throw new \RuntimeException('SECRET dsn=mysql://u:p@h');
        });
        $api->setAdminIdResolver(static fn($_) => 7);

        $adapter = new McpSdkAdapter($api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        $initRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ])));

        $initResp = $adapter->handle($initRequest);
        $sessionId = $initResp->getHeaderLine('Mcp-Session-Id');

        $callRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'whmcs_get_client',
                    'arguments' => ['clientid' => 5],
                ],
            ])));

        $response = $adapter->handle($callRequest);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('error', $body);
        $this->assertSame(-32603, $body['error']['code']);
        $this->assertSame('Error while executing tool', $body['error']['message']);

        $fullBody = (string) $response->getBody();
        $this->assertStringNotContainsString('SECRET', $fullBody);
        $this->assertStringNotContainsString('dsn=', $fullBody);
        $this->assertStringNotContainsString('mysql://', $fullBody);
    }

    /**
     * Issue #29 end-to-end: a recusa de gate tem que chegar ao cliente COM o
     * motivo, e não como o -32603 genérico do teste acima. Prova o wiring do
     * AuthorizationAwareReferenceHandler no builder — o teste unitário do
     * decorator não cobre se ele está de fato ligado.
     */
    #[Test]
    public function authorization_denial_reaches_the_client_with_the_reason(): void
    {
        $api = new LocalApiClient('testadmin');
        $api->setGates(['write' => true]);
        $api->setCallable(static function ($cmd, $params) {
            throw new AuthorizationException(
                "LocalApiClient: command 'AddTicketReply' is blocked "
                . '(write_target_not_allowed: ticketid=30 fora da allowlist de escrita).'
            );
        });
        $api->setAdminIdResolver(static fn($_) => 7);

        $adapter = new McpSdkAdapter($api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        $initRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ])));

        $sessionId = $adapter->handle($initRequest)->getHeaderLine('Mcp-Session-Id');

        $callRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'whmcs_get_client',
                    'arguments' => ['clientid' => 5],
                ],
            ])));

        $body = json_decode((string) $adapter->handle($callRequest)->getBody(), true);

        // Não é erro de protocolo: é resultado de tool marcado como erro.
        $this->assertArrayNotHasKey('error', $body, 'recusa de gate não deve virar erro JSON-RPC');
        $this->assertTrue($body['result']['isError'] ?? false);
        $text = $body['result']['content'][0]['text'] ?? '';
        $this->assertStringContainsString('write_target_not_allowed', $text);
        $this->assertStringContainsString('AddTicketReply', $text);
    }

    #[Test]
    public function request_without_session_id_for_tools_list_fails(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        $listRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
            ])));

        $listResponse = $adapter->handle($listRequest);
        $statusCode = $listResponse->getStatusCode();

        // Either 400+ or a JSON-RPC error, but not 200
        $this->assertGreaterThanOrEqual(400, $statusCode);

        $body = (string) $listResponse->getBody();
        $this->assertStringNotContainsString('Parse error', $body);
    }

    #[Test]
    public function warm_cache_still_lists_70_tools(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        // Cold start
        $initRequest1 = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ])));

        $initResp1 = $adapter->handle($initRequest1);
        $sessionId1 = $initResp1->getHeaderLine('Mcp-Session-Id');

        $listRequest1 = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId1)
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
            ])));

        $listResp1 = $adapter->handle($listRequest1);
        $body1 = json_decode((string) $listResp1->getBody(), true);
        $count1 = count($body1['result']['tools'] ?? []);

        // Warm start with new adapter instance but same cache dir
        $adapter2 = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);

        $initRequest2 = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ])));

        $initResp2 = $adapter2->handle($initRequest2);
        $sessionId2 = $initResp2->getHeaderLine('Mcp-Session-Id');

        $listRequest2 = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId2)
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
            ])));

        $listResp2 = $adapter2->handle($listRequest2);
        $body2 = json_decode((string) $listResp2->getBody(), true);
        $count2 = count($body2['result']['tools'] ?? []);

        $this->assertSame(70, $count1);
        $this->assertSame(70, $count2);
    }

    #[Test]
    public function body_larger_than_1mb_returns_413(): void
    {
        // This test verifies the SDK's body size guard works
        // The McpSdkAdapter sets maxBodyBytes=1048576 in StreamableHttpTransport
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        $largebody = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'test', 'version' => '1'],
                'data' => str_repeat('x', 1048577),
            ],
        ]);

        $request = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream($largebody));

        $response = $adapter->handle($request);

        $this->assertSame(413, $response->getStatusCode());
    }

    #[Test]
    public function notification_initialized_returns_202(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->baseDir, $this->tempDir);
        $factory = new Psr17Factory();

        $initRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ])));

        $initResp = $adapter->handle($initRequest);
        $sessionId = $initResp->getHeaderLine('Mcp-Session-Id');

        $notifRequest = $factory->createServerRequest('POST', 'https://localhost/mcp.php')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ])));

        $notifResponse = $adapter->handle($notifRequest);

        $this->assertSame(202, $notifResponse->getStatusCode());
    }
}
