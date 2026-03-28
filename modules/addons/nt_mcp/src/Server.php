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
        // ------------------------------------------------------------------
        // SECURITY (F10): Read the admin username from WHMCS configuration
        // instead of using a hardcoded value.  Falls back to 'admin' when
        // the setting has not been configured yet.
        // ------------------------------------------------------------------
        $adminUser = 'admin';
        try {
            $configured = trim(\WHMCS\Config\Setting::getValue('nt_mcp_admin_user') ?? '');
            if ($configured !== '') {
                $adminUser = $configured;
            }
        } catch (\Throwable $_ex) {
            // Setting not available — use default
        }

        $localApi = new LocalApiClient($adminUser);
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

        // ---------------------------------------------------------------
        // SECURITY FIX (F14 -- MEDIUM): Validate MCP-Session-Id header.
        //
        // The raw HTTP_MCP_SESSION_ID was accepted without any validation,
        // allowing CRLF injection (header splitting) or arbitrary values.
        // Now we enforce strict hex format (16-64 chars) and fall back to
        // a cryptographically random ID when the header is absent or invalid.
        // ---------------------------------------------------------------
        $rawSessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? '';
        $clientId = preg_match('/^[a-f0-9]{16,64}$/i', $rawSessionId)
            ? $rawSessionId
            : (session_id() ?: bin2hex(random_bytes(16)));

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

            // ---------------------------------------------------------------
            // SECURITY FIX (9.3 -- F11): Host header injection prevention.
            // $_SERVER['HTTP_HOST'] is attacker-controlled.  Derive the
            // SSE post-back endpoint from the WHMCS-configured System URL.
            // ---------------------------------------------------------------
            $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL') ?? '', '/');
            if ($systemUrl === '') {
                try {
                    $systemUrl = rtrim(\App::getSystemURL(), '/');
                } catch (\Throwable $e) {
                    // Last resort: reconstruct from validated server vars
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
                    $systemUrl = $scheme . '://' . $host;
                }
            }
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/modules/addons/nt_mcp/mcp.php';
            // Strip any injected characters from REQUEST_URI
            $requestUri = parse_url($requestUri, PHP_URL_PATH) ?: '/modules/addons/nt_mcp/mcp.php';
            $postEndpoint = $systemUrl . $requestUri;
            $transport->handleSseConnection($clientId, $postEndpoint);
        } else {
            http_response_code(405);
            header('Allow: GET, POST');
            echo json_encode(['error' => 'Method not allowed']);
        }
    }
}
