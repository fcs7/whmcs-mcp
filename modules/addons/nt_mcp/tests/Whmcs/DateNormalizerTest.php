<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\DateNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M1 — a ponte entre o `format: date-time` que o SDK v1 publica e o `Y-m-d` que
 * a API do WHMCS documenta.
 */
class DateNormalizerTest extends TestCase
{
    #[DataProvider('acceptedProvider')]
    public function test_accepts_and_normalizes_to_whmcs_format(string $input, string $expected): void
    {
        $this->assertSame($expected, DateNormalizer::toWhmcsDate($input, 'duedate'));
    }

    public static function acceptedProvider(): array
    {
        return [
            'data simples'         => ['2026-08-10', '2026-08-10'],
            'date-time Z'          => ['2026-08-10T00:00:00Z', '2026-08-10'],
            'date-time offset'     => ['2026-08-10T13:45:00+03:00', '2026-08-10'],
            'date-time fração'     => ['2026-08-10T13:45:00.123Z', '2026-08-10'],
            'offset extremo positivo' => ['2026-08-10T13:45:00+14:00', '2026-08-10'],
            'offset extremo negativo' => ['2026-08-10T13:45:00-14:00', '2026-08-10'],
            'espaços em volta'     => ['  2026-08-10  ', '2026-08-10'],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function test_rejects_invalid_input(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duedate must be a real date');

        DateNormalizer::toWhmcsDate($input, 'duedate');
    }

    public static function rejectedProvider(): array
    {
        return [
            'texto'            => ['ontem'],
            'dia inexistente'  => ['2026-02-31'],
            'mês inexistente'  => ['2026-13-01'],
            'formato BR'       => ['10/08/2026'],
            'só ano'           => ['2026'],
            'vazio'            => [''],
            'lixo com T'       => ['2026-02-31T00:00:00Z'],
            'sem padding'      => ['2026-8-10'],
            'T minúsculo'      => ['2026-08-10t00:00:00Z'],
            'Z minúsculo'      => ['2026-08-10T00:00:00z'],
            'espaço separador' => ['2026-08-10 13:45:00Z'],
            'sem colon offset' => ['2026-08-10T13:45:00+0300'],
            'sem zona'         => ['2026-08-10T13:45:00'],
            'sem segundos'     => ['2026-08-10T13:45Z'],
            'offset 14:01'     => ['2026-08-10T13:45:00+14:01'],
            'offset 15:00'     => ['2026-08-10T13:45:00-15:00'],
            'offset minuto'    => ['2026-08-10T13:45:00+03:60'],
            'hora inválida'    => ['2026-08-10T24:00:00Z'],
            'segundo leap'     => ['2026-08-10T23:59:60Z'],
        ];
    }

    public function test_optional_keeps_empty_as_empty(): void
    {
        $this->assertSame('', DateNormalizer::optional('', 'duedate'));
        $this->assertSame('', DateNormalizer::optional('   ', 'duedate'));
    }

    public function test_optional_still_validates_non_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DateNormalizer::optional('garbage', 'duedate');
    }

    public function test_error_message_names_the_field(): void
    {
        try {
            DateNormalizer::toWhmcsDate('nope', 'datecreated');
            $this->fail('deveria lançar');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('datecreated', $e->getMessage());
            $this->assertStringContainsString('2026-08-10T00:00:00Z', $e->getMessage());
        }
    }
}
