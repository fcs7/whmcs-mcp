<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use Mcp\Capability\Attribute\McpTool;
use NtMcp\Crm\MgCrmRepository;
use NtMcp\Tools\CrmTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Trava a superfície CRM pública no release read-only de quatro tools. */
class CrmToolsSurfaceTest extends TestCase
{
    private const READ_METHODS = ['listContacts', 'getContact', 'listFollowups', 'getKanban'];

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

    #[DataProvider('readMethodProvider')]
    public function test_reads_go_through_the_repository_only(string $method): void
    {
        $body = self::bodyOf($method);

        foreach (['mod_mgcrm_', 'CapsuleClient', 'Capsule::', 'ensureCrmAvailable', "['*']"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "{$method}() usa a rota legada");
        }
        $this->assertStringContainsString('$this->crm->', $body, "{$method}() deveria usar o repositório");
    }

    /** @return array<string, array{0:string}> */
    public static function readMethodProvider(): array
    {
        return array_combine(
            self::READ_METHODS,
            array_map(static fn(string $method): array => [$method], self::READ_METHODS)
        );
    }

    public function test_only_the_four_read_names_are_discovered(): void
    {
        $names = [];
        foreach ((new \ReflectionClass(CrmTools::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(McpTool::class) as $attribute) {
                $names[] = $attribute->newInstance()->name;
            }
        }
        sort($names);

        $this->assertSame([
            'whmcs_crm_get_contact',
            'whmcs_crm_get_kanban',
            'whmcs_crm_list_contacts',
            'whmcs_crm_list_followups',
        ], $names);
    }

    public function test_legacy_write_methods_are_absent(): void
    {
        foreach (['createLead', 'updateContact', 'addFollowup', 'addNote'] as $method) {
            $this->assertFalse(method_exists(CrmTools::class, $method), "{$method} ainda está executável");
        }
    }

    public function test_constructor_receives_only_the_real_repository(): void
    {
        $parameters = (new \ReflectionClass(CrmTools::class))->getConstructor()?->getParameters() ?? [];

        $this->assertSame(
            [MgCrmRepository::class],
            array_map(static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType(), $parameters)
        );
    }

    public function test_all_crm_descriptions_declare_mgcrm_requirement(): void
    {
        $descriptions = [];
        foreach ((new \ReflectionClass(CrmTools::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(McpTool::class) as $attribute) {
                $descriptions[] = $attribute->newInstance()->description;
            }
        }

        $this->assertCount(4, $descriptions);
        foreach ($descriptions as $description) {
            $this->assertStringStartsWith('Requer ModulesGarden CRM (mgCRM).', $description);
        }
    }
}
