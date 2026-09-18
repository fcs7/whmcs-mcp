<?php

declare(strict_types=1);

namespace NtMcp\Tests\Translation;

use NtMcp\Translation\TranslationStatusReader;
use NtMcp\Tests\Support\FakeCapsule;
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
