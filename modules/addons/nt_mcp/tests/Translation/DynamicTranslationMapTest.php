<?php

declare(strict_types=1);

namespace NtMcp\Tests\Translation;

use NtMcp\Translation\DynamicTranslationMap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DynamicTranslationMapTest extends TestCase
{
    #[Test]
    public function kinds_lists_all_five_kinds(): void
    {
        $this->assertSame(
            ['product', 'product_group', 'custom_field', 'product_addon', 'ticket_department'],
            DynamicTranslationMap::kinds()
        );
    }

    #[Test]
    public function product_fields_are_name_description_tagline_and_short_description(): void
    {
        $this->assertSame(
            ['name' => 'text', 'description' => 'textarea', 'tagline' => 'text', 'short_description' => 'text'],
            DynamicTranslationMap::fields('product')
        );
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
    public function custom_field_fields_are_name_and_description(): void
    {
        $this->assertSame(['name' => 'text', 'description' => 'text'], DynamicTranslationMap::fields('custom_field'));
    }

    #[Test]
    public function product_addon_fields_are_name_and_description(): void
    {
        $this->assertSame(['name' => 'text', 'description' => 'textarea'], DynamicTranslationMap::fields('product_addon'));
    }

    #[Test]
    public function ticket_department_fields_are_name_and_description(): void
    {
        $this->assertSame(['name' => 'text', 'description' => 'text'], DynamicTranslationMap::fields('ticket_department'));
    }

    #[Test]
    public function source_table_is_closed_per_kind(): void
    {
        $this->assertSame('tblproducts', DynamicTranslationMap::sourceTable('product'));
        $this->assertSame('tblproductgroups', DynamicTranslationMap::sourceTable('product_group'));
        $this->assertSame('tblcustomfields', DynamicTranslationMap::sourceTable('custom_field'));
        $this->assertSame('tbladdons', DynamicTranslationMap::sourceTable('product_addon'));
        $this->assertSame('tblticketdepartments', DynamicTranslationMap::sourceTable('ticket_department'));
    }

    #[Test]
    public function custom_field_name_reads_the_fieldname_column_but_description_reads_itself(): void
    {
        $this->assertSame('fieldname', DynamicTranslationMap::sourceColumn('custom_field', 'name'));
        $this->assertSame('description', DynamicTranslationMap::sourceColumn('custom_field', 'description'));
    }

    #[Test]
    public function source_column_defaults_to_the_field_name_for_kinds_without_a_mapping_override(): void
    {
        $this->assertSame('name', DynamicTranslationMap::sourceColumn('product', 'name'));
        $this->assertSame('name', DynamicTranslationMap::sourceColumn('product_addon', 'name'));
        $this->assertSame('name', DynamicTranslationMap::sourceColumn('ticket_department', 'name'));
    }

    #[Test]
    public function capability_for_isolates_phase_3_kinds_from_each_other_and_from_phase_2(): void
    {
        $this->assertSame('dynamic_translations', DynamicTranslationMap::capabilityFor('product'));
        $this->assertSame('dynamic_translations', DynamicTranslationMap::capabilityFor('product_group'));
        $this->assertSame('dynamic_translations_custom_field', DynamicTranslationMap::capabilityFor('custom_field'));
        $this->assertSame('dynamic_translations_product_addon', DynamicTranslationMap::capabilityFor('product_addon'));
        $this->assertSame('dynamic_translations_ticket_department', DynamicTranslationMap::capabilityFor('ticket_department'));
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
        $this->assertSame('product.{id}.tagline', DynamicTranslationMap::relatedType('product', 'tagline'));
        $this->assertSame('product.{id}.short_description', DynamicTranslationMap::relatedType('product', 'short_description'));
        $this->assertSame('product_group.{id}.headline', DynamicTranslationMap::relatedType('product_group', 'headline'));

        // Mesmo literal para IDs diferentes — a distinção fica em related_id, coluna separada.
        $this->assertSame(
            DynamicTranslationMap::relatedType('product', 'name'),
            DynamicTranslationMap::relatedType('product', 'name')
        );
    }

    #[Test]
    public function related_type_for_phase_3_kinds_uses_the_public_field_name_not_the_source_column(): void
    {
        // custom_field.name le a coluna 'fieldname', mas o literal usa o nome PUBLICO 'name'.
        $this->assertSame('custom_field.{id}.name', DynamicTranslationMap::relatedType('custom_field', 'name'));
        $this->assertSame('custom_field.{id}.description', DynamicTranslationMap::relatedType('custom_field', 'description'));
        $this->assertSame('product_addon.{id}.name', DynamicTranslationMap::relatedType('product_addon', 'name'));
        $this->assertSame('ticket_department.{id}.description', DynamicTranslationMap::relatedType('ticket_department', 'description'));
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
