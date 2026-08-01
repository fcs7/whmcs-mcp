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
        $localized->setParser(static fn(string $v): string => $v === '10|08|2026' ? '2026-08-10' : '');

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
     * P1 da revisão: NÃO existe mais fallback heurístico. Sem `toMySQLDate()`
     * não há como provar o round-trip, então a operação falha — mesmo que a
     * conversão esteja de fato correta.
     */
    public function test_without_inverse_helper_it_fails_closed_even_for_a_correct_conversion(): void
    {
        $localized = new LocalizedDate();
        $localized->setParser(null); // declara ausência do inverso

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not prove');

        $localized->fromWhmcsDate('2026-08-10', 'datecreated');
    }

    /**
     * O caso concreto que o fallback antigo aceitava: mesmos dígitos, data civil
     * TROCADA. `08/10/2026` numa instalação DD/MM/YYYY é 8 de outubro, não 10 de
     * agosto — e passava porque 2026, 8 e 10 apareciam em alguma ordem.
     */
    public function test_swapped_month_and_day_is_rejected(): void
    {
        $localized = new LocalizedDate();
        $localized->setFormatter(static fn(string $ymd): string => '08/10/2026');
        // Inverso honesto: numa instalação DD/MM/YYYY isso é 2026-10-08.
        $localized->setParser(static fn(string $v): string => '2026-10-08 00:00:00');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not prove');

        $localized->fromWhmcsDate('2026-08-10', 'datecreated');
    }

    /** Verificador inverso quebrado também é fail-closed. */
    public function test_broken_inverse_helper_fails_closed(): void
    {
        WhmcsDateFormat::$parserAvailable = false; // toMySQLDate() lança

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not prove');

        (new LocalizedDate())->fromWhmcsDate('2026-08-10', 'datecreated');
    }

    /** Inverso devolvendo lixo não é aceito como prova. */
    public function test_inverse_returning_garbage_fails_closed(): void
    {
        $localized = new LocalizedDate();
        $localized->setParser(static fn(string $v): string => 'not-a-date');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not prove');

        $localized->fromWhmcsDate('2026-08-10', 'datecreated');
    }

    // ---------------------------------------------------------------
    // fromFlexibleInput — as três famílias aceitas em `validuntil`
    // ---------------------------------------------------------------

    #[DataProvider('flexibleInputProvider')]
    public function test_flexible_input_accepts_the_three_families(string $input, string $expected): void
    {
        $this->assertSame($expected, (new LocalizedDate())->fromFlexibleInput($input, 'validuntil'));
    }

    public static function flexibleInputProvider(): array
    {
        return [
            'Y-m-d'          => ['2026-08-10', '10/08/2026'],
            'ISO date-time'  => ['2026-08-10T00:00:00Z', '10/08/2026'],
            'ISO com offset' => ['2026-08-10T23:30:00-03:00', '10/08/2026'],
            'já localizado'  => ['10/08/2026', '10/08/2026'],
        ];
    }

    /** Data escrita no formato de OUTRA configuração não passa disfarçada. */
    public function test_flexible_input_rejects_a_date_from_another_configuration(): void
    {
        WhmcsDateFormat::$phpFormat = 'd/m/Y'; // instalação usa DD/MM/YYYY

        // 08/10/2026 é uma data VÁLIDA em DD/MM (8 de outubro), então volta como
        // 08/10/2026 e o round-trip fecha — é aceita, mas como 8 de outubro.
        $this->assertSame('08/10/2026', (new LocalizedDate())->fromFlexibleInput('08/10/2026', 'validuntil'));

        // Já 10/20/2026 (MM/DD/YYYY) não existe em DD/MM e é recusada.
        $this->expectException(\InvalidArgumentException::class);
        (new LocalizedDate())->fromFlexibleInput('10/20/2026', 'validuntil');
    }

    public function test_flexible_input_rejects_garbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('validuntil must be a date');

        (new LocalizedDate())->fromFlexibleInput('ontem', 'validuntil');
    }

    public function test_flexible_input_rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        (new LocalizedDate())->fromFlexibleInput('   ', 'validuntil');
    }

    /** Sem inverso, nem a família localizada nem as demais passam. */
    public function test_flexible_input_fails_closed_without_inverse_helper(): void
    {
        $localized = new LocalizedDate();
        $localized->setParser(null);

        $this->expectException(\RuntimeException::class);

        $localized->fromFlexibleInput('2026-08-10', 'validuntil');
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
