<?php
// Simula o ambiente WHMCS para testes unitários
// localAPI() é a função global do WHMCS — mockamos aqui

if (!function_exists('localAPI')) {
    function localAPI(string $command, array $values, string $adminuser = 'admin'): array {
        return ['result' => 'success'];
    }
}

if (!function_exists('logActivity')) {
    function logActivity(string $msg): void {
        \NtMcp\Tests\Support\ActivityLogSpy::record($msg);
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------
// Helpers globais de data do WHMCS, usados por NtMcp\Whmcs\LocalizedDate.
// Stubs controláveis por NtMcp\Tests\Support\WhmcsDateFormat, para que os
// testes atravessem o caminho real (função global) e possam exercitar
// formatos de localização distintos e os modos de falha fail-closed.
// ---------------------------------------------------------------
if (!function_exists('fromMySQLDate')) {
    function fromMySQLDate($date, $includeTime = false, $applyClientDateFormat = false) {
        return \NtMcp\Tests\Support\WhmcsDateFormat::format((string) $date);
    }
}

if (!function_exists('toMySQLDate')) {
    function toMySQLDate($date) {
        if (!\NtMcp\Tests\Support\WhmcsDateFormat::$parserAvailable) {
            throw new \RuntimeException('toMySQLDate unavailable in this simulation');
        }

        return \NtMcp\Tests\Support\WhmcsDateFormat::toMySQL((string) $date);
    }
}

// ---------------------------------------------------------------
// Stub REAL de \WHMCS\Config\Setting.
//
// O código de produção decide por class_exists('\WHMCS\Config\Setting'):
// sem a classe ele nunca chega ao parser tri-state. Para exercitar o caminho
// verdadeiro (M3), a classe precisa existir no suite.
//
// O store nasce VAZIO de propósito: getValue() devolve null, o ConfigFlag
// classifica como Absent e todo gate cai no default decidido — exatamente o
// comportamento que os testes já assumiam quando a classe não existia.
// ---------------------------------------------------------------
if (!class_exists('\WHMCS\Config\Setting')) {
    eval('
        namespace WHMCS\Config;

        class Setting
        {
            /** @var array<string, mixed> */
            public static array $store = [];

            /** @var bool quando true, getValue() lança (simula falha de leitura) */
            public static bool $throwOnRead = false;

            public static function getValue(string $key): mixed
            {
                if (static::$throwOnRead) {
                    throw new \RuntimeException("simulated config read failure for {$key}");
                }

                return static::$store[$key] ?? null;
            }

            public static function reset(): void
            {
                static::$store = [];
                static::$throwOnRead = false;
            }
        }
    ');
}

// Ensure Mockery expectations are verified after each test
register_shutdown_function(function () {
    \Mockery::close();
});
