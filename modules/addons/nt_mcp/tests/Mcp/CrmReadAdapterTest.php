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
            ['type_id', 'limit_per_status', 'catalogs', 'lanes', 'lanes_truncated'],
            array_keys($payload)
        );
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
        $this->assertFalse($payload['lanes_truncated']);
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
        $this->assertSame(['type_id', 'limit_per_status'], array_keys($kanban['properties']));
        $this->assertArrayNotHasKey('required', $kanban, 'kanban não exige nada do chamador');
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
            'id não positivo' => [
                'whmcs_crm_get_contact', ['resource_id' => 0], 'validation', null,
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
