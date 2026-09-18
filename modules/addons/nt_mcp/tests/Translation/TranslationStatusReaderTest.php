<?php

declare(strict_types=1);

namespace NtMcp\Tests\Translation;

use NtMcp\Translation\TranslationStatusReader;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranslationStatusReaderTest extends TestCase
{
    protected function setUp(): void
    {
        FakeCapsule::reset();
    }

    protected function tearDown(): void
    {
        FakeCapsule::reset();
    }

    #[Test]
    public function client_language_counts_groups_and_sorts_by_language(): void
    {
        FakeCapsule::withRows('tblclients', [
            ['language' => 'english'],
            ['language' => ''],
            ['language' => 'english'],
        ]);

        $reader = new TranslationStatusReader(static fn(): string => 'unknown');

        $this->assertSame(['' => 1, 'english' => 2], $reader->clientLanguageCounts());
    }

    #[Test]
    public function dynamic_translations_enabled_delegates_to_injected_probe(): void
    {
        $reader = new TranslationStatusReader(static fn(): string => 'enabled');

        $this->assertSame('enabled', $reader->dynamicTranslationsEnabled());
    }

    #[Test]
    public function dynamic_translation_counts_is_empty_when_table_is_absent(): void
    {
        $reader = new TranslationStatusReader(static fn(): string => 'unknown', new FakeCrmSchemaProbe([]));

        $this->assertSame(['by_language' => [], 'by_related_type' => []], $reader->dynamicTranslationCounts());
    }

    #[Test]
    public function dynamic_translation_counts_groups_by_language_and_related_type(): void
    {
        FakeCapsule::withRows('tbldynamic_translations', [
            ['language' => 'english', 'related_type' => 'product.{id}.name'],
            ['language' => 'english', 'related_type' => 'product.{id}.name'],
            ['language' => 'english', 'related_type' => 'product.{id}.description'],
            ['language' => 'portuguese-br', 'related_type' => 'product_group.{id}.name'],
        ]);
        $probe = new FakeCrmSchemaProbe(['tbldynamic_translations' => ['language', 'related_type']]);
        $reader = new TranslationStatusReader(static fn(): string => 'unknown', $probe);

        $counts = $reader->dynamicTranslationCounts();

        $this->assertSame(['english' => 3, 'portuguese-br' => 1], $counts['by_language']);
        $this->assertSame([
            'product.{id}.description' => 1,
            'product.{id}.name' => 2,
            'product_group.{id}.name' => 1,
        ], $counts['by_related_type']);
    }

    // -----------------------------------------------------------
    // contentVariantCounts() (Fase 4)
    // -----------------------------------------------------------

    #[Test]
    public function content_variant_counts_reports_unavailable_per_missing_table_without_derailing_others(): void
    {
        FakeCapsule::withRows('tblannouncements', [
            ['language' => ''],
            ['language' => 'english'],
        ]);
        $probe = new FakeCrmSchemaProbe(['tblannouncements' => ['language']]);
        $reader = new TranslationStatusReader(static fn(): string => 'unknown', $probe);

        $counts = $reader->contentVariantCounts();

        $this->assertSame(['' => 1, 'english' => 1], $counts['announcements']);
        $this->assertSame('unavailable', $counts['kb_articles']);
        $this->assertSame('unavailable', $counts['kb_categories']);
    }

    #[Test]
    public function content_variant_counts_groups_each_table_independently(): void
    {
        FakeCapsule::withRows('tblannouncements', [['language' => '']]);
        FakeCapsule::withRows('tblknowledgebase', [['language' => ''], ['language' => 'english'], ['language' => 'english']]);
        FakeCapsule::withRows('tblknowledgebasecats', [['language' => 'english']]);
        $probe = new FakeCrmSchemaProbe([
            'tblannouncements' => ['language'],
            'tblknowledgebase' => ['language'],
            'tblknowledgebasecats' => ['language'],
        ]);
        $reader = new TranslationStatusReader(static fn(): string => 'unknown', $probe);

        $counts = $reader->contentVariantCounts();

        $this->assertSame(['' => 1], $counts['announcements']);
        $this->assertSame(['' => 1, 'english' => 2], $counts['kb_articles']);
        $this->assertSame(['english' => 1], $counts['kb_categories']);
    }

    // -----------------------------------------------------------
    // normalizeFlag() — usado pelo probe padrão (tblconfiguration real, onde
    // o WHMCS guarda checkbox como 'on', não '1').
    // -----------------------------------------------------------

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function flagCases(): array
    {
        return [
            'on' => ['on', 'enabled'],
            'On uppercase' => ['On', 'enabled'],
            'ON with spaces' => [' ON ', 'enabled'],
            'one' => ['1', 'enabled'],
            'true string' => ['true', 'enabled'],
            'yes string' => ['yes', 'enabled'],
            'bool true' => [true, 'enabled'],
            'empty string' => ['', 'disabled'],
            'zero' => ['0', 'disabled'],
            'off' => ['off', 'disabled'],
            'false string' => ['false', 'disabled'],
            'no string' => ['no', 'disabled'],
            'bool false' => [false, 'disabled'],
            'null' => [null, 'unknown'],
            'garbage' => ['maybe', 'unknown'],
            'numeric other' => ['2', 'unknown'],
        ];
    }

    #[Test]
    #[DataProvider('flagCases')]
    public function normalize_flag_classifies_known_and_unknown_values(mixed $raw, string $expected): void
    {
        $this->assertSame($expected, TranslationStatusReader::normalizeFlag($raw));
    }
}
