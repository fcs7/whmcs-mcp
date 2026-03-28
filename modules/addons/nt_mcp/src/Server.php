<?php
// src/Server.php
namespace NtMcp;

use PhpMcp\Server\Server as McpServer;
use PhpMcp\Server\Defaults\ArrayConfigurationRepository;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Transports\HttpTransportHandler;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\CapsuleClient;

class Server
{
    public static function run(): void
    {
        $localApi = new LocalApiClient('admin');
        $capsule  = new CapsuleClient();

        // Build server with custom config
        $config = new ArrayConfigurationRepository([
            'mcp' => [
                'server' => ['name' => 'NT Web WHMCS MCP Server', 'version' => '1.0.0'],
                'protocol_versions' => ['2024-11-05'],
                'pagination_limit' => 50,
                'capabilities' => [
                    'tools' => ['enabled' => true, 'listChanged' => true],
                    'resources' => ['enabled' => false],
                    'prompts' => ['enabled' => false],
                    'logging' => ['enabled' => false],
                ],
                'cache' => ['key' => 'mcp.elements.cache', 'ttl' => 3600, 'prefix' => 'mcp_state_'],
                'runtime' => ['log_level' => 'info'],
            ],
        ]);

        $server = McpServer::make()
            ->withConfig($config)
            ->withBasePath(__DIR__)
            ->withScanDirectories(['Tools']);

        // Register service instances into the container for tool constructor injection
        $container = $server->getContainer();
        if ($container instanceof BasicContainer) {
            $container->set(LocalApiClient::class, $localApi);
            $container->set(CapsuleClient::class, $capsule);
        }

        // Discover tools annotated with #[McpTool]
        $server->discover();

        // Handle the HTTP request via Streamable HTTP (stateless per-request)
        $transport = new HttpTransportHandler($server);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $clientId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? session_id() ?: bin2hex(random_bytes(16));

        if ($method === 'POST') {
            $input = file_get_contents('php://input');
            $transport->handleInput($input, $clientId);

            // Send queued response
            $state = $transport->getTransportState();
            $messages = $state->getQueuedMessages($clientId);

            header('Content-Type: application/json');
            header('Mcp-Session-Id: ' . $clientId);

            foreach ($messages as $message) {
                echo json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        } elseif ($method === 'GET') {
            // SSE endpoint for server-initiated messages
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('Mcp-Session-Id: ' . $clientId);

            $postEndpoint = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $transport->handleSseConnection($clientId, $postEndpoint);
        } else {
            http_response_code(405);
            header('Allow: GET, POST');
            echo json_encode(['error' => 'Method not allowed']);
        }
    }
}
