<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

/**
 * Captura o que `logActivity()` recebeu, para que os testes de auditoria (m1)
 * possam afirmar sobre o Activity Log real produzido por
 * `LocalApiClient::auditLog()` — incluindo a redação central.
 *
 * A captura fica DESLIGADA por padrão: só grava entre start() e stop(), para
 * não acumular memória ao longo do suite inteiro.
 */
final class ActivityLogSpy
{
    private static bool $capturing = false;

    /** @var array<string> */
    private static array $entries = [];

    /** Quando setado, `record()` lança — simula o sink de auditoria falhando. */
    private static ?\Throwable $failure = null;

    public static function failWith(?\Throwable $e): void
    {
        self::$failure = $e;
    }

    public static function start(): void
    {
        self::$capturing = true;
        self::$entries = [];
    }

    public static function stop(): void
    {
        self::$capturing = false;
        self::$entries = [];
        self::$failure = null;
    }

    public static function record(string $message): void
    {
        if (self::$failure !== null) {
            throw self::$failure;
        }

        if (self::$capturing) {
            self::$entries[] = $message;
        }
    }

    /** @return array<string> */
    public static function entries(): array
    {
        return self::$entries;
    }

    /** Entradas que contêm o trecho dado. @return array<string> */
    public static function matching(string $needle): array
    {
        return array_values(array_filter(
            self::$entries,
            static fn(string $e) => str_contains($e, $needle)
        ));
    }

    public static function hasEntryContaining(string $needle): bool
    {
        return self::matching($needle) !== [];
    }
}
