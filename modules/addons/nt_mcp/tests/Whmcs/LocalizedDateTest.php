<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use NtMcp\Tests\Support\WhmcsDateFormat;
use NtMcp\Whmcs\LocalizedDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F1 — conversão `Y-m-d` → data localizada efetiva, via helper nativo, com
 * fail-closed em qualquer dúvida.
 */
class LocalizedDateTest extends TestCase
{
    protected function setUp(): void
    {
        WhmcsDateFormat::reset();
    }

    protected function tearDown(): void
    {
        WhmcsDateFormat::reset();
    }

    /** O formato vem da configuração da instalação — nada hardcoded. */
    #[DataProvider('installationFormatProvider')]
    public function test_uses_the_configured_installation_format(string $phpFormat, string $expected): void
    {
        WhmcsDateFormat::$phpFormat = $phpFormat;

        $this->assertSame($expected, (new LocalizedDate())->fromWhmcsDate('2026-08-10', 'datecreated'));
    }

    public static function installationFormatProvider(): array
    {
        return [
            'DD/MM/YYYY' => ['d/m/Y', '10/08/2026'],
            'MM/DD/YYYY' => ['m/d/Y', '08/10/2026'],
            'YYYY-MM-DD' => ['Y-m-d', '2026-08-10'],
            'DD.MM.YYYY' => ['d.m.Y', '10.08.2026'],
            'DD-MM-YYYY' => ['d-m-Y', '10-08-2026'],
        ];
    }

    public function test_uses_injected_formatter_when_provided(): void
    {
        $localized = new LocalizedDate();
        $localized->setFormatter(static fn(string $ymd): string => '10|08|2026');
        $localized->setParser(null);

        $this->assertSame('10|08|2026', $localized->fromWhmcsDate('2026-08-10', 'datecreated'));
    }

    // ---------------------------------------------------------------
    // Fail-closed
    // ---------------------------------------------------------------

    #[DataProvider('failureModeProvider')]
    public function test_fails_closed_on_broken_helper(string $mode): void
    {
        WhmcsDateFormat::$mode = $mode;

        $this->expectException(\RuntimeException::class);

        (new LocalizedDate())->fromWhmcsDate('2026-08-10', 'datecreated');
    }

    public static function failureModeProvider(): array
    {
        return [
            'helper lança'        => [WhmcsDateFormat::MODE_THROW],
            'retorno vazio'       => [WhmcsDateFormat::MODE_EMPTY],
            'outra data'          => [WhmcsDateFormat::MODE_WRONG_DATE],
            'ano de 2 dígitos'    => [WhmcsDateFormat::MODE_TWO_DIGIT_YEAR],
        ];
    }

    public function test_fails_closed_when_helper_is_unavailable(): void
    {
        $localized = new LocalizedDate();
        $localized->setFormatter(static function (string $ymd): string {
            throw new \Error('Call to undefined function fromMySQLDate()');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("localisation of 'datecreated' failed");

        $localized->fromWhmcsDate('2026-08-10', 'datecreated');
    }

    public function test_fails_closed_when_input_is_not_ymd(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed date');

        (new LocalizedDate())->fromWhmcsDate('10/08/2026', 'datecreated');
    }

    /**
     * Numa instalação sem `toMySQLDate()` a verificação cai no exame por
     * dígitos — que aceita a data certa e continua reprovando a errada.
     */
    public function test_digit_verification_is_used_when_no_inverse_helper_exists(): void
    {
        $ok = new LocalizedDate();
        $ok->setParser(null);
        $this->assertSame('10/08/2026', $ok->fromWhmcsDate('2026-08-10', 'datecreated'));

        WhmcsDateFormat::$mode = WhmcsDateFormat::MODE_WRONG_DATE;
        $bad = new LocalizedDate();
        $bad->setParser(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not represent the same calendar date');
        $bad->fromWhmcsDate('2026-08-10', 'datecreated');
    }

    /** Verificador inverso quebrado também é fail-closed. */
    public function test_broken_inverse_helper_fails_closed(): void
    {
        WhmcsDateFormat::$parserAvailable = false; // toMySQLDate() lança

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not represent the same calendar date');

        (new LocalizedDate())->fromWhmcsDate('2026-08-10', 'datecreated');
    }

    public function test_error_message_names_the_field(): void
    {
        WhmcsDateFormat::$mode = WhmcsDateFormat::MODE_EMPTY;

        try {
            (new LocalizedDate())->fromWhmcsDate('2026-08-10', 'validuntil');
            $this->fail('deveria falhar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('validuntil', $e->getMessage());
        }
    }

    /** Espaço em volta do retorno é aparado, não tratado como falha. */
    public function test_trims_helper_output(): void
    {
        $localized = new LocalizedDate();
        $localized->setFormatter(static fn(string $ymd): string => "  10/08/2026\n");

        $this->assertSame('10/08/2026', $localized->fromWhmcsDate('2026-08-10', 'datecreated'));
    }
}
