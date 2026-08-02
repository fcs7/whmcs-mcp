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
 * O CRM (`CapsuleQueryPort`) usa o mesmo fake pelo mesmo motivo: a afirmação
 * "a projeção é sempre explícita e o soft-deleted nunca é lido" precisa ser
 * provada contra a cadeia de produção, não contra um dublê do repositório. Por
 * isso `where()`/`whereNull()` FILTRAM de verdade e as mutações são contadas.
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

    /** @var array<int, array{verb:string, table:string, values:array<string,mixed>}> mutações tentadas */
    public static array $mutations = [];

    /** Próximo id devolvido por `insertGetId()`. */
    public static int $nextInsertId = 1;

    public static function reset(): void
    {
        self::$enabled = false;
        self::$rows = [];
        self::$calls = [];
        self::$failure = null;
        self::$mutations = [];
        self::$nextInsertId = 1;
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

    /**
     * Popula uma tabela a partir de linhas associativas.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function withRows(string $table, array $rows): void
    {
        self::$enabled = true;
        self::$rows[$table] = array_map(static fn(array $row): object => (object) $row, $rows);
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

    /** Sentinela para distinguir `where($c,$v)` de `where($c,$op,$v)`. */
    private const NO_VALUE = "\0__fake_capsule_no_value__\0";

    /** @var array<string, mixed> */
    private array $wheres = [];

    /** @var array<string, array<int, int|string>> */
    private array $inWheres = [];

    /** @var array<int, array{0:string,1:string,2:mixed}> */
    private array $comparisons = [];

    /** @var array<int, string> */
    private array $nullWheres = [];

    /** @var array<int, array{0:string,1:string}> */
    private array $orders = [];

    private ?int $take = null;
    private int $skip = 0;

    public function __construct(private readonly string $table) {}

    /** Aceita `select('a')` e `select(['a','b'])`, como o builder real. */
    public function select(array|string ...$columns): self
    {
        $flat = [];
        foreach ($columns as $column) {
            foreach ((array) $column as $name) {
                $flat[] = (string) $name;
            }
        }

        $this->columns = $flat;
        FakeCapsule::$calls[] = 'select(' . implode(',', $flat) . ')';

        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;
        FakeCapsule::$calls[] = 'distinct()';

        return $this;
    }

    /**
     * Aceita `where($col, $value)` e `where($col, $operator, $value)`, como o
     * builder real — o keyset das varreduras usa a forma de três argumentos.
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = self::NO_VALUE): self
    {
        if ($value === self::NO_VALUE) {
            FakeCapsule::$calls[] = "where({$column})";
            $this->wheres[$column] = $operatorOrValue;

            return $this;
        }

        $operator = (string) $operatorOrValue;
        FakeCapsule::$calls[] = "where({$column},{$operator})";
        $this->comparisons[] = [$column, $operator, $value];

        return $this;
    }

    /**
     * @param array<int, int|string> $values
     */
    public function whereIn(string $column, array $values): self
    {
        FakeCapsule::$calls[] = "whereIn({$column}," . count($values) . ')';
        $this->inWheres[$column] = $values;

        return $this;
    }

    public function whereNull(string $column): self
    {
        FakeCapsule::$calls[] = "whereNull({$column})";
        $this->nullWheres[] = $column;

        return $this;
    }

    public function count(): int
    {
        FakeCapsule::$calls[] = 'count()';

        return count($this->matchingRows());
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        FakeCapsule::$calls[] = "orderBy({$column},{$direction})";
        $this->orders[] = [$column, $direction];

        return $this;
    }

    public function take(int $limit): self
    {
        FakeCapsule::$calls[] = "take({$limit})";
        $this->take = $limit;

        return $this;
    }

    public function skip(int $offset): self
    {
        FakeCapsule::$calls[] = "skip({$offset})";
        $this->skip = $offset;

        return $this;
    }

    public function first(): ?object
    {
        FakeCapsule::$calls[] = 'first()';

        return $this->get()[0] ?? null;
    }

    /** @param array<string, mixed> $values */
    public function insertGetId(array $values): int
    {
        FakeCapsule::$calls[] = 'insertGetId()';
        FakeCapsule::$mutations[] = ['verb' => 'INSERT', 'table' => $this->table, 'values' => $values];

        return FakeCapsule::$nextInsertId++;
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): int
    {
        FakeCapsule::$calls[] = 'update()';
        FakeCapsule::$mutations[] = ['verb' => 'UPDATE', 'table' => $this->table, 'values' => $values];

        return count($this->matchingRows());
    }

    /** @return array<int, object> linhas com APENAS as colunas projetadas */
    public function get(): array
    {
        FakeCapsule::$calls[] = 'get()';

        $rows = $this->matchingRows();

        foreach (array_reverse($this->orders) as [$column, $direction]) {
            usort($rows, static function (object $a, object $b) use ($column, $direction): int {
                $comparison = ($a->{$column} ?? null) <=> ($b->{$column} ?? null);

                return $direction === 'desc' ? -$comparison : $comparison;
            });
        }

        if ($this->skip > 0) {
            $rows = array_slice($rows, $this->skip);
        }

        if ($this->take !== null) {
            $rows = array_slice($rows, 0, $this->take);
        }

        if ($this->columns === []) {
            return array_values($rows);
        }

        // Projeção real: só as colunas pedidas sobrevivem, como no driver.
        return array_values(array_map(function (object $row): object {
            $projected = new \stdClass();
            foreach ($this->columns as $column) {
                $projected->{$column} = $row->{$column} ?? null;
            }
            return $projected;
        }, $rows));
    }

    /** @return array<int, object> */
    private function matchingRows(): array
    {
        $rows = FakeCapsule::$rows[$this->table] ?? [];

        foreach ($this->wheres as $column => $value) {
            $rows = array_filter(
                $rows,
                static fn(object $row): bool => ($row->{$column} ?? null) == $value
            );
        }

        foreach ($this->inWheres as $column => $values) {
            $rows = array_filter(
                $rows,
                static fn(object $row): bool => in_array($row->{$column} ?? null, $values, false)
            );
        }

        foreach ($this->comparisons as [$column, $operator, $value]) {
            $rows = array_filter($rows, static function (object $row) use ($column, $operator, $value): bool {
                $actual = $row->{$column} ?? null;

                return match ($operator) {
                    '>' => $actual > $value,
                    '>=' => $actual >= $value,
                    '<' => $actual < $value,
                    '<=' => $actual <= $value,
                    '=' => $actual == $value,
                    '!=', '<>' => $actual != $value,
                    default => throw new \RuntimeException("FakeCapsule: unsupported operator {$operator}"),
                };
            });
        }

        foreach ($this->nullWheres as $column) {
            $rows = array_filter(
                $rows,
                static fn(object $row): bool => ($row->{$column} ?? null) === null
            );
        }

        return array_values($rows);
    }
}
