<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\TranslationCatalogTools;
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

final class TranslationCatalogToolsTest extends TestCase
{
    private const DYNAMIC_COLUMNS = ['id', 'related_type', 'related_id', 'language', 'translation', 'input_type'];

    private const PRODUCT_COLUMNS = ['id', 'gid', 'name', 'description', 'hidden', 'retired'];

    private const PRODUCT_GROUP_COLUMNS = ['id', 'name', 'headline', 'tagline', 'hidden'];

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
            'tblproducts' => self::PRODUCT_COLUMNS,
            'tblproductgroups' => self::PRODUCT_GROUP_COLUMNS,
        ]);
    }

    private function seedProducts(): void
    {
        FakeCapsule::withRows('tblproducts', [
            ['id' => 1, 'gid' => 5, 'name' => 'Fibra 500', 'description' => '<p>Descricao 500</p>', 'hidden' => '0', 'retired' => '0'],
        ]);
    }

    private function seedProductGroups(): void
    {
        FakeCapsule::withRows('tblproductgroups', [
            ['id' => 5, 'name' => 'Internet', 'headline' => 'Internet rapida', 'tagline' => 'Sempre conectado', 'hidden' => '0'],
        ]);
    }

    private function tools(array $gates = ['write' => true, 'readonly' => false]): TranslationCatalogTools
    {
        $probe = $this->healthyProbe();

        return new TranslationCatalogTools(
            new DynamicTranslationRepository(new TranslationSchemaGuard($probe), null, $probe),
            new TranslationGuard($gates),
            new TranslationBackup(sys_get_temp_dir() . '/nt_mcp_catalog_tools_test_' . uniqid('', true))
        );
    }

    private function payload(string|CallToolResult $result): array
    {
        $json = $result instanceof CallToolResult ? $result->content[0]->text : $result;

        return json_decode($json, true);
    }

    // -----------------------------------------------------------
    // product_list / product_get
    // -----------------------------------------------------------

    #[Test]
    public function product_list_returns_success(): void
    {
        $this->seedProducts();
        $tools = $this->tools();

        $payload = $this->payload($tools->productList());

        $this->assertSame('success', $payload['result']);
        $this->assertSame([1], array_column($payload['items'], 'id'));
    }

    #[Test]
    public function product_get_reports_absent_hash(): void
    {
        $this->seedProducts();
        $tools = $this->tools();

        $payload = $this->payload($tools->productGet([1]));

        $this->assertSame('absent', $payload['items'][0]['fields']['name']['target_hash']);
    }

    #[Test]
    public function product_group_list_returns_success(): void
    {
        $this->seedProductGroups();
        $tools = $this->tools();

        $payload = $this->payload($tools->productGroupList());

        $this->assertSame('success', $payload['result']);
        $this->assertSame([5], array_column($payload['items'], 'id'));
    }

    // -----------------------------------------------------------
    // product_set / product_group_set
    // -----------------------------------------------------------

    #[Test]
    public function product_set_without_confirm_is_a_dry_run_and_skips_the_gate(): void
    {
        $this->seedProducts();
        $tools = $this->tools(['write' => false, 'readonly' => true]);

        $payload = $this->payload($tools->productSet(
            [['id' => 1, 'field' => 'name', 'text' => 'Fiber 500', 'expected_hash' => 'absent']],
            false
        ));

        $this->assertSame('success', $payload['result']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function product_set_with_confirm_requires_the_write_gate(): void
    {
        $this->seedProducts();
        $tools = $this->tools(['write' => false, 'readonly' => false]);

        $this->expectException(AuthorizationException::class);
        $tools->productSet(
            [['id' => 1, 'field' => 'name', 'text' => 'Fiber 500', 'expected_hash' => 'absent']],
            true
        );
    }

    #[Test]
    public function product_set_with_confirm_writes_the_whole_batch(): void
    {
        $this->seedProducts();
        $tools = $this->tools(['write' => true, 'readonly' => false]);

        $payload = $this->payload($tools->productSet(
            [['id' => 1, 'field' => 'name', 'text' => 'Fiber 500', 'expected_hash' => 'absent']],
            true
        ));

        $this->assertSame('success', $payload['result']);
        $this->assertFalse($payload['dry_run']);
        $insertCount = count(array_filter(FakeCapsule::$mutations, static fn($m) => $m['verb'] === 'INSERT'));
        $this->assertSame(1, $insertCount);
    }

    #[Test]
    public function product_set_rejects_more_than_twenty_items(): void
    {
        $this->seedProducts();
        $tools = $this->tools();

        $items = array_fill(0, 21, ['id' => 1, 'field' => 'name', 'text' => 'x', 'expected_hash' => 'absent']);
        $result = $tools->productSet($items, false);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertTrue($result->isError);
        $payload = $this->payload($result);
        $this->assertSame('invalid_items', $payload['error_code']);
    }

    #[Test]
    public function product_set_reports_hash_conflict_as_tool_error(): void
    {
        $this->seedProducts();
        $tools = $this->tools();

        $result = $tools->productSet(
            [['id' => 1, 'field' => 'name', 'text' => 'x', 'expected_hash' => 'wrong']],
            false
        );

        $this->assertInstanceOf(CallToolResult::class, $result);
        $payload = $this->payload($result);
        $this->assertSame('hash_conflict', $payload['error_code']);
    }

    #[Test]
    public function product_group_set_with_confirm_writes_the_batch(): void
    {
        $this->seedProductGroups();
        $tools = $this->tools(['write' => true, 'readonly' => false]);

        $payload = $this->payload($tools->productGroupSet(
            [['id' => 5, 'field' => 'headline', 'text' => 'Fast internet', 'expected_hash' => 'absent']],
            true
        ));

        $this->assertSame('success', $payload['result']);
        $insertCount = count(array_filter(FakeCapsule::$mutations, static fn($m) => $m['verb'] === 'INSERT'));
        $this->assertSame(1, $insertCount);
    }

    #[Test]
    public function product_group_set_without_confirm_never_touches_the_gate_even_when_readonly(): void
    {
        $this->seedProductGroups();
        $tools = $this->tools(['write' => false, 'readonly' => true]);

        $payload = $this->payload($tools->productGroupSet(
            [['id' => 5, 'field' => 'headline', 'text' => 'Fast internet', 'expected_hash' => 'absent']],
            false
        ));

        $this->assertSame('success', $payload['result']);
        $this->assertTrue($payload['dry_run']);
    }
}
