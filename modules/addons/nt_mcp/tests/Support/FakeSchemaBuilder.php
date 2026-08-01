<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

/**
 * Fake do Schema Builder do WHMCS (`Capsule::schema()`).
 *
 * Existe para que `CapsuleSchemaProbe` seja exercitado no caminho REAL —
 * `hasTable()`/`hasColumn()` — e para que o teste possa PROVAR que o probe
 * nunca lê linha nenhuma: `FakeCapsule::$calls` (que só registra acesso a
 * dados) precisa continuar vazio depois de um probe completo.
 *
 * Desligado por padrão: sem `$enabled`, as duas operações lançam, reproduzindo
 * o ambiente sem banco. Assim o fail-closed do probe também é testável.
 */
final class FakeSchemaBuilder
{
    public static bool $enabled = false;

    /** @var array<string, array<int, string>> tabela => colunas existentes */
    public static array $tables = [];

    /** @var array<int, string> operações de metadata observadas */
    public static array $calls = [];

    /** Quando setado, toda operação de metadata lança. */
    public static ?\Throwable $failure = null;

    public static function reset(): void
    {
        self::$enabled = false;
        self::$tables = [];
        self::$calls = [];
        self::$failure = null;
    }

    /** @param array<string, array<int, string>> $tables */
    public static function install(array $tables): void
    {
        self::$enabled = true;
        self::$tables = $tables;
    }

    /** Remove uma coluna de uma tabela já instalada. */
    public static function dropColumn(string $table, string $column): void
    {
        self::$tables[$table] = array_values(array_filter(
            self::$tables[$table] ?? [],
            static fn(string $name): bool => $name !== $column
        ));
    }

    public static function dropTable(string $table): void
    {
        unset(self::$tables[$table]);
    }

    public static function builder(): self
    {
        return new self();
    }

    public function hasTable(string $table): bool
    {
        self::guard();
        self::$calls[] = "hasTable({$table})";

        return array_key_exists($table, self::$tables);
    }

    public function hasColumn(string $table, string $column): bool
    {
        self::guard();
        self::$calls[] = "hasColumn({$table},{$column})";

        return in_array($column, self::$tables[$table] ?? [], true);
    }

    private static function guard(): void
    {
        if (self::$failure !== null) {
            throw self::$failure;
        }

        if (!self::$enabled) {
            throw new \RuntimeException('FakeSchemaBuilder disabled: no WHMCS database in this test');
        }
    }
}
