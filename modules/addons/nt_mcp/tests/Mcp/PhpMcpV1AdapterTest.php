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
        \NtMcp\Tests\Support\WhmcsDateFormat::reset();
        $this->baseDir = dirname(__DIR__, 2) . '/src'; // .../nt_mcp/src (tem Tools/)
        $this->cacheDir = sys_get_temp_dir() . '/nt_mcp_adapter_test_' . bin2hex(random_bytes(6));
        @mkdir($this->cacheDir, 0700, true);
    }

    protected function tearDown(): void
    {
        \NtMcp\Tests\Support\WhmcsDateFormat::reset();
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

    /** Adapter com gates e callable controlados, para exercitar tools/call. */
    private function makeCallableAdapter(array $gates, callable $cb): PhpMcpV1Adapter
    {
        $api = new LocalApiClient('testadmin');
        $api->setGates($gates);
        $api->setCallable($cb);
        // Comandos de Project Manager / To-Do passam pelo clamp de impersonação,
        // que resolve o admin id no banco; sem WHMCS, o resolver injetado evita
        // que a rota morra antes de exercitar o que o teste quer provar.
        $api->setAdminIdResolver(static fn(string $username): int => 7);

        return new PhpMcpV1Adapter($api, new CapsuleClient(), $this->baseDir, $this->cacheDir);
    }

    private function toolsCallRequest(int $id, string $name, array $arguments): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }

    /** Extrai o payload de uma resposta tools/call (erro JSON-RPC ou result). */
    private function callOutcome(array $messages, int $id): array
    {
        foreach ($messages as $m) {
            if (($m['id'] ?? null) !== $id) {
                continue;
            }
            if (isset($m['error'])) {
                return ['jsonrpc_error' => $m['error']];
            }

            return $m['result'] ?? [];
        }

        return [];
    }

    /** Texto devolvido por uma tool bem/mal sucedida. */
    private function callText(array $messages, int $id): string
    {
        $outcome = $this->callOutcome($messages, $id);

        return $outcome['content'][0]['text'] ?? json_encode($outcome);
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

    public function test_canonical_tool_effect_matrix_remains_38_23_1_2(): void
    {
        $write = [
            'whmcs_create_client', 'whmcs_update_client', 'whmcs_add_contact', 'whmcs_update_contact',
            'whmcs_create_project', 'whmcs_update_project', 'whmcs_add_project_task',
            'whmcs_update_project_task', 'whmcs_start_task_timer', 'whmcs_end_task_timer',
            'whmcs_add_project_message', 'whmcs_crm_create_lead', 'whmcs_crm_update_contact',
            'whmcs_crm_add_followup', 'whmcs_crm_add_note', 'whmcs_update_todo_item',
            'whmcs_open_ticket', 'whmcs_reply_ticket', 'whmcs_update_ticket', 'whmcs_pending_order',
            'whmcs_create_quote', 'whmcs_update_quote', 'whmcs_duplicate_quote',
        ];
        $financial = ['whmcs_convert_quote_to_invoice'];
        $destructive = ['whmcs_cancel_order', 'whmcs_delete_quote'];
        $read = array_values(array_diff(self::CANONICAL_TOOLS, $write, $financial, $destructive));

        $this->assertCount(38, $read);
        $this->assertCount(23, $write);
        $this->assertCount(1, $financial);
        $this->assertCount(2, $destructive);

        $matrix = array_merge($read, $write, $financial, $destructive);
        $this->assertCount(64, array_unique($matrix));
        sort($matrix);
        $canonical = self::CANONICAL_TOOLS;
        sort($canonical);
        $this->assertSame($canonical, $matrix);
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

    // ---------------------------------------------------------------
    // M1 — datas pelo protocolo MCP REAL.
    //
    // O SchemaGenerator da v1.1 publica todo parâmetro cujo nome contenha
    // "date" como format: date-time, e o opis/json-schema VALIDA isso antes de
    // a tool ser chamada. Os testes diretos de classe não pegam esse degrau.
    // ---------------------------------------------------------------

    /** Contrato: o valor date-time publicado é aceito ponta a ponta. */
    public function test_date_time_value_is_accepted_end_to_end_and_normalized_for_whmcs(): void
    {
        $captured = [];
        $adapter = $this->makeCallableAdapter(['write' => true], function (string $cmd, array $params) use (&$captured) {
            $captured[$cmd] = $params;
            return ['result' => 'success'];
        });

        $messages = $adapter->handle(
            $this->toolsCallRequest(1, 'whmcs_create_quote', [
                'subject' => 'Q',
                'stage' => 'Draft',
                'proposal' => 'P',
                'datecreated' => '2026-08-10T00:00:00Z',
                'validuntil' => '2026-08-20',
            ]),
            'client-m1-date001',
            'tools/call'
        );

        $outcome = $this->callOutcome($messages, 1);
        $this->assertArrayNotHasKey('jsonrpc_error', $outcome, 'o schema publicado deve aceitar date-time');
        $this->assertFalse($outcome['isError'] ?? true, $this->callText($messages, 1));
        // CreateQuote documenta "localised format (eg DD/MM/YYYY)", NÃO Y-m-d.
        $this->assertSame('10/08/2026', $captured['CreateQuote']['datecreated']);
        $this->assertSame('20/08/2026', $captured['CreateQuote']['validuntil']);
    }

    /** GetActivityLog também documenta formato localizado. */
    public function test_date_time_value_is_accepted_end_to_end_on_a_read_tool(): void
    {
        $captured = [];
        $adapter = $this->makeCallableAdapter([], function (string $cmd, array $params) use (&$captured) {
            $captured[$cmd] = $params;
            return ['result' => 'success'];
        });

        $messages = $adapter->handle(
            $this->toolsCallRequest(1, 'whmcs_get_activity_log', ['date' => '2026-08-10T00:00:00Z']),
            'client-m1-date002',
            'tools/call'
        );

        $this->assertFalse($this->callOutcome($messages, 1)['isError'] ?? true, $this->callText($messages, 1));
        $this->assertSame('10/08/2026', $captured['GetActivityLog']['date']);
    }

    /**
     * As seis rotas que a API documenta como `Y-m-d` NÃO podem ser localizadas.
     * `UpdateInvoice.duedate` é a que carrega efeito financeiro.
     */
    public function test_ymd_routes_keep_ymd_downstream(): void
    {
        $captured = [];
        $adapter = $this->makeCallableAdapter(['financial' => true, 'write' => true], function (string $cmd, array $params) use (&$captured) {
            $captured[$cmd] = $params;
            if ($cmd === 'GetQuotes') {
                return ['result' => 'success', 'quotes' => ['quote' => [['id' => 10, 'stage' => 'Delivered']]]];
            }
            return ['result' => 'success', 'invoiceid' => 99];
        });

        $adapter->handle(
            $this->toolsCallRequest(1, 'whmcs_convert_quote_to_invoice', [
                'quoteid' => 10,
                'duedate' => '2026-08-10T00:00:00Z',
            ]),
            'client-m1-ymd0001',
            'tools/call'
        );
        $this->assertSame('2026-08-10', $captured['UpdateInvoice']['duedate'], 'UpdateInvoice documenta YYYY-mm-dd');

        $adapter->handle(
            $this->toolsCallRequest(2, 'whmcs_list_quotes', ['datecreated' => '2026-08-10T00:00:00Z']),
            'client-m1-ymd0002',
            'tools/call'
        );
        $this->assertSame('2026-08-10', $captured['GetQuotes']['datecreated'], 'GetQuotes documenta Y-m-d');

        $adapter->handle(
            $this->toolsCallRequest(3, 'whmcs_update_project', ['projectid' => 1, 'duedate' => '2026-08-10T00:00:00Z']),
            'client-m1-ymd0003',
            'tools/call'
        );
        $this->assertSame('2026-08-10', $captured['UpdateProject']['duedate'], 'UpdateProject documenta Y-m-d');
    }

    /** D9 completa: `lastmodified` atravessa a fronteira real do adapter. */
    public function test_list_quotes_lastmodified_rejects_localized_and_normalizes_public_dates(): void
    {
        $captured = [];
        $adapter = $this->makeCallableAdapter([], function (string $command, array $params) use (&$captured): array {
            $captured[] = [$command, $params];

            return ['result' => 'success', 'quotes' => ['quote' => []]];
        });

        $localized = $adapter->handle(
            $this->toolsCallRequest(41, 'whmcs_list_quotes', ['lastmodified' => '10/08/2026']),
            'client-lastmod-01',
            'tools/call'
        );
        $this->assertTrue($this->callOutcome($localized, 41)['isError'] ?? false);
        $this->assertSame([], $captured, 'data localizada não pode chegar ao GetQuotes');

        $iso = $adapter->handle(
            $this->toolsCallRequest(42, 'whmcs_list_quotes', ['lastmodified' => '2026-08-10T23:59:59-03:00']),
            'client-lastmod-02',
            'tools/call'
        );
        $this->assertFalse($this->callOutcome($iso, 42)['isError'] ?? true, $this->callText($iso, 42));
        $this->assertSame('2026-08-10', $captured[0][1]['lastmodified'] ?? null);

        $ymd = $adapter->handle(
            $this->toolsCallRequest(43, 'whmcs_list_quotes', ['lastmodified' => '2026-08-11']),
            'client-lastmod-03',
            'tools/call'
        );
        $this->assertFalse($this->callOutcome($ymd, 43)['isError'] ?? true, $this->callText($ymd, 43));
        $this->assertSame('2026-08-11', $captured[1][1]['lastmodified'] ?? null);
    }

    /**
     * `validuntil` aceita somente as duas famílias públicas não ambíguas pelo
     * protocolo real. A forma localizada é recusada em teste separado.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('validUntilInputProvider')]
    public function test_validuntil_accepts_unambiguous_forms_on_create_and_update(string $input): void
    {
        $captured = [];
        $adapter = $this->makeCallableAdapter(['write' => true], function (string $cmd, array $params) use (&$captured) {
            $captured[$cmd] = $params;
            return ['result' => 'success'];
        });

        $adapter->handle(
            $this->toolsCallRequest(1, 'whmcs_create_quote', [
                'subject' => 'Q', 'stage' => 'Draft', 'proposal' => 'P', 'validuntil' => $input,
            ]),
            'client-vu-' . substr(md5($input . 'c'), 0, 7),
            'tools/call'
        );
        $this->assertSame('10/08/2026', $captured['CreateQuote']['validuntil'] ?? null, "create com '{$input}'");

        $adapter->handle(
            $this->toolsCallRequest(2, 'whmcs_update_quote', ['quoteid' => 3, 'validuntil' => $input]),
            'client-vu-' . substr(md5($input . 'u'), 0, 7),
            'tools/call'
        );
        $this->assertSame('10/08/2026', $captured['UpdateQuote']['validuntil'] ?? null, "update com '{$input}'");
    }

    public static function validUntilInputProvider(): array
    {
        return [
            'Y-m-d'         => ['2026-08-10'],
            'ISO date-time' => ['2026-08-10T00:00:00Z'],
        ];
    }

    /**
     * D9: data localizada é RECUSADA na entrada, nas três rotas de cotação —
     * `10/08/2026` não pode ser distinguido entre 10 de agosto e 8 de outubro.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('localisedRejectionProvider')]
    public function test_localised_validuntil_is_rejected_on_every_quote_route(string $tool, array $args): void
    {
        $called = false;
        $adapter = $this->makeCallableAdapter(['write' => true], function (string $cmd) use (&$called) {
            if ($cmd === 'GetQuotes') {
                return ['result' => 'success', 'quotes' => ['quote' => [[
                    'id' => 10, 'subject' => 'S', 'stage' => 'Draft', 'proposal' => 'P', 'userid' => 3,
                ]]]];
            }
            $called = true;
            return ['result' => 'success'];
        });

        $messages = $adapter->handle(
            $this->toolsCallRequest(1, $tool, $args + ['validuntil' => '10/08/2026']),
            'client-loc-' . substr(md5($tool), 0, 6),
            'tools/call'
        );

        $this->assertTrue($this->callOutcome($messages, 1)['isError'] ?? false, "{$tool} deveria recusar localizado");
        $this->assertStringContainsString('unambiguous', $this->callText($messages, 1));
        $this->assertFalse($called, "{$tool} não pode escrever com data ambígua");
    }

    public static function localisedRejectionProvider(): array
    {
        return [
            'create'    => ['whmcs_create_quote', ['subject' => 'Q', 'stage' => 'Draft', 'proposal' => 'P']],
            'update'    => ['whmcs_update_quote', ['quoteid' => 3]],
            'duplicate' => ['whmcs_duplicate_quote', ['quoteid' => 10]],
        ];
    }

    /** ISO impossível não atravessa mais `validuntil` (o schema não o barra). */
    public function test_impossible_iso_is_rejected_on_validuntil(): void
    {
        $called = false;
        $adapter = $this->makeCallableAdapter(['write' => true], function () use (&$called) {
            $called = true;
            return ['result' => 'success'];
        });

        $messages = $adapter->handle(
            $this->toolsCallRequest(1, 'whmcs_create_quote', [
                'subject' => 'Q', 'stage' => 'Draft', 'proposal' => 'P',
                'validuntil' => '2026-08-10T99:99:99+99:99',
            ]),
            'client-badiso-001',
            'tools/call'
        );

        $this->assertTrue($this->callOutcome($messages, 1)['isError'] ?? false);
        $this->assertFalse($called);
    }

    /** O override do duplicate aceita as formas não ambíguas. */
    #[\PHPUnit\Framework\Attributes\DataProvider('validUntilInputProvider')]
    public function test_validuntil_override_on_duplicate_accepts_unambiguous_forms(string $input): void
    {
        $captured = [];
        $adapter = $this->makeCallableAdapter(['write' => true], function (string $cmd, array $params) use (&$captured) {
            $captured[$cmd] = $params;
            if ($cmd === 'GetQuotes') {
                return ['result' => 'success', 'quotes' => ['quote' => [[
                    'id' => 10, 'subject' => 'S', 'stage' => 'Draft', 'proposal' => 'P', 'userid' => 3,
                ]]]];
            }
            return ['result' => 'success', 'quoteid' => 11];
        });

        $adapter->handle(
            $this->toolsCallRequest(1, 'whmcs_duplicate_quote', ['quoteid' => 10, 'validuntil' => $input]),
            'client-vd-' . substr(md5($input), 0, 7),
            'tools/call'
        );

        $this->assertSame('10/08/2026', $captured['CreateQuote']['validuntil'] ?? null);
    }

    /** A herança de GetQuotes (Y-m-d) também é localizada na duplicação. */
    public function test_validuntil_inherited_from_getquotes_is_localised(): void
    {
        $captured = [];
        $adapter = $this->makeCallableAdapter(['write' => true], function (string $cmd, array $params) use (&$captured) {
            $captured[$cmd] = $params;
            if ($cmd === 'GetQuotes') {
                return ['result' => 'success', 'quotes' => ['quote' => [[
                    'id' => 10, 'subject' => 'S', 'stage' => 'Draft', 'proposal' => 'P', 'userid' => 3,
                    'validuntil' => '2026-08-10', 'datecreated' => '2026-07-01',
                ]]]];
            }
            return ['result' => 'success', 'quoteid' => 11];
        });

        $adapter->handle(
            $this->toolsCallRequest(1, 'whmcs_duplicate_quote', ['quoteid' => 10]),
            'client-vinherit01',
            'tools/call'
        );

        $this->assertSame('10/08/2026', $captured['CreateQuote']['validuntil']);
        $this->assertSame('01/07/2026', $captured['CreateQuote']['datecreated']);
    }

    /** Sem o inverso documentado, a rota localizada falha antes da LocalAPI. */
    public function test_validuntil_fails_closed_when_inverse_helper_is_broken(): void
    {
        \NtMcp\Tests\Support\WhmcsDateFormat::$parserAvailable = false;

        $called = false;
        $adapter = $this->makeCallableAdapter(['write' => true], function () use (&$called) {
            $called = true;
            return ['result' => 'success'];
        });

        $messages = $adapter->handle(
            $this->toolsCallRequest(1, 'whmcs_create_quote', [
                'subject' => 'Q', 'stage' => 'Draft', 'proposal' => 'P', 'validuntil' => '2026-08-10',
            ]),
            'client-vu-noparse',
            'tools/call'
        );

        $this->assertTrue($this->callOutcome($messages, 1)['isError'] ?? false);
        $this->assertFalse($called);
    }

    /** A configuração de data da instalação é respeitada, sem hardcode. */
    public function test_localised_route_follows_the_installation_date_format(): void
    {
        \NtMcp\Tests\Support\WhmcsDateFormat::$phpFormat = 'm/d/Y'; // MM/DD/YYYY

        $captured = [];
        $adapter = $this->makeCallableAdapter([], function (string $cmd, array $params) use (&$captured) {
            $captured[$cmd] = $params;
            return ['result' => 'success'];
        });

        $adapter->handle(
            $this->toolsCallRequest(1, 'whmcs_get_activity_log', ['date' => '2026-08-10T00:00:00Z']),
            'client-m1-fmt00001',
            'tools/call'
        );

        $this->assertSame('08/10/2026', $captured['GetActivityLog']['date']);
    }

    /** Localização quebrada falha FECHADO — nada chega à LocalAPI. */
    public function test_broken_localisation_fails_closed_before_localapi(): void
    {
        foreach ([
            \NtMcp\Tests\Support\WhmcsDateFormat::MODE_THROW,
            \NtMcp\Tests\Support\WhmcsDateFormat::MODE_EMPTY,
            \NtMcp\Tests\Support\WhmcsDateFormat::MODE_WRONG_DATE,
            \NtMcp\Tests\Support\WhmcsDateFormat::MODE_TWO_DIGIT_YEAR,
        ] as $i => $mode) {
            \NtMcp\Tests\Support\WhmcsDateFormat::reset();
            \NtMcp\Tests\Support\WhmcsDateFormat::$mode = $mode;

            $called = false;
            $adapter = $this->makeCallableAdapter([], function () use (&$called) {
                $called = true;
                return ['result' => 'success'];
            });

            $messages = $adapter->handle(
                $this->toolsCallRequest(1, 'whmcs_get_activity_log', ['date' => '2026-08-10T00:00:00Z']),
                'client-m1-fail' . str_pad((string) $i, 4, '0'),
                'tools/call'
            );

            $this->assertTrue($this->callOutcome($messages, 1)['isError'] ?? false, "modo {$mode} deveria falhar");
            $this->assertFalse($called, "modo {$mode} não pode alcançar a LocalAPI");
        }
    }

    /**
     * Timezone: preservamos a DATA CIVIL escrita, sem deslocar o dia por offset.
     * Os dois casos abaixo mudariam de dia se convertêssemos para UTC.
     */
    public function test_offsets_near_midnight_preserve_the_written_civil_date(): void
    {
        $captured = [];
        $adapter = $this->makeCallableAdapter([], function (string $cmd, array $params) use (&$captured) {
            $captured[] = $params['datecreated'] ?? null;
            return ['result' => 'success'];
        });

        // 23:30-03:00 seria 2026-08-11 em UTC; 00:30+05:00 seria 2026-08-09.
        foreach (['2026-08-10T23:30:00-03:00', '2026-08-10T00:30:00+05:00'] as $i => $iso) {
            $adapter->handle(
                $this->toolsCallRequest(1, 'whmcs_list_quotes', ['datecreated' => $iso]),
                'client-m1-tz' . str_pad((string) $i, 6, '0'),
                'tools/call'
            );
        }

        $this->assertSame(['2026-08-10', '2026-08-10'], $captured);
    }

    /** Toda tool que publica format=date-time aceita o valor date-time. */
    public function test_every_published_date_time_param_accepts_a_date_time_value(): void
    {
        $adapter = $this->makeAdapter();
        $messages = $adapter->handle($this->toolsListRequest(1), 'client-m1-scan001', 'tools/list');
        $tools = $this->toolsFrom($messages, 1) ?? [];

        $dateParams = [];
        foreach ($tools as $t) {
            foreach (($t['inputSchema']['properties'] ?? []) as $prop => $def) {
                if (($def['format'] ?? null) === 'date-time') {
                    $dateParams[] = [$t['name'], $prop];
                }
            }
        }

        $this->assertNotEmpty($dateParams, 'o heurístico da SDK deve continuar publicando date-time');

        foreach ($dateParams as $i => [$tool, $prop]) {
            $normalized = \NtMcp\Whmcs\DateNormalizer::tryNormalize('2026-08-10T00:00:00Z');
            $this->assertSame(
                '2026-08-10',
                $normalized,
                "o valor exigido pelo schema de {$tool}.{$prop} precisa ser normalizável para o formato WHMCS"
            );
        }
    }

    /**
     * Uma data impossível nunca chega ao WHMCS. Ela pode ser barrada em dois
     * degraus — o validator do schema (date-time inexistente) ou o
     * DateNormalizer — e o teste aceita ambos: o invariante é "rejeitada e sem
     * efeito", não qual camada rejeitou.
     */
    public function test_impossible_date_never_reaches_whmcs(): void
    {
        foreach (['2026-02-31T00:00:00Z', '2026-02-31'] as $i => $badDate) {
            $called = false;
            $adapter = $this->makeCallableAdapter([], function () use (&$called) {
                $called = true;
                return ['result' => 'success'];
            });

            $messages = $adapter->handle(
                $this->toolsCallRequest(1, 'whmcs_get_activity_log', ['date' => $badDate]),
                'client-m1-bad' . str_pad((string) $i, 5, '0'),
                'tools/call'
            );

            $outcome = $this->callOutcome($messages, 1);
            $rejected = isset($outcome['jsonrpc_error']) || ($outcome['isError'] ?? false);

            $this->assertTrue($rejected, "data impossível aceita: {$badDate}");
            $this->assertFalse($called, "data impossível chegou ao WHMCS: {$badDate}");
        }
    }

    // ---------------------------------------------------------------
    // M2 — contrato parcial sobrevive a exceção, pelo protocolo real.
    // ---------------------------------------------------------------

    public function test_exception_after_first_effect_still_returns_partial_contract(): void
    {
        $adapter = $this->makeCallableAdapter(['financial' => true], function (string $cmd) {
            if ($cmd === 'GetQuotes') {
                return ['result' => 'success', 'quotes' => ['quote' => [['id' => 10, 'stage' => 'Delivered']]]];
            }
            if ($cmd === 'AcceptQuote') {
                return ['result' => 'success', 'invoiceid' => 99];
            }

            throw new \RuntimeException('transport died');
        });

        $messages = $adapter->handle(
            $this->toolsCallRequest(1, 'whmcs_convert_quote_to_invoice', [
                'quoteid' => 10,
                'duedate' => '2026-08-10T00:00:00Z',
            ]),
            'client-m2-partial1',
            'tools/call'
        );

        $text = $this->callText($messages, 1);
        $this->assertStringNotContainsString('Tool execution failed', $text, 'não pode virar erro genérico');

        $payload = json_decode($text, true);
        $this->assertIsArray($payload, "resposta não é JSON do contrato: {$text}");
        $this->assertSame('error', $payload['result']);
        $this->assertTrue($payload['partial']);
        $this->assertSame(10, $payload['quoteid']);
        $this->assertSame(99, $payload['invoiceid']);
        $this->assertStringContainsString('NÃO repetir', $payload['warning']);
    }

    public function test_exception_on_first_effect_returns_indeterminate_partial(): void
    {
        $adapter = $this->makeCallableAdapter(['financial' => true], function (string $cmd) {
            if ($cmd === 'GetQuotes') {
                return ['result' => 'success', 'quotes' => ['quote' => [['id' => 10, 'stage' => 'Delivered']]]];
            }

            throw new \RuntimeException('timeout');
        });

        $messages = $adapter->handle(
            $this->toolsCallRequest(1, 'whmcs_convert_quote_to_invoice', ['quoteid' => 10]),
            'client-m2-partial2',
            'tools/call'
        );

        $payload = json_decode($this->callText($messages, 1), true);
        $this->assertIsArray($payload);
        $this->assertSame('error', $payload['result']);
        $this->assertTrue($payload['partial']);
        $this->assertStringContainsString('MAY have been accepted', $payload['message']);
        $this->assertStringContainsString('NÃO repetir', $payload['warning']);
    }

    /** M2: `result:error` de AcceptQuote continua indeterminado pós-efeito. */
    public function test_accept_quote_error_array_returns_indeterminate_partial_via_adapter(): void
    {
        $adapter = $this->makeCallableAdapter(['financial' => true], static function (string $command): array {
            if ($command === 'GetQuotes') {
                return ['result' => 'success', 'quotes' => ['quote' => [['id' => 10, 'stage' => 'Delivered']]]];
            }

            return ['result' => 'error', 'message' => 'Quote Already Accepted'];
        });

        $messages = $adapter->handle(
            $this->toolsCallRequest(51, 'whmcs_convert_quote_to_invoice', ['quoteid' => 10]),
            'client-m2-error01',
            'tools/call'
        );

        $payload = json_decode($this->callText($messages, 51), true);
        $this->assertSame('error', $payload['result'] ?? null);
        $this->assertTrue($payload['partial'] ?? false);
        $this->assertSame(10, $payload['quoteid'] ?? null);
        $this->assertArrayNotHasKey('invoiceid', $payload);
        $this->assertStringContainsString('MAY have been accepted', $payload['message'] ?? '');
        $this->assertStringContainsString('NÃO repetir', $payload['warning'] ?? '');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}\z/', $payload['correlation_id'] ?? '');
    }

    // ---------------------------------------------------------------
    // M4 — gateway validado antes do primeiro efeito, pelo protocolo real.
    // ---------------------------------------------------------------

    public function test_unknown_payment_gateway_is_rejected_before_any_effect(): void
    {
        $calls = [];
        $adapter = $this->makeCallableAdapter(['financial' => true], function (string $cmd) use (&$calls) {
            $calls[] = $cmd;
            if ($cmd === 'GetQuotes') {
                return ['result' => 'success', 'quotes' => ['quote' => [['id' => 10, 'stage' => 'Delivered']]]];
            }
            return ['result' => 'success', 'invoiceid' => 99];
        });

        $messages = $adapter->handle(
            $this->toolsCallRequest(1, 'whmcs_convert_quote_to_invoice', [
                'quoteid' => 10,
                'paymentmethod' => 'definitely-not-a-gateway',
            ]),
            'client-m4-gateway1',
            'tools/call'
        );

        $this->assertTrue($this->callOutcome($messages, 1)['isError'] ?? false);
        // Sem WHMCS bootstrapado a introspecção é indisponível ⇒ fail-closed.
        $this->assertStringContainsString('gateway', $this->callText($messages, 1));
        $this->assertSame([], $calls, 'nenhum efeito pode ocorrer sem validar o gateway');
    }

    /**
     * `duedate` saiu de `whmcs_update_todo_item`: o formato aceito pela API não
     * pôde ser provado (doc diz `int`, a coluna é `date`, o parser é ionCube).
     * A tool continua existindo e funcional para os demais campos.
     */
    public function test_update_todo_item_no_longer_publishes_duedate(): void
    {
        $adapter = $this->makeAdapter();
        $messages = $adapter->handle($this->toolsListRequest(1), 'client-todo-00001', 'tools/list');

        $tool = null;
        foreach ($this->toolsFrom($messages, 1) ?? [] as $t) {
            if (($t['name'] ?? '') === 'whmcs_update_todo_item') {
                $tool = $t;
            }
        }

        $this->assertNotNull($tool, 'a tool deve continuar registrada');
        $properties = $tool['inputSchema']['properties'] ?? [];
        $this->assertArrayNotHasKey('duedate', $properties);
        foreach (['itemid', 'status', 'title', 'description'] as $kept) {
            $this->assertArrayHasKey($kept, $properties, "{$kept} deveria continuar exposto");
        }
    }

    public function test_update_todo_item_still_works_for_the_remaining_fields(): void
    {
        $captured = [];
        $adapter = $this->makeCallableAdapter(['write' => true], function (string $cmd, array $params) use (&$captured) {
            $captured[$cmd] = $params;
            return ['result' => 'success'];
        });

        $messages = $adapter->handle(
            $this->toolsCallRequest(1, 'whmcs_update_todo_item', ['itemid' => 5, 'status' => 'Completed']),
            'client-todo-00002',
            'tools/call'
        );

        $this->assertFalse($this->callOutcome($messages, 1)['isError'] ?? true, $this->callText($messages, 1));
        $this->assertSame('Completed', $captured['UpdateToDoItem']['status']);
        $this->assertArrayNotHasKey('duedate', $captured['UpdateToDoItem']);
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
