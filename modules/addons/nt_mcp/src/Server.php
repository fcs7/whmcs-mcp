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

        $input = ($method === 'POST') ? file_get_contents('php://input') : '';
        $decoded = json_decode($input, true);

        // Temporary debug log — remove after confirming Claude Code works
        @file_put_contents(
            sys_get_temp_dir() . '/nt_mcp_debug.log',
            date('H:i:s') . " {$method} sid={$clientId}"
                . " method=" . ($decoded['method'] ?? 'N/A')
                . " id=" . ($decoded['id'] ?? 'none')
                . "\n",
            FILE_APPEND | LOCK_EX
        );

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

        // ------------------------------------------------------------------
        // 3. Acquire GLOBAL lock before ANY cache access.
        //    The FileCache stores ALL sessions in one JSON file. Without a
        //    global lock, concurrent workers do read-modify-write cycles that
        //    overwrite each other's data (TOCTOU race), causing "Client not
        //    initialized" errors on tools/list.
        // ------------------------------------------------------------------
        $lockFile = sys_get_temp_dir() . '/nt_mcp_global.lock';
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

            $cacheDir = sys_get_temp_dir() . '/nt_mcp_cache';
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

        // Debug: log response details
        $msgIds = array_map(fn($m) => $m['id'] ?? 'notif', $messages);
        @file_put_contents(
            sys_get_temp_dir() . '/nt_mcp_debug.log',
            date('H:i:s') . "   -> msgs=" . count($messages)
                . " ids=" . implode(',', $msgIds) . " reqId={$requestId}\n",
            FILE_APPEND | LOCK_EX
        );

        header('Mcp-Session-Id: ' . $clientId);

        if ($requestId !== null) {
            header('Content-Type: application/json');

            foreach ($messages as $message) {
                if (isset($message['id']) && $message['id'] === $requestId) {
                    $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    // Fix PHP empty array [] → empty object {} for JSON Schema
                    // properties fields. PHP json_encode([]) produces [] but
                    // JSON Schema requires properties to be an object {}.
                    $json = str_replace('"properties":[]', '"properties":{}', $json);
                    echo $json;

                    @file_put_contents(
                        sys_get_temp_dir() . '/nt_mcp_debug.log',
                        date('H:i:s') . "   -> SENT " . strlen($json) . " bytes\n",
                        FILE_APPEND | LOCK_EX
                    );
                    return;
                }
            }

            // No matching message found
            @file_put_contents(
                sys_get_temp_dir() . '/nt_mcp_debug.log',
                date('H:i:s') . "   -> NO MATCH for id={$requestId}\n",
                FILE_APPEND | LOCK_EX
            );
        } else {
            http_response_code(202);
        }
    }
}
