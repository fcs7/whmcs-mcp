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
 * adapter pula $server->discover() e mesmo assim tools/list devolve as 64
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

    private function initializeRequest(int $id): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ]);
    }

    public function test_cold_cache_discovers_and_lists_64_tools(): void
    {
        $adapter = $this->makeAdapter();
        $messages = $adapter->handle($this->toolsListRequest(1), 'client-cold-000000', 'tools/list');

        $tools = $this->toolsFrom($messages, 1);
        $this->assertIsArray($tools, 'tools/list deve retornar array de tools');
        $this->assertCount(64, $tools, 'cold start deve descobrir 64 tools');

        // Cache de elementos foi persistido → arquivo existe.
        $this->assertFileExists($this->cacheDir . '/mcp_state.json');
    }

    public function test_warm_cache_skips_discover_but_still_lists_64_tools(): void
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
        $this->assertCount(64, $tools, 'skip-discover com cache quente deve preservar as 64 tools');
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

    // ---------------------------------------------------------------
    // Contrato da superfície canônica (T1): o discovery limpo encontra
    // EXATAMENTE os 64 nomes do plano, sem duplicata e sem nenhuma das
    // tools retiradas.
    // ---------------------------------------------------------------

    /** As 64 tools da superfície canônica, em ordem determinística. */
    private const CANONICAL_TOOLS = [
        // ClientTools (12)
        'whmcs_add_contact', 'whmcs_create_client', 'whmcs_get_client',
        'whmcs_get_client_domains', 'whmcs_get_client_groups', 'whmcs_get_client_invoices',
        'whmcs_get_client_products', 'whmcs_get_clients_addons', 'whmcs_get_contacts',
        'whmcs_list_clients', 'whmcs_update_client', 'whmcs_update_contact',
        // ProjectManagerTools (9)
        'whmcs_add_project_message', 'whmcs_add_project_task', 'whmcs_create_project',
        'whmcs_end_task_timer', 'whmcs_get_project', 'whmcs_list_projects',
        'whmcs_start_task_timer', 'whmcs_update_project', 'whmcs_update_project_task',
        // CrmTools (8)
        'whmcs_crm_add_followup', 'whmcs_crm_add_note', 'whmcs_crm_create_lead',
        'whmcs_crm_get_contact', 'whmcs_crm_get_kanban', 'whmcs_crm_list_contacts',
        'whmcs_crm_list_followups', 'whmcs_crm_update_contact',
        // BillingTools (5)
        'whmcs_get_credits', 'whmcs_get_invoice', 'whmcs_get_pay_methods',
        'whmcs_get_transactions', 'whmcs_list_invoices',
        // DomainTools (5)
        'whmcs_domain_get_locking_status', 'whmcs_domain_get_nameservers',
        'whmcs_domain_get_whois_info', 'whmcs_get_tld_pricing', 'whmcs_list_domains',
        // SystemTools (5)
        'whmcs_get_activity_log', 'whmcs_get_admin_details', 'whmcs_get_stats',
        'whmcs_get_todo_items', 'whmcs_update_todo_item',
        // TicketTools (5)
        'whmcs_get_ticket', 'whmcs_list_tickets', 'whmcs_open_ticket',
        'whmcs_reply_ticket', 'whmcs_update_ticket',
        // OrderTools (4)
        'whmcs_cancel_order', 'whmcs_get_order', 'whmcs_list_orders', 'whmcs_pending_order',
        // QuoteTools (7)
        'whmcs_convert_quote_to_invoice', 'whmcs_create_quote', 'whmcs_delete_quote',
        'whmcs_duplicate_quote', 'whmcs_get_quote', 'whmcs_list_quotes', 'whmcs_update_quote',
        // SupportInfoTools (3)
        'whmcs_get_support_departments', 'whmcs_get_support_statuses', 'whmcs_get_ticket_counts',
        // ServiceTools (1)
        'whmcs_list_services',
    ];

    /** As 25 tools retiradas da superfície: nenhuma pode voltar a ser listada. */
    private const REMOVED_TOOLS = [
        // custo / provisionamento
        'whmcs_suspend_service', 'whmcs_unsuspend_service', 'whmcs_upgrade_service',
        'whmcs_register_domain', 'whmcs_renew_domain', 'whmcs_update_nameservers',
        'whmcs_update_client_domain', 'whmcs_accept_order', 'whmcs_add_order',
        // comunicação externa
        'whmcs_send_email', 'whmcs_send_quote',
        // destrutiva fora da exceção decidida
        'whmcs_delete_project_task',
        // substituída pela conversão gateada
        'whmcs_accept_quote',
        // lookups auxiliares retirados
        'whmcs_get_order_statuses', 'whmcs_get_products', 'whmcs_get_promotions',
        'whmcs_get_currencies', 'whmcs_get_payment_methods', 'whmcs_get_email_templates',
        'whmcs_get_todo_statuses', 'whmcs_log_activity',
        'whmcs_get_ticket_notes', 'whmcs_get_ticket_predefined_cats',
        'whmcs_get_ticket_predefined_replies', 'whmcs_get_ticket_attachment',
    ];

    /** @return array<string> nomes descobertos por um discovery real */
    private function discoveredToolNames(): array
    {
        $adapter = $this->makeAdapter();
        $messages = $adapter->handle($this->toolsListRequest(1), 'client-surface-01', 'tools/list');

        return array_map(fn(array $t) => $t['name'], $this->toolsFrom($messages, 1) ?? []);
    }

    public function test_discovery_exposes_exactly_the_canonical_64_tools(): void
    {
        $discovered = $this->discoveredToolNames();
        sort($discovered);

        $canonical = self::CANONICAL_TOOLS;
        sort($canonical);

        $this->assertCount(64, $canonical, 'a lista canônica do teste deve ter 64 nomes');
        $this->assertSame($canonical, $discovered);
    }

    public function test_discovery_has_no_duplicate_tool_names(): void
    {
        $discovered = $this->discoveredToolNames();

        $this->assertSame(
            count($discovered),
            count(array_unique($discovered)),
            'nomes duplicados em tools/list: ' . implode(', ', array_diff_assoc($discovered, array_unique($discovered)))
        );
    }

    public function test_removed_tools_are_absent_from_discovery(): void
    {
        $discovered = $this->discoveredToolNames();

        $this->assertCount(25, self::REMOVED_TOOLS);
        foreach (self::REMOVED_TOOLS as $removed) {
            $this->assertNotContains($removed, $discovered, "tool retirada ressuscitou: {$removed}");
        }
    }

    /**
     * Remoção FÍSICA: os métodos das tools retiradas não existem mais nas
     * classes de Tools. Manter o método sem atributo permitiria que o cache da
     * lib v1.1 os ressuscitasse como tools listadas e chamáveis.
     */
    public function test_removed_tool_methods_are_physically_deleted(): void
    {
        $gone = [
            \NtMcp\Tools\ServiceTools::class => ['suspendService', 'unsuspendService', 'upgradeService'],
            \NtMcp\Tools\DomainTools::class => ['registerDomain', 'renewDomain', 'updateNameservers', 'updateClientDomain'],
            \NtMcp\Tools\OrderTools::class => ['acceptOrder', 'addOrder', 'getOrderStatuses', 'getProducts', 'getPromotions'],
            \NtMcp\Tools\SystemTools::class => ['sendEmail', 'getCurrencies', 'getEmailTemplates', 'getPaymentMethods', 'getToDoStatuses', 'logActivity'],
            \NtMcp\Tools\SupportInfoTools::class => ['getTicketNotes', 'getTicketPredefinedCats', 'getTicketPredefinedReplies', 'getTicketAttachment'],
            \NtMcp\Tools\ProjectManagerTools::class => ['deleteProjectTask'],
            \NtMcp\Tools\QuoteTools::class => ['sendQuote', 'acceptQuote'],
        ];

        foreach ($gone as $class => $methods) {
            foreach ($methods as $method) {
                $this->assertFalse(
                    method_exists($class, $method),
                    "{$class}::{$method}() deveria ter sido apagado, não apenas perdido o atributo"
                );
            }
        }
    }

    // --- GC de clientes ociosos (raiz do storm queueMessageForAll) ---

    private function seedCache(): \PhpMcp\Server\Defaults\FileCache
    {
        return new \PhpMcp\Server\Defaults\FileCache($this->cacheDir . '/mcp_state.json');
    }

    public function test_gc_prunes_stale_clients_and_deletes_their_message_queues(): void
    {
        $cache = $this->seedCache();
        $stale = time() - 700; // > CLIENT_TTL (600s)
        $cache->set('mcp_state_active_clients', ['old1' => $stale, 'old2' => $stale], 3600);
        $cache->set('mcp_state_messages_old1', ['x' => str_repeat('a', 5000)], 3600);
        $cache->set('mcp_state_messages_old2', ['x' => str_repeat('a', 5000)], 3600);
        $cache->set('mcp_state_initialized_old1', true, 3600);

        // Um request de um cliente novo dispara o GC no pre-seed.
        $this->makeAdapter()->handle($this->toolsListRequest(1), 'freshclient01', 'tools/list');

        $active = $this->seedCache()->get('mcp_state_active_clients');
        $this->assertIsArray($active);
        $this->assertArrayNotHasKey('old1', $active, 'cliente ocioso deve ser podado');
        $this->assertArrayNotHasKey('old2', $active);
        $this->assertArrayHasKey('freshclient01', $active, 'cliente atual deve permanecer');
        $this->assertFalse($this->seedCache()->has('mcp_state_messages_old1'), 'fila do ocioso deve ser deletada');
        $this->assertFalse($this->seedCache()->has('mcp_state_messages_old2'));
        $this->assertFalse($this->seedCache()->has('mcp_state_initialized_old1'));
    }

    public function test_initialize_tracks_client_and_prunes_excess_client_queues(): void
    {
        $cache = $this->seedCache();
        $active = [];
        for ($i = 0; $i < 55; $i++) {
            $clientId = sprintf('abandoned%02d', $i);
            $active[$clientId] = time() - 1;
            $cache->set('mcp_state_messages_' . $clientId, [['orphan' => true]], 3600);
            $cache->set('mcp_state_initialized_' . $clientId, true, 3600);
        }
        $cache->set('mcp_state_active_clients', $active, 3600);

        $this->makeAdapter()->handle(
            $this->initializeRequest(1),
            'initonly0001',
            'initialize'
        );

        $cache = $this->seedCache();
        $active = $cache->get('mcp_state_active_clients');

        $this->assertCount(50, $active);
        $this->assertArrayHasKey('initonly0001', $active);
        $this->assertArrayNotHasKey('abandoned00', $active);
        $this->assertFalse($cache->has('mcp_state_messages_abandoned00'));
        $this->assertFalse($cache->has('mcp_state_initialized_abandoned00'));
    }

    public function test_gc_enforces_hard_cap_on_active_clients(): void
    {
        $cache = $this->seedCache();
        $now = time();
        $many = [];
        for ($i = 0; $i < 55; $i++) {
            $many['c' . $i] = $now; // recentes (não podados por tempo) → força o teto
        }
        $cache->set('mcp_state_active_clients', $many, 3600);

        $this->makeAdapter()->handle($this->toolsListRequest(1), 'freshcap0001', 'tools/list');

        $active = $this->seedCache()->get('mcp_state_active_clients');
        $this->assertCount(50, $active, 'teto rígido de 50 clientes ativos');
        $this->assertArrayHasKey('freshcap0001', $active, 'cliente atual nunca é podado pelo teto');
    }
}
