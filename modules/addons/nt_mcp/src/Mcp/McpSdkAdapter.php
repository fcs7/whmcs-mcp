<?php
// src/Mcp/McpSdkAdapter.php
declare(strict_types=1);

namespace NtMcp\Mcp;

use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server as McpServer;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use NtMcp\Crm\CapsuleAdminIdentityResolver;
use NtMcp\Crm\CapsuleQueryPort;
use NtMcp\Crm\CapsuleSchemaProbe;
use NtMcp\Crm\CrmSchema;
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
use NtMcp\Whmcs\CompatContainer;
use NtMcp\Whmcs\LocalApiClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Adapter sobre o SDK oficial `mcp/sdk` (StreamableHttpTransport, PSR-7).
 *
 * Decisões fixas aqui (ver CLAUDE.md → "SDK oficial"):
 *  - Middleware do SDK: SÓ `ProtocolVersionMiddleware` (header
 *    `MCP-Protocol-Version` inválido/não suportado → 400, como a spec exige;
 *    ausente → aceito, cobre o initialize e clientes legados). CorsMiddleware
 *    (bloqueia cross-origin) e DnsRebindingProtection (só localhost) ficam
 *    fora: CORS, IP allowlist, TLS e Bearer são das camadas em mcp.php, ANTES
 *    deste adapter. `middleware: []` derrubaria a validação de protocolo junto.
 *  - Versão de protocolo explícita (`PROTOCOL_VERSION`): o SDK 0.7.1 responde
 *    SEMPRE essa versão no initialize, independentemente da pedida pelo
 *    cliente (não há negociação para baixo); as quatro do enum são aceitas no
 *    header das requests seguintes. Não existe modo stateless nesta versão.
 *  - `maxBodyBytes` = 1 MiB (M-02) — o default do SDK é 4 MiB; o guard em
 *    Server.php rejeita antes, este é o segundo cinto.
 *  - Sessões em `data/sessions/` (um arquivo por sessão, 0600, TTL 1h, GC 1/20)
 *    via SecureFileSessionStore — substitui o single-file cache da lib
 *    anterior. O flock global virou SessionLock por faixa (Server.php), porque
 *    o store do SDK não serializa requests concorrentes da mesma sessão.
 *  - Discovery das Tools por atributo, cacheado em `data/cache/mcp_elements.json`
 *    (FileElementCache), invalidado em nt_mcp_upgrade().
 *  - Logger anônimo sem type-hints: WHMCS pré-carrega psr/log v1; o SDK nunca
 *    deve instanciar o NullLogger v3 por conta própria.
 */
final class McpSdkAdapter implements ServerAdapterInterface
{
    public const SERVER_NAME = 'NT Web WHMCS MCP Server';
    public const SERVER_VERSION = '2.2.4';
    public const MAX_BODY_BYTES = 1048576;
    public const SESSION_TTL = 3600;
    public const ELEMENTS_CACHE_FILE = 'mcp_elements.json';
    public const PROTOCOL_VERSION = ProtocolVersion::V2025_11_25;

    private readonly string $dataDir;
    private readonly MgCrmRepository $crm;

    /**
     * @param string               $baseDir Diretório src/ do addon (base do scan de Tools).
     * @param string|null          $dataDir Diretório data/ (sessões + cache); default baseDir/../data.
     * @param MgCrmRepository|null $crm     Injetável para testes sem WHMCS.
     */
    public function __construct(
        private readonly LocalApiClient $localApi,
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
        $server = $this->buildServer($logger);

        $psr17 = new Psr17Factory();
        $transport = new StreamableHttpTransport(
            $request,
            $psr17,
            $psr17,
            $logger,
            middleware: [new ProtocolVersionMiddleware(null, $psr17, $psr17)],
            maxBodyBytes: self::MAX_BODY_BYTES,
        );

        return $server->run($transport);
    }

    /**
     * Reconstrói o cache de discovery (`data/cache/mcp_elements.json`) fora do
     * caminho de request.
     *
     * O cache é invalidado no upgrade do addon; sem este aquecimento, o PRIMEIRO
     * request depois do deploy paga o scan de atributos inteiro — e paga
     * segurando o `SessionLock`, o que aparece no cliente como
     * `DeadlineExceeded` numa chamada que deveria ser trivial. `setLazyLoading(false)`
     * força os loaders a rodarem no `build()`, que é o que grava o cache.
     *
     * Falha NUNCA propaga: aquecer é otimização, e derrubar a ativação do addon
     * por causa dela seria trocar lentidão por indisponibilidade.
     */
    public function warmElementCache(): bool
    {
        try {
            $this->buildServer(self::compatLogger(), eager: true);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildServer(LoggerInterface $logger, bool $eager = false): McpServer
    {
        $container = new CompatContainer();
        $container->set(LocalApiClient::class, $this->localApi);
        foreach ([
            BillingTools::class, ClientTools::class, DomainTools::class,
            OrderTools::class, ProjectManagerTools::class, QuoteTools::class,
            ServiceTools::class, SupportInfoTools::class,
            TicketTools::class,
        ] as $toolClass) {
            $container->set($toolClass, new $toolClass($this->localApi));
        }
        // SystemTools needs CRM probe capability
        $container->set(SystemTools::class, new SystemTools(
            $this->localApi,
            null,
            static fn(): bool => \WHMCS\Database\Capsule::schema()->hasTable(CrmSchema::TABLE_RESOURCES)
        ));
        $container->set(CrmTools::class, new CrmTools($this->crm));
        $container->set(LoggerInterface::class, $logger);

        $server = McpServer::builder()
            ->setServerInfo(self::SERVER_NAME, self::SERVER_VERSION)
            ->setProtocolVersion(self::PROTOCOL_VERSION)
            ->setCapabilities(self::capabilities())
            ->setContainer($container)
            ->setLogger($logger)
            ->setDiscovery(
                $this->baseDir,
                ['Tools'],
                [],
                new FileElementCache($this->dataDir . '/cache/' . self::ELEMENTS_CACHE_FILE, DiscoveryState::class)
            )
            ->setSession(
                new SecureFileSessionStore($this->dataDir . '/sessions', self::SESSION_TTL),
                gcProbability: 1,
                gcDivisor: 20,
            )
            ->setPaginationLimit(200)
            ->setLazyLoading(!$eager)
            ->build();

        return $server;
    }

    /**
     * Capabilities declaradas explicitamente: este servidor expõe SÓ tools.
     *
     * A autodetecção do SDK (`Builder::detectCapabilities()`) anuncia
     * `resources`, `resourcesSubscribe` e `prompts` sempre que há "fonte
     * opaca" — e discovery por atributo é uma —, além de cravar `logging` e
     * `completions` em `true`. O comentário do SDK chama isso de
     * "over-advertising is harmless", o que é verdade no protocolo mas não na
     * UI: o cliente cria as seções Prompts/Resources e elas aparecem vazias,
     * lendo como feature quebrada (reportado no Cursor). Não temos nenhum
     * `#[McpPrompt]`/`#[McpResource]` registrado, e o logger é um sink que
     * descarta tudo — anunciar essas capacidades seria promessa sem lastro.
     *
     * ATENÇÃO: isto NÃO se atualiza sozinho. Ao registrar o primeiro prompt ou
     * resource, ligar a flag correspondente aqui, senão o cliente nunca vai
     * pedir a lista.
     */
    public static function capabilities(): ServerCapabilities
    {
        return new ServerCapabilities(
            tools: true,
            toolsListChanged: false,
            resources: false,
            resourcesSubscribe: false,
            resourcesListChanged: false,
            prompts: false,
            promptsListChanged: false,
            logging: false,
            completions: false,
        );
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
