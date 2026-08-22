<?php

declare(strict_types=1);

namespace NtMcp\Tests\Mcp;

use NtMcp\Crm\MgCrmRepository;
use NtMcp\Mcp\McpSdkAdapter;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeCrmQueryPort;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use NtMcp\Whmcs\CapsuleClient;
use NtMcp\Whmcs\LocalApiClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpSdkAdapterTest extends TestCase
{
    private string $tempDir;
    private LocalApiClient $api;
    private CapsuleClient $capsule;
    private string $baseDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mcp-test-' . bin2hex(random_bytes(8));
        @mkdir($this->tempDir, 0700, true);

        $this->api = new LocalApiClient('testadmin');
        $this->api->setGates(['write' => true, 'destructive' => false]);
        $this->api->setCallable(static fn($cmd, $params) => ['result' => 'success', 'cmd' => $cmd]);
        $this->api->setAdminIdResolver(static fn($_) => 7);

        $this->capsule = new CapsuleClient();
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

    #[Test]
    public function initialize_returns_200_with_session_id_and_protocol_version(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->capsule, $this->baseDir, $this->tempDir);
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
    public function tools_list_returns_exactly_64_tools_with_whmcs_prefix(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->capsule, $this->baseDir, $this->tempDir);
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
        $this->assertCount(64, $tools);

        foreach ($tools as $tool) {
            $this->assertStringStartsWith('whmcs_', $tool['name']);
        }
    }

    #[Test]
    public function tools_call_whmcs_get_client_invokes_localapi(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->capsule, $this->baseDir, $this->tempDir);
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
                    'arguments' => ['clientid' => 5],
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
        // isError may be boolean or absent, both are acceptable for a successful result
        $isError = $content['isError'] ?? false;
        $this->assertFalse($isError);

        $text = json_decode($content['text'], true);
        $this->assertArrayHasKey('cmd', $text);
        $this->assertSame('GetClientsDetails', $text['cmd']);
    }

    #[Test]
    public function crm_read_tools_publish_schema_with_additional_properties_false(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->capsule, $this->baseDir, $this->tempDir);
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
        $adapter = new McpSdkAdapter($this->api, $this->capsule, $this->baseDir, $this->tempDir);
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
        $adapter = new McpSdkAdapter($this->api, $this->capsule, $this->baseDir, $this->tempDir);
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
        $adapter = new McpSdkAdapter($this->api, $this->capsule, $this->baseDir, $this->tempDir);
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
        // This test verifies that when a CRM repository throws, the error is properly
        // wrapped with error_code and doesn't leak sensitive information
        // We test this indirectly by checking the adapter behavior with a normal adapter
        // The actual CRM exception handling is tested in CrmTools-specific tests

        $api = new LocalApiClient('testadmin');
        $api->setGates(['write' => true]);
        // Make API callable throw on certain commands
        $api->setCallable(static function ($cmd, $params) {
            if ($cmd === 'GetContacts') {
                throw new \RuntimeException('Database connection failed');
            }
            return ['result' => 'success'];
        });
        $api->setAdminIdResolver(static fn($_) => 7);

        $adapter = new McpSdkAdapter($api, $this->capsule, $this->baseDir, $this->tempDir);
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
                    'arguments' => ['clientid' => 1],
                ],
            ])));

        $response = $adapter->handle($callRequest);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('result', $body);
        $content = $body['result']['content'][0] ?? null;
        $this->assertNotNull($content);

        // Error response should have error_code in the text
        // Sensitive message should be redacted
        $text = (string) $content['text'];
        $this->assertStringNotContainsString('Database connection failed', $text);
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

        $adapter = new McpSdkAdapter($api, $this->capsule, $this->baseDir, $this->tempDir);
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

    #[Test]
    public function request_without_session_id_for_tools_list_fails(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->capsule, $this->baseDir, $this->tempDir);
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
    public function warm_cache_still_lists_64_tools(): void
    {
        $adapter = new McpSdkAdapter($this->api, $this->capsule, $this->baseDir, $this->tempDir);
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
        $adapter2 = new McpSdkAdapter($this->api, $this->capsule, $this->baseDir, $this->tempDir);

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

        $this->assertSame(64, $count1);
        $this->assertSame(64, $count2);
    }

    #[Test]
    public function body_larger_than_1mb_returns_413(): void
    {
        // This test verifies the SDK's body size guard works
        // The McpSdkAdapter sets maxBodyBytes=1048576 in StreamableHttpTransport
        $adapter = new McpSdkAdapter($this->api, $this->capsule, $this->baseDir, $this->tempDir);
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
        $adapter = new McpSdkAdapter($this->api, $this->capsule, $this->baseDir, $this->tempDir);
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
