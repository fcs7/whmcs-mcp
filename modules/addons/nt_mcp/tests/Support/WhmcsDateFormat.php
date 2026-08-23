<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

/**
 * Stub controlável dos helpers globais `fromMySQLDate()` / `toMySQLDate()` do
 * WHMCS, usados por `NtMcp\Whmcs\LocalizedDate`.
 *
 * Existe para que os testes atravessem o MESMO caminho de produção (a função
 * global) em vez de injetar um seam e provar outra coisa — e para permitir
 * exercitar formatos de localização diferentes e os modos de falha que precisam
 * ser fail-closed.
 */
final class WhmcsDateFormat
{
    public const MODE_OK = 'ok';
    /** Helper lança. */
    public const MODE_THROW = 'throw';
    /** Helper devolve string vazia. */
    public const MODE_EMPTY = 'empty';
    /** Helper devolve uma data DIFERENTE (formatação corrompida). */
    public const MODE_WRONG_DATE = 'wrong_date';
    /** Helper devolve ano com 2 dígitos (não verificável). */
    public const MODE_TWO_DIGIT_YEAR = 'two_digit_year';

    /** Formato PHP equivalente ao "Date Format" configurado no WHMCS. */
    public static string $phpFormat = 'd/m/Y';

    public static string $mode = self::MODE_OK;

    /** Quando true, `toMySQLDate()` some — força a verificação por dígitos. */
    public static bool $parserAvailable = true;

    public static function reset(): void
    {
        self::$phpFormat = 'd/m/Y';
        self::$mode = self::MODE_OK;
        self::$parserAvailable = true;
    }

    /** Equivalente a fromMySQLDate($ymd, false, false). */
    public static function format(string $ymd): string
    {
        switch (self::$mode) {
            case self::MODE_THROW:
                throw new \RuntimeException('simulated fromMySQLDate failure');
            case self::MODE_EMPTY:
                return '';
            case self::MODE_WRONG_DATE:
                return '01/01/1970';
            case self::MODE_TWO_DIGIT_YEAR:
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
                return $d === false ? '' : $d->format('d/m/y');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return $date === false ? '' : $date->format(self::$phpFormat);
    }

    /** Equivalente a toMySQLDate($localized). */
    public static function toMySQL(string $localized): string
    {
        $date = \DateTimeImmutable::createFromFormat('!' . self::$phpFormat, trim($localized));
        if ($date === false) {
            return '';
        }

        return $date->format('Y-m-d H:i:s');
    }
}
