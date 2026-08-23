<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Crm\MgCrmRepository;
use NtMcp\Tools\CrmTools;
use NtMcp\Whmcs\CapsuleClient;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A FRONTEIRA entre as leituras migradas e as escritas legadas, verificada
 * mecanicamente no código-fonte.
 *
 * CRM-2 deixa `CrmTools` deliberadamente híbrida: quatro métodos já falam com
 * `crm_*` pelo repositório, quatro ainda falam com as tabelas fictícias
 * `mod_mgcrm_*` pelo `CapsuleClient`. Isso só é aceitável enquanto for
 * VERIFICÁVEL que nenhuma leitura escorregou para o lado legado — ler o diff
 * não é verificação, então a separação é asserida por reflexão método a método.
 *
 * Quando CRM-3 migrar as escritas e CRM-4 remover o legado, os testes do lado
 * "legado" abaixo passam a falhar de propósito: eles fixam o estado ATUAL, e o
 * seu vermelho é o lembrete de que o ticket seguinte terminou.
 */
class CrmToolsSurfaceTest extends TestCase
{
    private const READ_METHODS = ['listContacts', 'getContact', 'listFollowups', 'getKanban'];
    private const WRITE_METHODS = ['createLead', 'updateContact', 'addFollowup', 'addNote'];

    /** Código-fonte exato de um método, sem o resto do arquivo. */
    private static function bodyOf(string $method): string
    {
        $reflection = new \ReflectionMethod(CrmTools::class, $method);
        $lines = file((string) $reflection->getFileName()) ?: [];

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    // ---------------------------------------------------------------
    // As quatro leituras estão limpas
    // ---------------------------------------------------------------

    /** Nenhuma leitura pode citar as tabelas fictícias, nem por constante. */
    #[DataProvider('readMethodProvider')]
    public function test_reads_never_reference_the_legacy_schema(string $method): void
    {
        $body = self::bodyOf($method);

        foreach (['mod_mgcrm_', 'TABLE_CONTACTS', 'TABLE_FOLLOWUPS', 'TABLE_NOTES'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "{$method}() cita o schema legado");
        }
    }

    /** Nenhuma leitura pode projetar coringa. */
    #[DataProvider('readMethodProvider')]
    public function test_reads_never_use_a_wildcard_projection(string $method): void
    {
        $body = self::bodyOf($method);

        foreach (["['*']", "'*'", '"*"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "{$method}() projeta coringa");
        }
    }

    /**
     * Nenhuma leitura pode passar pelo cliente genérico nem pelo probe legado:
     * as duas coisas contornariam as barreiras do repositório.
     */
    #[DataProvider('readMethodProvider')]
    public function test_reads_go_through_the_repository_only(string $method): void
    {
        $body = self::bodyOf($method);

        $this->assertStringNotContainsString('$this->capsule', $body, "{$method}() usa o CapsuleClient");
        $this->assertStringNotContainsString('ensureCrmAvailable', $body, "{$method}() usa o probe legado");
        $this->assertStringNotContainsString('Capsule::', $body, "{$method}() fala com o driver");
        $this->assertStringContainsString('$this->crm->', $body, "{$method}() deveria usar o repositório");
    }

    /** @return array<string, array{0:string}> */
    public static function readMethodProvider(): array
    {
        return array_combine(
            self::READ_METHODS,
            array_map(static fn(string $m): array => [$m], self::READ_METHODS)
        );
    }

    // ---------------------------------------------------------------
    // As quatro escritas seguem intocadas
    // ---------------------------------------------------------------

    /**
     * O lado legado continua legado. Se uma escrita começar a usar o
     * repositório aqui, CRM-3 vazou para dentro de CRM-2.
     */
    #[DataProvider('writeMethodProvider')]
    public function test_writes_still_use_the_legacy_route(string $method): void
    {
        $body = self::bodyOf($method);

        $this->assertStringContainsString('$this->capsule', $body, "{$method}() já não é legado");
        $this->assertStringContainsString('ensureCrmAvailable', $body, "{$method}() perdeu o probe legado");
        $this->assertStringNotContainsString('$this->crm->', $body, "{$method}() foi migrado cedo demais");
    }

    /** @return array<string, array{0:string}> */
    public static function writeMethodProvider(): array
    {
        return array_combine(
            self::WRITE_METHODS,
            array_map(static fn(string $m): array => [$m], self::WRITE_METHODS)
        );
    }

    /** As assinaturas públicas das escritas não mudaram nesta tranche. */
    public function test_write_signatures_are_unchanged(): void
    {
        $expected = [
            'createLead' => ['name', 'email', 'phone', 'company', 'notes'],
            'updateContact' => ['id', 'name', 'email', 'phone', 'company', 'notes', 'status', 'stage'],
            'addFollowup' => ['contactId', 'note', 'duedate'],
            'addNote' => ['contactId', 'note'],
        ];

        foreach ($expected as $method => $parameters) {
            $this->assertSame(
                $parameters,
                array_map(
                    static fn(\ReflectionParameter $p): string => $p->getName(),
                    (new \ReflectionMethod(CrmTools::class, $method))->getParameters()
                ),
                "{$method}() mudou de assinatura"
            );
        }
    }

    // ---------------------------------------------------------------
    // A superfície como um todo
    // ---------------------------------------------------------------

    /** Continuam OITO tools, com os mesmos nomes públicos. */
    public function test_the_eight_public_names_are_preserved(): void
    {
        $names = [];

        foreach ((new \ReflectionClass(CrmTools::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(McpTool::class) as $attribute) {
                $names[] = $attribute->newInstance()->name;
            }
        }

        sort($names);

        $this->assertSame([
            'whmcs_crm_add_followup',
            'whmcs_crm_add_note',
            'whmcs_crm_create_lead',
            'whmcs_crm_get_contact',
            'whmcs_crm_get_kanban',
            'whmcs_crm_list_contacts',
            'whmcs_crm_list_followups',
            'whmcs_crm_update_contact',
        ], $names, 'CRM-2 não pode criar, remover nem renomear tool');
    }

    /**
     * A classe recebe as DUAS rotas — e nada além. Um colaborador extra aqui
     * seria uma dependência de produção entrando sem revisão.
     */
    public function test_the_tool_class_receives_exactly_the_two_routes(): void
    {
        $parameters = (new \ReflectionClass(CrmTools::class))->getConstructor()?->getParameters() ?? [];

        $this->assertSame(
            [CapsuleClient::class, MgCrmRepository::class],
            array_map(
                static fn(\ReflectionParameter $p): string => (string) $p->getType(),
                $parameters
            )
        );
    }

    /**
     * Nenhuma leitura pode acionar o gate de escrita nem resolver autoria: o
     * arquivo inteiro não conhece `CrmWriteGate`, e nenhum corpo de leitura
     * menciona autoria.
     *
     * A citação de `admin_id` que sobrevive no arquivo é o COMENTÁRIO de dívida
     * dentro de `addFollowup()`, herdado da base e preservado byte a byte até
     * CRM-3 — por isso a asserção é por método, não pelo arquivo.
     */
    public function test_reads_cannot_reach_the_write_gate_or_authorship(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(CrmTools::class))->getFileName());

        $this->assertStringNotContainsString('CrmWriteGate', $source);
        $this->assertStringNotContainsString('assertWritable', $source);
        $this->assertStringNotContainsString('resolveAuthorAdminId', $source);

        foreach (self::READ_METHODS as $method) {
            $body = self::bodyOf($method);

            $this->assertStringNotContainsString('admin_id', $body, "{$method}() menciona autoria");
            $this->assertStringNotContainsString('adminId', $body, "{$method}() menciona autoria");
        }
    }

    // ---------------------------------------------------------------
    // #23: All CRM tool descriptions declare mgCRM dependency
    // ---------------------------------------------------------------

    /** #23 — todas as 8 tools de CRM começam com "Requer ModulesGarden CRM". */
    public function test_all_crm_tool_descriptions_declare_mgcrm_requirement(): void
    {
        $reflection = new \ReflectionClass(CrmTools::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $crmMethods = [];
        foreach ($methods as $method) {
            $attrs = $method->getAttributes(McpTool::class);
            if (!$attrs) continue;

            $attr = $attrs[0]->newInstance();
            $crmMethods[$method->getName()] = $attr->description;
        }

        // Devem haver exatamente 8 tools de CRM
        $this->assertCount(8, $crmMethods, 'CrmTools deve ter 8 tools');

        // Todas devem começar com a declaração de dependência
        foreach ($crmMethods as $method => $description) {
            $this->assertStringStartsWith(
                'Requer ModulesGarden CRM (mgCRM).',
                $description,
                "{$method}() description não começa com 'Requer ModulesGarden CRM (mgCRM).'"
            );
        }
    }
}
