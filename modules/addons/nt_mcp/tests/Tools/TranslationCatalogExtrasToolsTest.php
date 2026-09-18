<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\TranslationCatalogExtrasTools;
use NtMcp\Translation\DynamicTranslationRepository;
use NtMcp\Translation\TranslationBackup;
use NtMcp\Translation\TranslationGuard;
use NtMcp\Translation\TranslationSchemaGuard;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use NtMcp\Whmcs\AuthorizationException;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranslationCatalogExtrasToolsTest extends TestCase
{
    private const DYNAMIC_COLUMNS = ['id', 'related_type', 'related_id', 'language', 'translation', 'input_type'];

    private const CUSTOM_FIELD_COLUMNS = ['id', 'type', 'relid', 'fieldname', 'description', 'adminonly'];

    private const PRODUCT_ADDON_COLUMNS = ['id', 'name', 'description', 'hidden'];

    private const TICKET_DEPARTMENT_COLUMNS = ['id', 'name', 'description', 'hidden'];

    protected function setUp(): void
    {
        FakeCapsule::reset();
    }

    protected function tearDown(): void
    {
        FakeCapsule::reset();
    }

    private function healthyProbe(): FakeCrmSchemaProbe
    {
        return new FakeCrmSchemaProbe([
            'tbldynamic_translations' => self::DYNAMIC_COLUMNS,
            'tblcustomfields' => self::CUSTOM_FIELD_COLUMNS,
            'tbladdons' => self::PRODUCT_ADDON_COLUMNS,
            'tblticketdepartments' => self::TICKET_DEPARTMENT_COLUMNS,
        ]);
    }

    private function seedCustomFields(): void
    {
        FakeCapsule::withRows('tblcustomfields', [
            ['id' => 1, 'type' => 'product', 'relid' => 5, 'fieldname' => 'CPF', 'description' => 'Documento', 'adminonly' => ''],
        ]);
    }

    private function seedProductAddons(): void
    {
        FakeCapsule::withRows('tbladdons', [
            ['id' => 1, 'name' => 'IP Fixo', 'description' => '<p>Endereco IP dedicado</p>', 'hidden' => '0'],
        ]);
    }

    private function seedTicketDepartments(): void
    {
        FakeCapsule::withRows('tblticketdepartments', [
            ['id' => 1, 'name' => 'Suporte Tecnico', 'description' => 'Duvidas tecnicas', 'hidden' => '0'],
        ]);
    }

    private function tools(array $gates = ['write' => true, 'readonly' => false]): TranslationCatalogExtrasTools
    {
        $probe = $this->healthyProbe();

        return new TranslationCatalogExtrasTools(
            new DynamicTranslationRepository(new TranslationSchemaGuard($probe), null, $probe),
            new TranslationGuard($gates),
            new TranslationBackup(sys_get_temp_dir() . '/nt_mcp_catalog_extras_tools_test_' . uniqid('', true))
        );
    }

    private function payload(string|CallToolResult $result): array
    {
        $json = $result instanceof CallToolResult ? $result->content[0]->text : $result;

        return json_decode($json, true);
    }

    // -----------------------------------------------------------
    // custom_field_list / custom_field_set
    // -----------------------------------------------------------

    #[Test]
    public function custom_field_list_returns_success(): void
    {
        $this->seedCustomFields();
        $tools = $this->tools();

        $payload = $this->payload($tools->customFieldList());

        $this->assertSame('success', $payload['result']);
        $this->assertSame([1], array_column($payload['items'], 'id'));
    }

    #[Test]
    public function custom_field_list_filters_by_type(): void
    {
        FakeCapsule::withRows('tblcustomfields', [
            ['id' => 1, 'type' => 'product', 'relid' => 5, 'fieldname' => 'CPF', 'description' => 'Documento', 'adminonly' => ''],
            ['id' => 2, 'type' => 'dropdown', 'relid' => 5, 'fieldname' => 'Plano', 'description' => '', 'adminonly' => ''],
        ]);
        $tools = $this->tools();

        $payload = $this->payload($tools->customFieldList('dropdown'));

        $this->assertSame([2], array_column($payload['items'], 'id'));
    }

    #[Test]
    public function custom_field_set_without_confirm_is_a_dry_run_and_skips_the_gate(): void
    {
        $this->seedCustomFields();
        $tools = $this->tools(['write' => false, 'readonly' => true]);

        $payload = $this->payload($tools->customFieldSet(
            [['id' => 1, 'field' => 'name', 'text' => 'SSN', 'expected_hash' => 'absent']],
            false
        ));

        $this->assertSame('success', $payload['result']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function custom_field_set_with_confirm_requires_the_write_gate(): void
    {
        $this->seedCustomFields();
        $tools = $this->tools(['write' => false, 'readonly' => false]);

        $this->expectException(AuthorizationException::class);
        $tools->customFieldSet(
            [['id' => 1, 'field' => 'name', 'text' => 'SSN', 'expected_hash' => 'absent']],
            true
        );
    }

    #[Test]
    public function custom_field_set_with_confirm_writes_the_whole_batch(): void
    {
        $this->seedCustomFields();
        $tools = $this->tools(['write' => true, 'readonly' => false]);

        $payload = $this->payload($tools->customFieldSet(
            [['id' => 1, 'field' => 'name', 'text' => 'SSN', 'expected_hash' => 'absent']],
            true
        ));

        $this->assertSame('success', $payload['result']);
        $this->assertFalse($payload['dry_run']);
        $insertCount = count(array_filter(FakeCapsule::$mutations, static fn($m) => $m['verb'] === 'INSERT'));
        $this->assertSame(1, $insertCount);
    }

    #[Test]
    public function custom_field_set_rejects_more_than_twenty_items(): void
    {
        $this->seedCustomFields();
        $tools = $this->tools();

        $items = array_fill(0, 21, ['id' => 1, 'field' => 'name', 'text' => 'x', 'expected_hash' => 'absent']);
        $result = $tools->customFieldSet($items, false);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertTrue($result->isError);
        $payload = $this->payload($result);
        $this->assertSame('invalid_items', $payload['error_code']);
    }

    // -----------------------------------------------------------
    // product_addon_list / product_addon_set
    // -----------------------------------------------------------

    #[Test]
    public function product_addon_list_returns_success(): void
    {
        $this->seedProductAddons();
        $tools = $this->tools();

        $payload = $this->payload($tools->productAddonList());

        $this->assertSame('success', $payload['result']);
        $this->assertSame([1], array_column($payload['items'], 'id'));
    }

    #[Test]
    public function product_addon_set_with_confirm_writes_the_batch(): void
    {
        $this->seedProductAddons();
        $tools = $this->tools(['write' => true, 'readonly' => false]);

        $payload = $this->payload($tools->productAddonSet(
            [['id' => 1, 'field' => 'description', 'text' => '<p>Dedicated IP address</p>', 'expected_hash' => 'absent']],
            true
        ));

        $this->assertSame('success', $payload['result']);
        $insertCount = count(array_filter(FakeCapsule::$mutations, static fn($m) => $m['verb'] === 'INSERT'));
        $this->assertSame(1, $insertCount);
    }

    // -----------------------------------------------------------
    // department_list / department_set
    // -----------------------------------------------------------

    #[Test]
    public function department_list_returns_success(): void
    {
        $this->seedTicketDepartments();
        $tools = $this->tools();

        $payload = $this->payload($tools->departmentList());

        $this->assertSame('success', $payload['result']);
        $this->assertSame([1], array_column($payload['items'], 'id'));
    }

    #[Test]
    public function department_set_without_confirm_never_touches_the_gate_even_when_readonly(): void
    {
        $this->seedTicketDepartments();
        $tools = $this->tools(['write' => false, 'readonly' => true]);

        $payload = $this->payload($tools->departmentSet(
            [['id' => 1, 'field' => 'name', 'text' => 'Technical Support', 'expected_hash' => 'absent']],
            false
        ));

        $this->assertSame('success', $payload['result']);
        $this->assertTrue($payload['dry_run']);
    }

    #[Test]
    public function department_set_reports_hash_conflict_as_tool_error(): void
    {
        $this->seedTicketDepartments();
        $tools = $this->tools();

        $result = $tools->departmentSet(
            [['id' => 1, 'field' => 'name', 'text' => 'x', 'expected_hash' => 'wrong']],
            false
        );

        $this->assertInstanceOf(CallToolResult::class, $result);
        $payload = $this->payload($result);
        $this->assertSame('hash_conflict', $payload['error_code']);
    }
}
