<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

/**
 * Fake do `Capsule` do WHMCS, no formato do driver real.
 *
 * A revisão apontou que os testes de gateway provavam o resolver injetado, não
 * a consulta de produção. Este fake fecha essa lacuna: `PaymentGatewayDirectory`
 * roda `table()->select()->distinct()->get()` de verdade, e o teste inspeciona
 * a cadeia chamada além do resultado.
 *
 * Desligado por padrão (`$enabled = false`): `table()` lança, reproduzindo o
 * ambiente sem WHMCS bootstrapado que a maioria dos testes assume.
 */
final class FakeCapsule
{
    public static bool $enabled = false;

    /** @var array<string, array<int, object>> linhas por tabela */
    public static array $rows = [];

    /** @var array<int, string> cadeia de chamadas observada */
    public static array $calls = [];

    /** Quando setado, `table()` lança — simula driver fora do ar. */
    public static ?\Throwable $failure = null;

    public static function reset(): void
    {
        self::$enabled = false;
        self::$rows = [];
        self::$calls = [];
        self::$failure = null;
    }

    /** Popula uma tabela com valores da coluna `gateway`. */
    public static function withGateways(array $gatewayValues): void
    {
        self::$enabled = true;
        self::$rows['tblpaymentgateways'] = array_map(static function ($value) {
            $row = new \stdClass();
            $row->gateway = $value;
            // Colunas que NUNCA podem ser projetadas — se aparecerem no
            // resultado, a projeção está errada.
            $row->setting = 'secretKey';
            $row->value = 'sk_live_MUST_NEVER_BE_READ';
            return $row;
        }, $gatewayValues);
    }

    public static function table(string $table): FakeCapsuleQuery
    {
        if (self::$failure !== null) {
            throw self::$failure;
        }

        if (!self::$enabled) {
            throw new \RuntimeException('FakeCapsule disabled: no WHMCS database in this test');
        }

        self::$calls[] = "table({$table})";

        return new FakeCapsuleQuery($table);
    }
}

/** Query builder mínimo, registrando a cadeia. */
final class FakeCapsuleQuery
{
    /** @var array<int, string> */
    private array $columns = [];
    private bool $distinct = false;

    public function __construct(private readonly string $table) {}

    public function select(string ...$columns): self
    {
        $this->columns = $columns;
        FakeCapsule::$calls[] = 'select(' . implode(',', $columns) . ')';

        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;
        FakeCapsule::$calls[] = 'distinct()';

        return $this;
    }

    public function where(string $column, mixed $value): self
    {
        FakeCapsule::$calls[] = "where({$column})";

        return $this;
    }

    public function first(): ?object
    {
        FakeCapsule::$calls[] = 'first()';

        return FakeCapsule::$rows[$this->table][0] ?? null;
    }

    /** @return array<int, object> linhas com APENAS as colunas projetadas */
    public function get(): array
    {
        FakeCapsule::$calls[] = 'get()';

        $rows = FakeCapsule::$rows[$this->table] ?? [];
        if ($this->columns === []) {
            return $rows;
        }

        // Projeção real: só as colunas pedidas sobrevivem, como no driver.
        return array_map(function (object $row): object {
            $projected = new \stdClass();
            foreach ($this->columns as $column) {
                $projected->{$column} = $row->{$column} ?? null;
            }
            return $projected;
        }, $rows);
    }
}
