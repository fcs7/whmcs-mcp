<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

/**
 * Captura o que `error_log()` realmente escreveu, redirecionando o sink para um
 * arquivo temporário.
 *
 * Sem isso os testes de F2 ficavam verdes enquanto o vazamento aparecia no
 * stderr: verificar só o Activity Log prova metade do contrato. Aqui inspeciona-
 * se o sink protegido de verdade.
 */
final class ErrorLogSpy
{
    private static ?string $file = null;
    private static ?string $previous = null;
    private static ?string $previousType = null;

    public static function start(): void
    {
        self::stop();

        self::$file = tempnam(sys_get_temp_dir(), 'nt_mcp_errlog_');
        self::$previous = (string) ini_get('error_log');
        self::$previousType = (string) ini_get('log_errors');

        ini_set('log_errors', '1');
        ini_set('error_log', self::$file);
    }

    public static function stop(): void
    {
        if (self::$file === null) {
            return;
        }

        ini_set('error_log', self::$previous ?? '');
        ini_set('log_errors', self::$previousType ?? '1');

        @unlink(self::$file);
        self::$file = null;
        self::$previous = null;
        self::$previousType = null;
    }

    public static function contents(): string
    {
        if (self::$file === null || !is_file(self::$file)) {
            return '';
        }

        return (string) file_get_contents(self::$file);
    }

    /** @return array<string> linhas não vazias */
    public static function lines(): array
    {
        return array_values(array_filter(
            preg_split('/\r?\n/', self::contents()) ?: [],
            static fn(string $l) => trim($l) !== ''
        ));
    }

    public static function hasLineContaining(string $needle): bool
    {
        return str_contains(self::contents(), $needle);
    }
}
