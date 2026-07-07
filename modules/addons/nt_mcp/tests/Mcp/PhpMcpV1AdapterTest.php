<?php
// tests/Mcp/PhpMcpV1AdapterTest.php
namespace NtMcp\Tests\Mcp;

use NtMcp\Mcp\PhpMcpV1Adapter;
use NtMcp\Whmcs\CapsuleClient;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Integração real do adapter contra a lib php-mcp/server (sem WHMCS).
 *
 * Prova a otimização de risco da FASE 2: com o cache de elementos QUENTE o
 * adapter pula $server->discover() e mesmo assim tools/list devolve as 86
 * tools — ou seja, o Registry rehidrata do cache. Cobre cold (descobre) e
 * warm (pula discover) no mesmo cacheDir.
 */
class PhpMcpV1AdapterTest extends TestCase
{
    private string $cacheDir;
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = dirname(__DIR__, 2) . '/src'; // .../nt_mcp/src (tem Tools/)
        $this->cacheDir = sys_get_temp_dir() . '/nt_mcp_adapter_test_' . bin2hex(random_bytes(6));
        @mkdir($this->cacheDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    private function makeAdapter(): PhpMcpV1Adapter
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable(fn() => ['result' => 'success']); // não chamado em tools/list
        return new PhpMcpV1Adapter($api, new CapsuleClient(), $this->baseDir, $this->cacheDir);
    }

    /** Extrai o array result.tools da resposta com o id dado. */
    private function toolsFrom(array $messages, int $id): ?array
    {
        foreach ($messages as $m) {
            if (($m['id'] ?? null) === $id && isset($m['result']['tools'])) {
                return $m['result']['tools'];
            }
        }
        return null;
    }

    private function toolsListRequest(int $id): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'method'  => 'tools/list',
            'params'  => new \stdClass(),
        ]);
    }

    public function test_cold_cache_discovers_and_lists_86_tools(): void
    {
        $adapter = $this->makeAdapter();
        $messages = $adapter->handle($this->toolsListRequest(1), 'client-cold-000000', 'tools/list');

        $tools = $this->toolsFrom($messages, 1);
        $this->assertIsArray($tools, 'tools/list deve retornar array de tools');
        $this->assertCount(86, $tools, 'cold start deve descobrir 86 tools');

        // Cache de elementos foi persistido → arquivo existe.
        $this->assertFileExists($this->cacheDir . '/mcp_state.json');
    }

    public function test_warm_cache_skips_discover_but_still_lists_86_tools(): void
    {
        // 1ª chamada: cold → popula o cache de elementos.
        $this->makeAdapter()->handle($this->toolsListRequest(1), 'client-warm-000001', 'tools/list');
        $this->assertFileExists($this->cacheDir . '/mcp_state.json');

        // 2ª chamada (adapter novo, mesmo cacheDir): cache quente → discover()
        // é pulado. As tools DEVEM continuar vindo (rehidratadas do cache).
        $adapter = $this->makeAdapter();
        $messages = $adapter->handle($this->toolsListRequest(2), 'client-warm-000002', 'tools/list');

        $tools = $this->toolsFrom($messages, 2);
        $this->assertIsArray($tools, 'warm cache deve retornar tools do cache');
        $this->assertCount(86, $tools, 'skip-discover com cache quente deve preservar as 86 tools');
    }

    public function test_every_tool_name_is_prefixed_whmcs(): void
    {
        $adapter = $this->makeAdapter();
        $messages = $adapter->handle($this->toolsListRequest(1), 'client-names-00003', 'tools/list');
        $tools = $this->toolsFrom($messages, 1) ?? [];

        $this->assertNotEmpty($tools);
        foreach ($tools as $t) {
            $this->assertStringStartsWith('whmcs_', $t['name'] ?? '', 'tool sem prefixo whmcs_: ' . ($t['name'] ?? '?'));
        }
    }
}
