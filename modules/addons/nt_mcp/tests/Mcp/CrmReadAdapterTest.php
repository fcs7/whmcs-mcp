<?php

declare(strict_types=1);

namespace NtMcp\Tests\Mcp;

use NtMcp\Crm\CrmSchema;
use NtMcp\Crm\CrmSchemaGuard;
use NtMcp\Crm\MgCrmRepository;
use NtMcp\Mcp\PhpMcpV1Adapter;
use NtMcp\Tests\Support\FakeAdminIdentityResolver;
use NtMcp\Tests\Support\FakeCrmQueryPort;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use NtMcp\Whmcs\CapsuleClient;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * As quatro leituras de CRM pelo ADAPTER REAL, não pela classe.
 *
 * O degrau que só aparece aqui é o schema publicado pela lib: o
 * `SchemaGenerator` da v1.1 deriva as propriedades dos parâmetros PHP e o
 * `opis/json-schema` valida o input ANTES de a tool ser chamada. É esse degrau
 * que prova a troca de `id`/`contactId`/`type` por `resource_id`/`type_id` — um
 * teste que chamasse `$tools->getContact(1)` diretamente aceitaria qualquer
 * nome, porque não passa pelo schema.
 */
class CrmReadAdapterTest extends TestCase
{
    private string $cacheDir;
    private string $baseDir;
    private FakeCrmQueryPort $port;

    protected function setUp(): void
    {
        $this->baseDir = dirname(__DIR__, 2) . '/src';
        $this->cacheDir = sys_get_temp_dir() . '/nt_mcp_crm_read_' . bin2hex(random_bytes(6));
        @mkdir($this->cacheDir, 0700, true);

        $this->port = new FakeCrmQueryPort();
        $this->seedInstallation();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    /** Uma instalação mgCRM2 pequena, porém completa. */
    private function seedInstallation(): void
    {
        $this->port->seed(CrmSchema::TABLE_RESOURCES, [
            [
                'id' => 42, 'type_id' => 1, 'status_id' => 10,
                'name' => 'Ana', 'lastname' => 'Souza', 'email' => 'ana@example.test',
                'phone' => '+55 11 90000-0000', 'country' => 'BR',
                'short_description' => 'curta', 'description' => 'longa',
                'created_at' => '2026-07-01 10:00:00', 'updated_at' => '2026-07-02 10:00:00',
                'deleted_at' => null,
            ],
            [
                'id' => 43, 'type_id' => 2, 'status_id' => 11,
                'name' => 'Bruno', 'lastname' => null, 'email' => 'bruno@example.test',
                'phone' => null, 'country' => 'BR',
                'short_description' => null, 'description' => null,
                'created_at' => '2026-07-03 10:00:00', 'updated_at' => '2026-07-03 10:00:00',
                'deleted_at' => null,
            ],
            [
                'id' => 44, 'type_id' => 1, 'status_id' => 10,
                'name' => 'Removido', 'lastname' => null, 'email' => 'x@example.test',
                'phone' => null, 'country' => 'BR',
                'short_description' => null, 'description' => null,
                'created_at' => '2026-07-04 10:00:00', 'updated_at' => '2026-07-04 10:00:00',
                'deleted_at' => '2026-07-05 00:00:00',
            ],
        ]);

        $this->port->seed(CrmSchema::TABLE_RESOURCE_TYPES, [
            ['id' => 1, 'name' => 'Lead', 'active' => 1, 'deleted_at' => null],
            ['id' => 2, 'name' => 'Cliente', 'active' => 1, 'deleted_at' => null],
            ['id' => 3, 'name' => 'Inativo', 'active' => 0, 'deleted_at' => null],
        ]);
        $this->port->seed(CrmSchema::TABLE_RESOURCE_STATUSES, [
            ['id' => 10, 'name' => 'Aberto', 'active' => 1, 'deleted_at' => null],
            ['id' => 11, 'name' => 'Ganho', 'active' => 1, 'deleted_at' => null],
        ]);
        $this->port->seed(CrmSchema::TABLE_FOLLOWUP_TYPES, [
            ['id' => 20, 'name' => 'Ligacao', 'active' => 1, 'deleted_at' => null],
        ]);
        $this->port->seed(CrmSchema::TABLE_FOLLOWUP_STATUSES, [
            ['id' => 30, 'name' => 'Pendente', 'active' => 1, 'deleted_at' => null],
        ]);
        $this->port->seed(CrmSchema::TABLE_FOLLOWUPS, [
            [
                'id' => 1, 'resource_id' => 42, 'type_id' => 20, 'status_id' => 30,
                'admin_id' => 9, 'description' => 'primeiro contato',
                'date' => '2026-07-10 09:00:00',
                'created_at' => '2026-07-09 09:00:00', 'updated_at' => '2026-07-09 09:00:00',
                'deleted_at' => null,
            ],
        ]);
        $this->port->seed(CrmSchema::TABLE_FIELDS, [
            ['id' => 5, 'name' => 'Company Name', 'deleted_at' => null],
        ]);
        $this->port->seed(CrmSchema::TABLE_FIELD_VALUES, [
            ['id' => 100, 'field_id' => 5, 'resource_id' => 42, 'value' => 'NT Web'],
        ]);
    }

    private function adapter(?FakeCrmSchemaProbe $probe = null): PhpMcpV1Adapter
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable(fn() => ['result' => 'success']);

        $repository = new MgCrmRepository(
            new CrmSchemaGuard($probe ?? FakeCrmSchemaProbe::healthy()),
            $this->port,
            FakeAdminIdentityResolver::resolvingTo(7),
        );

        return new PhpMcpV1Adapter($api, new CapsuleClient(), $this->baseDir, $this->cacheDir, $repository);
    }

    /** As quatro READ pelo adapter entram uma vez cada no snapshot da resposta. */
    public function test_every_public_crm_read_enters_exactly_one_snapshot(): void
    {
        $this->payload('whmcs_crm_list_contacts', []);
        $this->payload('whmcs_crm_get_contact', ['resource_id' => 42]);
        $this->payload('whmcs_crm_list_followups', ['resource_id' => 42]);
        $this->payload('whmcs_crm_get_kanban', []);

        $this->assertSame(4, $this->port->snapshotCount);
    }

    /** @param array<string, mixed> $arguments */
    private function call(string $tool, array $arguments, ?FakeCrmSchemaProbe $probe = null): array
    {
        $messages = $this->adapter($probe)->handle(
            (string) json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ]),
            'crm-read-' . substr(sha1($tool . serialize($arguments)), 0, 12),
            'tools/call'
        );

        foreach ($messages as $message) {
            if (($message['id'] ?? null) !== 1) {
                continue;
            }

            if (isset($message['error'])) {
                return ['jsonrpc_error' => $message['error']];
            }

            return $message['result'] ?? [];
        }

        return [];
    }

    /**
     * Payload decodificado de uma chamada bem-sucedida no nível do protocolo.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function payload(string $tool, array $arguments, ?FakeCrmSchemaProbe $probe = null): array
    {
        $outcome = $this->call($tool, $arguments, $probe);

        $this->assertArrayNotHasKey(
            'jsonrpc_error',
            $outcome,
            'o schema publicado deveria aceitar este input: ' . json_encode($outcome)
        );

        $text = $outcome['content'][0]['text'] ?? '';
        $decoded = json_decode((string) $text, true);

        $this->assertIsArray($decoded, 'a tool deve devolver JSON: ' . (string) $text);

        return $decoded;
    }

    // ---------------------------------------------------------------
    // Contratos de saída
    // ---------------------------------------------------------------

    public function test_list_contacts_returns_the_paginated_contract(): void
    {
        $payload = $this->payload('whmcs_crm_list_contacts', ['limit' => 1]);

        $this->assertSame(['items', 'count', 'limit', 'offset', 'has_more'], array_keys($payload));
        $this->assertSame(2, $payload['count'], 'o soft-deleted não conta');
        $this->assertSame(1, $payload['limit']);
        $this->assertSame(0, $payload['offset']);
        $this->assertTrue($payload['has_more']);
        $this->assertSame(43, $payload['items'][0]['resource_id'], 'ordenação id desc');
        $this->assertArrayNotHasKey('id', $payload['items'][0]);
    }

    public function test_list_contacts_filters_by_catalog_ids(): void
    {
        $payload = $this->payload('whmcs_crm_list_contacts', ['type_id' => 2, 'status_id' => 11]);

        $this->assertSame(1, $payload['count']);
        $this->assertSame([43], array_column($payload['items'], 'resource_id'));
    }

    public function test_get_contact_returns_core_plus_normalized_custom_fields(): void
    {
        $payload = $this->payload('whmcs_crm_get_contact', ['resource_id' => 42]);

        $this->assertSame(['resource', 'custom_fields'], array_keys($payload));
        $this->assertSame(42, $payload['resource']['resource_id']);
        $this->assertSame('Ana', $payload['resource']['name']);
        $this->assertSame(
            [['field_id' => 5, 'name' => 'Company Name', 'value' => 'NT Web']],
            $payload['custom_fields']
        );
    }

    public function test_list_followups_resolves_labels(): void
    {
        $payload = $this->payload('whmcs_crm_list_followups', ['resource_id' => 42]);

        $this->assertSame(['items', 'count', 'limit', 'offset', 'has_more'], array_keys($payload));
        $this->assertSame(1, $payload['count']);
        $this->assertSame(1, $payload['items'][0]['followup_id']);
        $this->assertSame('Ligacao', $payload['items'][0]['type_name']);
        $this->assertSame('Pendente', $payload['items'][0]['status_name']);
        $this->assertArrayNotHasKey('admin_id', $payload['items'][0]);
    }

    public function test_get_kanban_publishes_catalogs_and_lanes(): void
    {
        $payload = $this->payload('whmcs_crm_get_kanban', []);

        $this->assertSame(
            [
                'type_id', 'limit_per_status',
                'status_count', 'status_limit', 'status_offset', 'status_has_more',
                'catalogs', 'lanes',
            ],
            array_keys($payload),
            'shape D14 estável; lanes_truncated não existe mais'
        );
        $this->assertNull($payload['type_id']);
        $this->assertSame(25, $payload['limit_per_status']);
        $this->assertSame(2, $payload['status_count']);
        $this->assertSame(25, $payload['status_limit'], 'default D14');
        $this->assertSame(0, $payload['status_offset'], 'default D14');
        $this->assertFalse($payload['status_has_more']);
        $this->assertSame(
            ['resource_types', 'resource_statuses', 'followup_types', 'followup_statuses'],
            array_keys($payload['catalogs'])
        );
        $this->assertSame(
            [['id' => 2, 'name' => 'Cliente'], ['id' => 1, 'name' => 'Lead']],
            $payload['catalogs']['resource_types'],
            'catálogo inativo não é publicado'
        );

        $this->assertCount(2, $payload['lanes']);
        $this->assertSame(10, $payload['lanes'][0]['status_id']);
        $this->assertSame('Aberto', $payload['lanes'][0]['status_name']);
        $this->assertSame(1, $payload['lanes'][0]['total'], 'o soft-deleted não entra no total');
        $this->assertSame([42], array_column($payload['lanes'][0]['items'], 'resource_id'));
        $this->assertFalse($payload['lanes'][0]['has_more']);
    }

    /** Duas chamadas idênticas produzem a MESMA saída, byte a byte. */
    public function test_read_output_is_stable_across_calls(): void
    {
        $first = $this->payload('whmcs_crm_get_kanban', ['limit_per_status' => 5]);
        $second = $this->payload('whmcs_crm_get_kanban', ['limit_per_status' => 5]);

        $this->assertSame(json_encode($first), json_encode($second));
    }

    // ---------------------------------------------------------------
    // Troca dos nomes ambíguos
    // ---------------------------------------------------------------

    /**
     * Os nomes antigos não são mais aceitos onde eram OBRIGATÓRIOS: o schema
     * publicado exige `resource_id` e recusa a chamada antes da tool.
     *
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('legacyIdentityProvider')]
    public function test_legacy_identity_parameters_are_rejected(string $tool, array $arguments): void
    {
        $outcome = $this->call($tool, $arguments);

        $this->assertArrayHasKey('jsonrpc_error', $outcome, json_encode($outcome));
        $this->assertSame(-32602, $outcome['jsonrpc_error']['code'] ?? null);
    }

    /** @return array<string, array{0:string, 1:array<string, mixed>}> */
    public static function legacyIdentityProvider(): array
    {
        return [
            'get_contact com id' => ['whmcs_crm_get_contact', ['id' => 42]],
            'get_contact com contactId' => ['whmcs_crm_get_contact', ['contactId' => 42]],
            'get_contact sem identidade' => ['whmcs_crm_get_contact', []],
            'list_followups com contactId' => ['whmcs_crm_list_followups', ['contactId' => 42]],
            'list_followups com id' => ['whmcs_crm_list_followups', ['id' => 42]],
        ];
    }

    /**
     * `type` (string livre) saiu de `list_contacts`: o filtro agora é `type_id`
     * inteiro, e uma string não passa pelo schema.
     */
    public function test_the_legacy_string_type_filter_is_gone(): void
    {
        $schema = $this->publishedSchema('whmcs_crm_list_contacts');

        $this->assertArrayNotHasKey('type', $schema['properties']);
        $this->assertSame(['integer', 'null'], $schema['properties']['type_id']['type']);

        $outcome = $this->call('whmcs_crm_list_contacts', ['type_id' => 'lead']);
        $this->assertSame(-32602, $outcome['jsonrpc_error']['code'] ?? null);
    }

    /** Os quatro schemas READ publicam exatamente o contrato revisado. */
    public function test_published_read_schemas_match_the_revised_contract(): void
    {
        $this->assertSame(
            ['type_id', 'status_id', 'limit', 'offset'],
            array_keys($this->publishedSchema('whmcs_crm_list_contacts')['properties'])
        );

        $contact = $this->publishedSchema('whmcs_crm_get_contact');
        $this->assertSame(['resource_id'], array_keys($contact['properties']));
        $this->assertSame(['resource_id'], $contact['required']);

        $followups = $this->publishedSchema('whmcs_crm_list_followups');
        $this->assertSame(
            ['resource_id', 'type_id', 'status_id', 'limit', 'offset'],
            array_keys($followups['properties'])
        );
        $this->assertSame(['resource_id'], $followups['required']);

        $kanban = $this->publishedSchema('whmcs_crm_get_kanban');
        $this->assertSame(
            ['type_id', 'limit_per_status', 'status_limit', 'status_offset'],
            array_keys($kanban['properties'])
        );
        $this->assertArrayNotHasKey('required', $kanban, 'kanban não exige nada do chamador');

        // D14: a faixa de status_limit é contrato publicado, não clamp mudo.
        $this->assertSame(1, $kanban['properties']['status_limit']['minimum'] ?? null);
        $this->assertSame(25, $kanban['properties']['status_limit']['maximum'] ?? null);
        $this->assertSame(25, $kanban['properties']['status_limit']['default'] ?? null);
        $this->assertSame(0, $kanban['properties']['status_offset']['minimum'] ?? null);
        $this->assertSame(0, $kanban['properties']['status_offset']['default'] ?? null);
    }

    /**
     * As quatro ESCRITAS continuam com o schema legado, intocado. Se uma delas
     * mudar aqui, CRM-2 saiu do seu escopo.
     */
    public function test_write_schemas_are_untouched_until_crm_3(): void
    {
        $this->assertSame(
            ['name', 'email', 'phone', 'company', 'notes'],
            array_keys($this->publishedSchema('whmcs_crm_create_lead')['properties'])
        );
        $this->assertSame(
            ['id', 'name', 'email', 'phone', 'company', 'notes', 'status', 'stage'],
            array_keys($this->publishedSchema('whmcs_crm_update_contact')['properties'])
        );
        $this->assertSame(
            ['contactId', 'note', 'duedate'],
            array_keys($this->publishedSchema('whmcs_crm_add_followup')['properties'])
        );
        $this->assertSame(
            ['contactId', 'note'],
            array_keys($this->publishedSchema('whmcs_crm_add_note')['properties'])
        );
    }

    // ---------------------------------------------------------------
    // Erros canônicos pelo protocolo
    // ---------------------------------------------------------------

    /**
     * A falha de domínio chega ao chamador como o contrato fechado do CRM-1,
     * com `error_code` — e não como texto solto de exceção.
     *
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('canonicalErrorProvider')]
    public function test_domain_failures_surface_as_canonical_error_codes(
        string $tool,
        array $arguments,
        string $expectedCode,
        ?string $dropTable,
    ): void {
        $probe = $dropTable === null
            ? FakeCrmSchemaProbe::healthy()
            : FakeCrmSchemaProbe::healthy()->dropTable($dropTable);

        $payload = $this->payload($tool, $arguments, $probe);

        $this->assertSame('error', $payload['result']);
        $this->assertSame($expectedCode, $payload['error_code']);
        $this->assertArrayNotHasKey('items', $payload, 'erro não pode parecer resultado vazio');

        foreach (['SELECT', 'crm_resources', 'mod_mgcrm_', 'PDO', 'ana@example.test'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload['message']);
        }
    }

    /** @return array<string, array{0:string, 1:array<string, mixed>, 2:string, 3:string|null}> */
    public static function canonicalErrorProvider(): array
    {
        return [
            'recurso ausente' => [
                'whmcs_crm_get_contact', ['resource_id' => 999], 'crm_resource_not_found', null,
            ],
            'recurso soft-deleted' => [
                'whmcs_crm_get_contact', ['resource_id' => 44], 'crm_resource_not_found', null,
            ],
            'catálogo inativo no filtro' => [
                'whmcs_crm_list_contacts', ['type_id' => 3], 'crm_catalog_invalid', null,
            ],
            'catálogo inexistente no kanban' => [
                'whmcs_crm_get_kanban', ['type_id' => 999], 'crm_catalog_invalid', null,
            ],
            'addon ausente' => [
                'whmcs_crm_list_contacts', [], 'crm_unavailable', CrmSchema::TABLE_RESOURCES,
            ],
            'custom fields ausentes' => [
                'whmcs_crm_get_contact', ['resource_id' => 42], 'crm_unavailable', CrmSchema::TABLE_FIELDS,
            ],
            'follow-ups ausentes' => [
                'whmcs_crm_list_followups', ['resource_id' => 42], 'crm_unavailable', CrmSchema::TABLE_FOLLOWUPS,
            ],
        ];
    }

    // ---------------------------------------------------------------
    // D14 — paginação de raias pelo protocolo real
    // ---------------------------------------------------------------

    /** Semeia N resource statuses ativos, substituindo os do fixture. */
    private function seedManyStatuses(int $count): void
    {
        $this->port->seed(CrmSchema::TABLE_RESOURCE_STATUSES, array_map(
            static fn(int $id): array => [
                'id' => $id,
                'name' => sprintf('S%05d', $id),
                'active' => 1,
                'deleted_at' => null,
            ],
            range(1, $count)
        ));
    }

    /** Defaults de D14 aparecem sem o chamador pedir nada. */
    public function test_kanban_defaults_follow_d14(): void
    {
        $this->seedManyStatuses(40);

        $payload = $this->payload('whmcs_crm_get_kanban', []);

        $this->assertSame(40, $payload['status_count']);
        $this->assertSame(25, $payload['status_limit']);
        $this->assertSame(0, $payload['status_offset']);
        $this->assertTrue($payload['status_has_more']);
        $this->assertCount(25, $payload['lanes']);
        $this->assertCount(40, $payload['catalogs']['resource_statuses'], 'catálogo completo');
    }

    /** Páginas consecutivas cobrem todo o catálogo sem repetir nem pular. */
    public function test_consecutive_lane_pages_cover_every_status(): void
    {
        $this->seedManyStatuses(60);

        $seen = [];
        for ($offset = 0; $offset < 75; $offset += 25) {
            $page = $this->payload('whmcs_crm_get_kanban', [
                'status_limit' => 25,
                'status_offset' => $offset,
            ]);

            $this->assertSame($offset, $page['status_offset']);
            $this->assertSame(60, $page['status_count']);

            foreach ($page['lanes'] as $lane) {
                $seen[] = $lane['status_id'];
            }
        }

        $this->assertSame(range(1, 60), $seen, 'sem duplicata e sem omissão');
        $this->assertCount(60, array_unique($seen));
    }

    /** Página além do fim: vazia, sem erro, sem `status_has_more`. */
    public function test_lane_page_past_the_end_is_empty_over_the_protocol(): void
    {
        $this->seedManyStatuses(30);

        $payload = $this->payload('whmcs_crm_get_kanban', [
            'status_limit' => 25,
            'status_offset' => 30,
        ]);

        $this->assertSame([], $payload['lanes']);
        $this->assertFalse($payload['status_has_more']);
        $this->assertCount(30, $payload['catalogs']['resource_statuses']);
    }

    /** Offset aceito pelo schema não pode ser reescrito silenciosamente. */
    public function test_very_large_legal_lane_offset_is_echoed_exactly_over_the_adapter(): void
    {
        $this->seedManyStatuses(30);

        foreach ([100001, PHP_INT_MAX] as $offset) {
            $payload = $this->payload('whmcs_crm_get_kanban', [
                'status_limit' => 25,
                'status_offset' => $offset,
            ]);

            $this->assertSame($offset, $payload['status_offset']);
            $this->assertSame([], $payload['lanes']);
            $this->assertFalse($payload['status_has_more']);
        }
    }

    /** `status_limit` acima do teto é recusado pelo schema, não clampado mudo. */
    public function test_status_limit_above_the_ceiling_is_refused(): void
    {
        $outcome = $this->call('whmcs_crm_get_kanban', ['status_limit' => 26]);

        $this->assertSame(-32602, $outcome['jsonrpc_error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Boundary MCP — isError e Throwable envenenado
    // ---------------------------------------------------------------

    /**
     * Falha de domínio é ERRO MCP, não sucesso com conteúdo de erro.
     *
     * A SDK só marca `isError` quando a tool lança; deixá-la lançar publicaria
     * a mensagem crua pelo formatter. Por isso a marcação é feita na fronteira,
     * a partir do envelope canônico.
     *
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('canonicalErrorProvider')]
    public function test_domain_failures_are_marked_as_mcp_errors(
        string $tool,
        array $arguments,
        string $expectedCode,
        ?string $dropTable,
    ): void {
        $probe = $dropTable === null
            ? FakeCrmSchemaProbe::healthy()
            : FakeCrmSchemaProbe::healthy()->dropTable($dropTable);

        $outcome = $this->call($tool, $arguments, $probe);

        $this->assertArrayNotHasKey('jsonrpc_error', $outcome);
        $this->assertTrue($outcome['isError'] ?? false, 'falha de leitura não pode sair como sucesso');

        $decoded = json_decode((string) ($outcome['content'][0]['text'] ?? ''), true);
        $this->assertSame($expectedCode, $decoded['error_code'] ?? null);
    }

    /** Sucesso continua sucesso — a marcação é restrita ao envelope de erro. */
    public function test_successful_reads_are_not_marked_as_errors(): void
    {
        foreach ([
            ['whmcs_crm_list_contacts', []],
            ['whmcs_crm_get_contact', ['resource_id' => 42]],
            ['whmcs_crm_list_followups', ['resource_id' => 42]],
            ['whmcs_crm_get_kanban', []],
        ] as [$tool, $arguments]) {
            $outcome = $this->call($tool, $arguments);

            $this->assertFalse($outcome['isError'] ?? true, "{$tool} deveria ser sucesso");
        }
    }

    /**
     * `Throwable` inesperado com mensagem envenenada: vira `downstream`
     * sanitizado, com `isError:true`, e NADA da causa chega ao payload.
     *
     * A reprodução da revisão fria publicou SQLSTATE, senha, path e e-mail por
     * este caminho, via `'Tool execution failed: ' . $e->getMessage()`.
     */
    public function test_poisoned_throwable_never_reaches_the_payload(): void
    {
        $poison = 'SQLSTATE[HY000] password=hunter2 /srv/whmcs/configuration.php '
            . 'user@example.test cpf=11144477735 SELECT * FROM crm_resources';

        $this->port->failWithRaw(new \RuntimeException($poison));

        $outcome = $this->call('whmcs_crm_get_contact', ['resource_id' => 42]);

        $this->assertArrayNotHasKey('jsonrpc_error', $outcome);
        $this->assertTrue($outcome['isError'] ?? false);

        $text = (string) ($outcome['content'][0]['text'] ?? '');
        $decoded = json_decode($text, true);

        $this->assertSame('downstream', $decoded['error_code'] ?? null);
        $this->assertNotEmpty($decoded['correlation_id'] ?? '');

        foreach ([
            'SQLSTATE', 'hunter2', '/srv/whmcs', 'user@example.test',
            '11144477735', 'SELECT', 'crm_resources', 'RuntimeException',
            'Tool execution failed',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, "vazou: {$forbidden}");
        }
    }

    // ---------------------------------------------------------------
    // Argumentos fechados
    // ---------------------------------------------------------------

    /**
     * Nome legado SOZINHO e AO LADO do canônico: os dois são recusados antes da
     * invocação. Antes, `{type:"lead"}` era ignorado e a leitura devolvia a
     * base inteira; `{resource_id:42, id:999}` passava com a identidade ambígua.
     *
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('unknownArgumentProvider')]
    public function test_unknown_arguments_are_rejected(string $tool, array $arguments): void
    {
        $outcome = $this->call($tool, $arguments);

        $this->assertArrayHasKey('jsonrpc_error', $outcome, json_encode($outcome));
        $this->assertSame(-32602, $outcome['jsonrpc_error']['code'] ?? null);
    }

    /** @return array<string, array{0:string, 1:array<string, mixed>}> */
    public static function unknownArgumentProvider(): array
    {
        return [
            'list_contacts type sozinho' => ['whmcs_crm_list_contacts', ['type' => 'lead']],
            'list_contacts type + type_id' => [
                'whmcs_crm_list_contacts', ['type' => 'lead', 'type_id' => 1],
            ],
            'get_contact id + resource_id' => [
                'whmcs_crm_get_contact', ['resource_id' => 42, 'id' => 999],
            ],
            'get_contact contactId + resource_id' => [
                'whmcs_crm_get_contact', ['resource_id' => 42, 'contactId' => 999],
            ],
            'list_followups contactId + resource_id' => [
                'whmcs_crm_list_followups', ['resource_id' => 42, 'contactId' => 999],
            ],
            'list_followups id + resource_id' => [
                'whmcs_crm_list_followups', ['resource_id' => 42, 'id' => 999],
            ],
            'get_kanban stage desconhecido' => ['whmcs_crm_get_kanban', ['stage' => 'New']],
            'get_kanban limit legado' => ['whmcs_crm_get_kanban', ['limit' => 10]],
            'get_kanban offset legado' => ['whmcs_crm_get_kanban', ['offset' => 25]],
            'get_kanban lanes_truncated' => [
                'whmcs_crm_get_kanban', ['status_limit' => 25, 'lanes_truncated' => false],
            ],
        ];
    }

    /**
     * Somente ausência/`null` é "sem filtro": `0` e negativos são recusados
     * pelo schema, em TODOS os filtros de id, e nunca ampliam a leitura.
     *
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('nonPositiveIdProvider')]
    public function test_zero_and_negative_ids_are_refused(string $tool, array $arguments): void
    {
        $outcome = $this->call($tool, $arguments);

        $this->assertArrayHasKey('jsonrpc_error', $outcome, json_encode($outcome));
        $this->assertSame(-32602, $outcome['jsonrpc_error']['code'] ?? null);
    }

    /** @return array<string, array{0:string, 1:array<string, mixed>}> */
    public static function nonPositiveIdProvider(): array
    {
        return [
            'list_contacts type_id 0' => ['whmcs_crm_list_contacts', ['type_id' => 0]],
            'list_contacts type_id negativo' => ['whmcs_crm_list_contacts', ['type_id' => -1]],
            'list_contacts status_id 0' => ['whmcs_crm_list_contacts', ['status_id' => 0]],
            'get_contact resource_id 0' => ['whmcs_crm_get_contact', ['resource_id' => 0]],
            'get_contact resource_id negativo' => ['whmcs_crm_get_contact', ['resource_id' => -5]],
            'list_followups resource_id 0' => ['whmcs_crm_list_followups', ['resource_id' => 0]],
            'list_followups type_id 0' => [
                'whmcs_crm_list_followups', ['resource_id' => 42, 'type_id' => 0],
            ],
            'list_followups status_id negativo' => [
                'whmcs_crm_list_followups', ['resource_id' => 42, 'status_id' => -2],
            ],
            'get_kanban type_id 0' => ['whmcs_crm_get_kanban', ['type_id' => 0]],
            'get_kanban limit_per_status 0' => ['whmcs_crm_get_kanban', ['limit_per_status' => 0]],
            'get_kanban status_limit 0' => ['whmcs_crm_get_kanban', ['status_limit' => 0]],
            'get_kanban status_limit negativo' => ['whmcs_crm_get_kanban', ['status_limit' => -1]],
            'get_kanban status_offset negativo' => ['whmcs_crm_get_kanban', ['status_offset' => -1]],
        ];
    }

    /** `null` explícito continua significando "sem filtro". */
    public function test_explicit_null_is_still_no_filter(): void
    {
        $payload = $this->payload('whmcs_crm_list_contacts', ['type_id' => null, 'status_id' => null]);

        $this->assertSame(2, $payload['count']);
    }

    /** O endurecimento é publicado no schema, e restrito às quatro READ. */
    public function test_only_the_four_reads_are_closed(): void
    {
        foreach (\NtMcp\Mcp\CrmReadBoundary::READ_TOOLS as $tool) {
            $this->assertFalse(
                $this->publishedSchema($tool)['additionalProperties'] ?? null,
                "{$tool} deveria recusar propriedades desconhecidas"
            );
        }

        foreach ([
            'whmcs_crm_create_lead', 'whmcs_crm_update_contact',
            'whmcs_crm_add_followup', 'whmcs_crm_add_note',
            'whmcs_list_clients', 'whmcs_get_invoice',
        ] as $untouched) {
            $this->assertArrayNotHasKey(
                'additionalProperties',
                $this->publishedSchema($untouched),
                "{$untouched} não pode ter sido fechada por CRM-2.1"
            );
        }
    }

    /** Os limites positivos aparecem no schema publicado dos filtros de id. */
    public function test_published_schemas_bound_the_id_filters(): void
    {
        $this->assertSame(
            1,
            $this->publishedSchema('whmcs_crm_get_contact')['properties']['resource_id']['minimum'] ?? null
        );
        $this->assertSame(
            1,
            $this->publishedSchema('whmcs_crm_list_contacts')['properties']['type_id']['minimum'] ?? null
        );
        $this->assertSame(
            ['integer', 'null'],
            $this->publishedSchema('whmcs_crm_list_contacts')['properties']['type_id']['type'] ?? null,
            'null continua permitido — é o único "sem filtro"'
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private function publishedSchema(string $tool): array
    {
        $messages = $this->adapter()->handle(
            (string) json_encode([
                'jsonrpc' => '2.0',
                'id' => 7,
                'method' => 'tools/list',
                'params' => new \stdClass(),
            ]),
            'crm-schema-01',
            'tools/list'
        );

        foreach ($messages as $message) {
            foreach (($message['result']['tools'] ?? []) as $published) {
                if (($published['name'] ?? null) === $tool) {
                    return $published['inputSchema'];
                }
            }
        }

        $this->fail("tool não publicada: {$tool}");
    }
}
