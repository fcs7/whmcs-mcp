<?php
// src/Server.php
namespace NtMcp;

use PhpMcp\Server\Server as McpServer;
use PhpMcp\Server\Defaults\ArrayConfigurationRepository;
use PhpMcp\Server\Defaults\FileCache;
use Psr\Log\NullLogger;
use PhpMcp\Server\Transports\HttpTransportHandler;
use PhpMcp\Server\Contracts\ConfigurationRepositoryInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;
use NtMcp\Whmcs\CompatContainer;
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

        // ------------------------------------------------------------------
        // 1. Parse the HTTP request FIRST — we need clientId before setup
        // ------------------------------------------------------------------
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $rawSessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? '';
        $clientId = preg_match('/^[a-zA-Z0-9._\-]{8,128}$/', $rawSessionId)
            ? $rawSessionId
            : bin2hex(random_bytes(16));

        // ------------------------------------------------------------------
        // 2. Handle GET (405) and other methods early — no server needed
        // ------------------------------------------------------------------
        if ($method === 'GET') {
            http_response_code(405);
            header('Allow: POST');
            header('Content-Type: application/json');
            echo json_encode(['error' => 'SSE not supported; use POST']);
            return;
        }
        if ($method !== 'POST') {
            http_response_code(405);
            header('Allow: GET, POST');
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        // SECURITY FIX (M-02): Reject oversized POST bodies before reading
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 1048576) { // 1 MB
            http_response_code(413);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Request body too large (max 1 MB)']);
            return;
        }

        $input = file_get_contents('php://input');
        $decoded = json_decode($input, true);

        // ------------------------------------------------------------------
        // 3. Acquire GLOBAL lock before ANY cache access.
        //    The FileCache stores ALL sessions in one JSON file. Without a
        //    global lock, concurrent workers do read-modify-write cycles that
        //    overwrite each other's data (TOCTOU race), causing "Client not
        //    initialized" errors on tools/list.
        // ------------------------------------------------------------------
        $dataDir = __DIR__ . '/../data';
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0700, true);
        }
        $lockFile = $dataDir . '/nt_mcp_global.lock';
        $lock = fopen($lockFile, 'c');
        flock($lock, LOCK_EX);

        try {
            // ------------------------------------------------------------------
            // 4. Build the MCP server (inside the lock — cache access is safe)
            // ------------------------------------------------------------------
            $localApi = new LocalApiClient($adminUser);
            $capsule  = new CapsuleClient();

            $config = new ArrayConfigurationRepository([
                'mcp' => [
                    'server' => ['name' => 'NT Web WHMCS MCP Server', 'version' => '1.0.0'],
                    'protocol_version' => '2024-11-05',
                    'pagination_limit' => 200,
                    'capabilities' => [
                        'tools' => ['enabled' => true, 'listChanged' => false],
                        'resources' => ['enabled' => false],
                        'prompts' => ['enabled' => false],
                        'logging' => ['enabled' => false],
                    ],
                    'cache' => ['key' => 'mcp.elements.cache', 'ttl' => 3600, 'prefix' => 'mcp_state_'],
                    'runtime' => ['log_level' => 'info'],
                ],
            ]);

            $container = new CompatContainer();
            $container->set(LocalApiClient::class, $localApi);
            $container->set(CapsuleClient::class, $capsule);

            $cacheDir = __DIR__ . '/../data/cache';
            $container->set(CacheInterface::class, new FileCache($cacheDir . '/mcp_state.json'));
            $container->set(LoggerInterface::class, new NullLogger());
            $container->set(ConfigurationRepositoryInterface::class, $config);

            $server = McpServer::make()
                ->withContainer($container)
                ->withConfig($config)
                ->withBasePath(__DIR__)
                ->withScanDirectories(['Tools']);

            $server->discover();

            // ------------------------------------------------------------------
            // PHP-FPM workaround: The library's Registry constructor calls
            // loadElementsFromCache() which re-registers all 54 tools, each
            // triggering queueMessageForAll(). This massive read/write storm
            // on the single-file cache can corrupt session state.
            // Pre-seed the initialization flag so tools/call never fails.
            // ------------------------------------------------------------------
            $mcpMethod = $decoded['method'] ?? '';
            if ($mcpMethod !== 'initialize' && $mcpMethod !== 'notifications/initialized') {
                $cache = $container->get(CacheInterface::class);
                $prefix = 'mcp_state_';
                $initKey = $prefix . 'initialized_' . $clientId;
                if (!$cache->has($initKey)) {
                    $cache->set($initKey, true, 3600);

                    // Also register as active client so future requests work
                    $activeKey = $prefix . 'active_clients';
                    $active = $cache->get($activeKey, []);
                    $active[$clientId] = time();
                    $cache->set($activeKey, $active, 3600);
                }
            }

            $transport = new HttpTransportHandler($server);

            // ------------------------------------------------------------------
            // 5. Process the request and collect response
            // ------------------------------------------------------------------
            $transport->handleInput($input, $clientId);

            $state = $transport->getTransportState();
            $messages = $state->getQueuedMessages($clientId);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        // ------------------------------------------------------------------
        // 6. Send the HTTP response (outside lock — no cache access needed)
        // ------------------------------------------------------------------
        $requestId = $decoded['id'] ?? null;

        header('Mcp-Session-Id: ' . $clientId);

        if ($requestId !== null) {
            header('Content-Type: application/json');

            foreach ($messages as $message) {
                if (isset($message['id']) && $message['id'] === $requestId) {
                    $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    // Fix: PHP json_encode([]) produces [] but MCP JSON Schema
                    // requires "properties" to be an object {}.  A targeted
                    // str_replace is safe here because "properties" only appears
                    // as a schema keyword, never as user data in tool responses.
                    $json = str_replace('"properties":[]', '"properties":{}', $json);
                    echo $json;
                    return;
                }
            }

        } else {
            http_response_code(202);
        }
    }
}
