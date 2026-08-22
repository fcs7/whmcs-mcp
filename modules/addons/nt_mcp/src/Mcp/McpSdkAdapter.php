<?php
// src/Mcp/McpSdkAdapter.php
declare(strict_types=1);

namespace NtMcp\Mcp;

use Mcp\Server as McpServer;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use NtMcp\Crm\CapsuleAdminIdentityResolver;
use NtMcp\Crm\CapsuleQueryPort;
use NtMcp\Crm\CapsuleSchemaProbe;
use NtMcp\Crm\CrmSchemaGuard;
use NtMcp\Crm\MgCrmRepository;
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
use NtMcp\Whmcs\CapsuleClient;
use NtMcp\Whmcs\CompatContainer;
use NtMcp\Whmcs\LocalApiClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Adapter sobre o SDK oficial `mcp/sdk` (StreamableHttpTransport, PSR-7).
 *
 * Decisões fixas aqui (ver CLAUDE.md → "SDK oficial"):
 *  - `middleware: []` — o SDK instalaria CorsMiddleware (bloqueia cross-origin)
 *    e DnsRebindingProtection (só localhost). CORS, IP allowlist, TLS e
 *    Bearer são responsabilidade das camadas em mcp.php, ANTES deste adapter.
 *  - `maxBodyBytes` = 1 MiB (M-02) — o default do SDK é 4 MiB; o guard em
 *    Server.php rejeita antes, este é o segundo cinto.
 *  - Sessões em `data/sessions/` (um arquivo por sessão, TTL 1h, GC 1/20) —
 *    substitui o single-file cache + flock global da lib anterior.
 *  - Discovery das Tools por atributo, cacheado em `data/cache/mcp_elements.json`
 *    (FileElementCache), invalidado em nt_mcp_upgrade().
 *  - Logger anônimo sem type-hints: WHMCS pré-carrega psr/log v1; o SDK nunca
 *    deve instanciar o NullLogger v3 por conta própria.
 */
final class McpSdkAdapter implements ServerAdapterInterface
{
    public const SERVER_NAME = 'NT Web WHMCS MCP Server';
    public const SERVER_VERSION = '2.0.0';
    public const MAX_BODY_BYTES = 1048576;
    public const SESSION_TTL = 3600;
    public const ELEMENTS_CACHE_FILE = 'mcp_elements.json';

    private readonly string $dataDir;
    private readonly MgCrmRepository $crm;

    /**
     * @param string               $baseDir Diretório src/ do addon (base do scan de Tools).
     * @param string|null          $dataDir Diretório data/ (sessões + cache); default baseDir/../data.
     * @param MgCrmRepository|null $crm     Injetável para testes sem WHMCS.
     */
    public function __construct(
        private readonly LocalApiClient $localApi,
        private readonly CapsuleClient $capsule,
        private readonly string $baseDir,
        ?string $dataDir = null,
        ?MgCrmRepository $crm = null,
    ) {
        $this->dataDir = $dataDir ?? ($baseDir . '/../data');
        $this->crm = $crm ?? self::capsuleCrmRepository();
    }

    private static function capsuleCrmRepository(): MgCrmRepository
    {
        $port = new CapsuleQueryPort();
        $guard = new CrmSchemaGuard(new CapsuleSchemaProbe($port));

        return new MgCrmRepository($guard, $port, new CapsuleAdminIdentityResolver($guard, $port));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $logger = self::compatLogger();

        $container = new CompatContainer();
        $container->set(LocalApiClient::class, $this->localApi);
        $container->set(CapsuleClient::class, $this->capsule);
        foreach ([
            BillingTools::class, ClientTools::class, DomainTools::class,
            OrderTools::class, ProjectManagerTools::class, QuoteTools::class,
            ServiceTools::class, SupportInfoTools::class, SystemTools::class,
            TicketTools::class,
        ] as $toolClass) {
            $container->set($toolClass, new $toolClass($this->localApi));
        }
        $container->set(CrmTools::class, new CrmTools($this->capsule, $this->crm));
        $container->set(LoggerInterface::class, $logger);

        $server = McpServer::builder()
            ->setServerInfo(self::SERVER_NAME, self::SERVER_VERSION)
            ->setContainer($container)
            ->setLogger($logger)
            ->setDiscovery(
                $this->baseDir,
                ['Tools'],
                [],
                new FileElementCache($this->dataDir . '/cache/' . self::ELEMENTS_CACHE_FILE)
            )
            ->setSession(
                new FileSessionStore($this->dataDir . '/sessions', self::SESSION_TTL),
                gcProbability: 1,
                gcDivisor: 20,
            )
            ->setPaginationLimit(200)
            ->build();

        $psr17 = new Psr17Factory();
        $transport = new StreamableHttpTransport(
            $request,
            $psr17,
            $psr17,
            $logger,
            middleware: [],
            maxBodyBytes: self::MAX_BODY_BYTES,
        );

        return $server->run($transport);
    }

    /**
     * PSR-3 sem type-hints nos parâmetros: compatível tanto com a interface v1
     * que o WHMCS carrega quanto com a v3 do vendor.
     */
    public static function compatLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            public function emergency($message, array $context = []): void {}
            public function alert($message, array $context = []): void {}
            public function critical($message, array $context = []): void {}
            public function error($message, array $context = []): void {}
            public function warning($message, array $context = []): void {}
            public function notice($message, array $context = []): void {}
            public function info($message, array $context = []): void {}
            public function debug($message, array $context = []): void {}
            public function log($level, $message, array $context = []): void {}
        };
    }
}
