<?php

declare(strict_types=1);

namespace NtMcp\Tests\Translation;

use NtMcp\Translation\DynamicTranslationMap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DynamicTranslationMapTest extends TestCase
{
    #[Test]
    public function kinds_lists_product_and_product_group(): void
    {
        $this->assertSame(['product', 'product_group'], DynamicTranslationMap::kinds());
    }

    #[Test]
    public function product_fields_are_name_and_description(): void
    {
        $this->assertSame(['name' => 'text', 'description' => 'textarea'], DynamicTranslationMap::fields('product'));
    }

    #[Test]
    public function product_group_fields_are_name_headline_and_tagline(): void
    {
        $this->assertSame(
            ['name' => 'text', 'headline' => 'text', 'tagline' => 'text'],
            DynamicTranslationMap::fields('product_group')
        );
    }

    #[Test]
    public function source_table_is_closed_per_kind(): void
    {
        $this->assertSame('tblproducts', DynamicTranslationMap::sourceTable('product'));
        $this->assertSame('tblproductgroups', DynamicTranslationMap::sourceTable('product_group'));
    }

    #[Test]
    public function invalid_kind_is_rejected(): void
    {
        $this->assertFalse(DynamicTranslationMap::isValidKind('department'));
        $this->assertFalse(DynamicTranslationMap::isValidField('product', 'gid'));
    }

    #[Test]
    public function related_type_is_the_literal_string_with_id_placeholder_not_the_numeric_id(): void
    {
        $this->assertSame('product.{id}.name', DynamicTranslationMap::relatedType('product', 'name'));
        $this->assertSame('product.{id}.description', DynamicTranslationMap::relatedType('product', 'description'));
        $this->assertSame('product_group.{id}.headline', DynamicTranslationMap::relatedType('product_group', 'headline'));

        // Mesmo literal para IDs diferentes — a distinção fica em related_id, coluna separada.
        $this->assertSame(
            DynamicTranslationMap::relatedType('product', 'name'),
            DynamicTranslationMap::relatedType('product', 'name')
        );
    }

    #[Test]
    public function source_table_throws_for_unknown_kind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DynamicTranslationMap::sourceTable('kb_article');
    }

    #[Test]
    public function input_type_throws_for_unknown_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DynamicTranslationMap::inputType('product', 'sku');
    }
}
