<?php
// src/Mcp/PhpMcpV1Adapter.php
namespace NtMcp\Mcp;

use PhpMcp\Server\Server as McpServer;
use PhpMcp\Server\Defaults\ArrayConfigurationRepository;
use PhpMcp\Server\Defaults\FileCache;
use PhpMcp\Server\Transports\HttpTransportHandler;
use PhpMcp\Server\Contracts\ConfigurationRepositoryInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;
use NtMcp\Whmcs\CompatContainer;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\CapsuleClient;
use NtMcp\Tools\BillingTools;
use NtMcp\Tools\ClientTools;
use NtMcp\Tools\CrmTools;
use NtMcp\Tools\DomainTools;
use NtMcp\Tools\OrderTools;
use NtMcp\Tools\ProjectManagerTools;
use NtMcp\Tools\QuoteTools;
use NtMcp\Tools\ServiceTools;
use NtMcp\Tools\SupportInfoTools;
use NtMcp\Tools\SystemTools;
use NtMcp\Tools\TicketTools;

/**
 * Adapter concreto para php-mcp/server v1.x. Encapsula toda a montagem do
 * McpServer (config, container, cache, logger, discovery) e o processamento
 * de um request via HttpTransportHandler.
 *
 * FASE 2 (deixar leve): duas otimizações contra o overhead por request —
 *  - 2a: só chama $server->discover() (scan filesystem + reflexão das 86 tools)
 *        quando o cache de elementos está frio. saveElementsToCache() persiste
 *        sem TTL (nunca expira), então após o 1º request o Registry rehidrata
 *        do cache no construtor e o scan é pulado. Auto-curável: cache ausente
 *        (ex.: invalidação no upgrade do addon) → próximo request re-descobre.
 *  - 2b: pré-registra as 11 Tools classes no container, evitando a reflexão do
 *        CompatContainer a cada tools/call.
 */
class PhpMcpV1Adapter implements ServerAdapterInterface
{
    /**
     * Chave sob a qual a lib persiste as tools descobertas.
     * Registry.php: cacheKey = config('mcp.cache.prefix') . 'elements'.
     * Com prefix 'mcp_state_' → 'mcp_state_elements'.
     */
    private const ELEMENTS_CACHE_KEY = 'mcp_state_elements';

    private const CACHE_PREFIX = 'mcp_state_';

    /** Sessão sem atividade além disto (s) é podada do fan-out. */
    private const CLIENT_TTL = 600;

    /** Teto rígido de clientes ativos (backstop contra flood de session-ids). */
    private const MAX_ACTIVE_CLIENTS = 50;

    private readonly string $cacheDir;

    /**
     * @param string      $baseDir  Diretório base para withBasePath()/scan de
     *                              Tools (o src/ do addon).
     * @param string|null $cacheDir Diretório do FileCache; default baseDir/../data/cache.
     *                              Parametrizável para testes não poluírem o cache real.
     */
    public function __construct(
        private readonly LocalApiClient $localApi,
        private readonly CapsuleClient $capsule,
        private readonly string $baseDir,
        ?string $cacheDir = null,
    ) {
        $this->cacheDir = $cacheDir ?? ($baseDir . '/../data/cache');
    }

    public function handle(string $input, string $clientId, ?string $mcpMethod): array
    {
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
                'cache' => ['key' => 'mcp.elements.cache', 'ttl' => 3600, 'prefix' => self::CACHE_PREFIX],
                'runtime' => ['log_level' => 'info'],
            ],
        ]);

        $container = new CompatContainer();
        $container->set(LocalApiClient::class, $this->localApi);
        $container->set(CapsuleClient::class, $this->capsule);

        // FASE 2b: pré-registrar as 11 Tools classes evita a reflexão do
        // CompatContainer a cada resolução. 10 injetam LocalApiClient; CrmTools
        // injeta CapsuleClient.
        foreach ([
            BillingTools::class, ClientTools::class, DomainTools::class,
            OrderTools::class, ProjectManagerTools::class, QuoteTools::class,
            ServiceTools::class, SupportInfoTools::class, SystemTools::class,
            TicketTools::class,
        ] as $toolClass) {
            $container->set($toolClass, new $toolClass($this->localApi));
        }
        $container->set(CrmTools::class, new CrmTools($this->capsule));

        $container->set(CacheInterface::class, new FileCache($this->cacheDir . '/mcp_state.json'));

        // WHMCS preloads Psr\Log\LoggerInterface v1 (untyped params).
        // psr/log v3 NullLogger has typed params (string|\Stringable) which
        // causes a fatal declaration compatibility error on PHP 8.1.
        // Anonymous class with untyped params is compatible with both versions.
        $container->set(LoggerInterface::class, new class implements LoggerInterface {
            public function emergency($message, array $context = []): void {}
            public function alert($message, array $context = []): void {}
            public function critical($message, array $context = []): void {}
            public function error($message, array $context = []): void {}
            public function warning($message, array $context = []): void {}
            public function notice($message, array $context = []): void {}
            public function info($message, array $context = []): void {}
            public function debug($message, array $context = []): void {}
            public function log($level, $message, array $context = []): void {}
        });

        $container->set(ConfigurationRepositoryInterface::class, $config);

        $server = McpServer::make();
        $server = $server->withContainer($container);
        $server = $server->withConfig($config);
        $server = $server->withBasePath($this->baseDir);
        $server = $server->withScanDirectories(['Tools']);

        // FASE 2a: discovery condicional. O construtor da Registry (na lib)
        // chama loadElementsFromCache() automaticamente, então quando o cache
        // já tem os elementos basta NÃO chamar discover() — o Registry rehidrata
        // do cache sem tocar no filesystem. Só descobre no cold start.
        $cache = $container->get(CacheInterface::class);
        if (!$cache->has(self::ELEMENTS_CACHE_KEY)) {
            $server->discover();
        }

        // ------------------------------------------------------------------
        // PHP-FPM workaround: The library's Registry constructor calls
        // loadElementsFromCache() which re-registers all tools, each
        // triggering queueMessageForAll(). This read/write storm on the
        // single-file cache can corrupt session state. Pre-seed the
        // initialization flag so tools/call never fails.
        // ------------------------------------------------------------------
        if ($mcpMethod !== 'initialize' && $mcpMethod !== 'notifications/initialized') {
            $initKey = self::CACHE_PREFIX . 'initialized_' . $clientId;
            if (!$cache->has($initKey)) {
                $cache->set($initKey, true, 3600);
            }
            $this->trackActiveClientAndGc($cache, $clientId);
        }

        $transport = new HttpTransportHandler($server);
        $transport->handleInput($input, $clientId);

        $state = $transport->getTransportState();
        return $state->getQueuedMessages($clientId);
    }

    /**
     * Registra o cliente atual como ativo e faz GC dos abandonados.
     *
     * A lib enfileira uma cópia da resposta para CADA cliente ativo
     * (queueMessageForAll). Um cliente que não volta nunca drena a própria
     * fila (mcp_state_messages_<id>) → o cache single-file incha e a latência
     * quadratiza — raiz do "módulo pesado" / cascata 504 sob múltiplas sessões
     * (uma sessão única que reusa o session-id NÃO sofre disso). Poda por
     * (i) inatividade (CLIENT_TTL) e (ii) teto rígido (MAX_ACTIVE_CLIENTS,
     * backstop contra flood de session-ids distintos), deletando a fila e o
     * flag de init de cada cliente removido. O cliente atual nunca é podado.
     */
    private function trackActiveClientAndGc(CacheInterface $cache, string $clientId): void
    {
        $activeKey = self::CACHE_PREFIX . 'active_clients';
        $active = $cache->get($activeKey, []);
        if (!is_array($active)) {
            $active = [];
        }

        $now = time();
        $active[$clientId] = $now; // refresh last-seen do cliente atual (sempre o mais novo)

        // (i) poda por inatividade
        foreach ($active as $cid => $lastSeen) {
            if ($cid !== $clientId && ($now - (int) $lastSeen) > self::CLIENT_TTL) {
                unset($active[$cid]);
                $this->dropClientState($cache, $cid);
            }
        }

        // (ii) teto rígido — remove os mais antigos (asort é estável em PHP 8,
        // e o cliente atual é o mais novo, logo nunca fica em primeiro).
        if (count($active) > self::MAX_ACTIVE_CLIENTS) {
            asort($active);
            while (count($active) > self::MAX_ACTIVE_CLIENTS) {
                $cid = array_key_first($active);
                unset($active[$cid]);
                $this->dropClientState($cache, $cid);
            }
        }

        $cache->set($activeKey, $active, 3600);
    }

    private function dropClientState(CacheInterface $cache, string $clientId): void
    {
        $cache->delete(self::CACHE_PREFIX . 'messages_' . $clientId);
        $cache->delete(self::CACHE_PREFIX . 'initialized_' . $clientId);
    }
}
