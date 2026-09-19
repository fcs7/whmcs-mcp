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

    /** @var array<int, string> transações read-only solicitadas pelo port real */
    public static array $snapshotCalls = [];

    /** @var array<int, string> operações preparadas por PDO (`read`/`write`). */
    public static array $pdoCalls = [];

    /** @var array{set:mixed,begin:mixed,commit:mixed,rollback:mixed} */
    public static array $snapshotFailures = ['set' => null, 'begin' => null, 'commit' => null, 'rollback' => null];

    public static bool $ambientTransaction = false;

    public static int $ambientTransactionLevel = 0;

    /**
     * Engine reportado por `information_schema.tables` (via `CapsuleEngineProbe`),
     * por tabela — default `'InnoDB'` para que testes que nunca tocam o assunto
     * continuem passando sem configurar nada. `withTableEngine()` simula MyISAM
     * (ou qualquer outro engine) para uma tabela específica.
     */
    public static string $defaultEngine = 'InnoDB';

    /** @var array<string, string> tabela => engine, sobrepõe `$defaultEngine` */
    public static array $tableEngines = [];

    /**
     * Nome da propriedade do engine na linha sintética de
     * `information_schema.tables` — default `'engine'`. Testes podem setar
     * `'ENGINE'` para simular um driver MySQL 8 que devolve a coluna sem
     * honrar o alias `engine AS engine_name` (`CapsuleEngineProbe::engineOf()`
     * precisa cair no fallback maiúsculo nesse caso).
     */
    public static string $informationSchemaEngineKey = 'engine';

    /**
     * Tabelas cujo `get()` devolve um `FakeCollection` (Traversable não-array,
     * como `Illuminate\Support\Collection` no WHMCS real) em vez de um array
     * puro. Desligado por padrão — só os testes de tradução ligam, para provar
     * que o código de produção não quebra com `array_map()`/`array_filter()`
     * direto sobre o retorno de `get()`.
     *
     * @var array<int, string>
     */
    public static array $collectionTables = [];

    /**
     * Pilha de snapshots de transação (`rows`/`mutations`/`nextInsertId`).
     * `beginTransaction()` empilha, `commit()` descarta o topo e `rollBack()`
     * restaura o topo — o fake antigo só desfazia o contador de nível, nunca
     * as mutações, então um "rollback" não revertia nada de fato.
     *
     * @var array<int, array{rows: array<string, array<int, object>>, mutations: array<int, array{verb:string, table:string, values:array<string,mixed>}>, nextInsertId: int}>
     */
    private static array $transactionSnapshots = [];

    private static ?FakeCapsuleConnection $connection = null;

    public static function reset(): void
    {
        self::$enabled = false;
        self::$rows = [];
        self::$calls = [];
        self::$failure = null;
        self::$mutations = [];
        self::$nextInsertId = 1;
        self::$snapshotCalls = [];
        self::$pdoCalls = [];
        self::$snapshotFailures = ['set' => null, 'begin' => null, 'commit' => null, 'rollback' => null];
        self::$ambientTransaction = false;
        self::$ambientTransactionLevel = 0;
        self::$connection = null;
        self::$collectionTables = [];
        self::$transactionSnapshots = [];
        self::$defaultEngine = 'InnoDB';
        self::$tableEngines = [];
        self::$informationSchemaEngineKey = 'engine';
    }

    /** Simula o engine de uma tabela em `information_schema.tables` (ex.: `'MyISAM'`). */
    public static function withTableEngine(string $table, string $engine): void
    {
        self::$enabled = true;
        self::$tableEngines[$table] = $engine;
    }

    /** Empilha o estado atual (`rows`/`mutations`/`nextInsertId`). */
    public static function pushTransactionSnapshot(): void
    {
        self::$transactionSnapshots[] = [
            'rows' => self::$rows,
            'mutations' => self::$mutations,
            'nextInsertId' => self::$nextInsertId,
        ];
    }

    /** Descarta o snapshot do topo sem restaurar (equivalente a um commit). */
    public static function discardTransactionSnapshot(): void
    {
        array_pop(self::$transactionSnapshots);
    }

    /** Restaura o snapshot do topo (equivalente a um rollback real). */
    public static function restoreTransactionSnapshot(): void
    {
        $snapshot = array_pop(self::$transactionSnapshots);
        if ($snapshot === null) {
            return;
        }

        self::$rows = $snapshot['rows'];
        self::$mutations = $snapshot['mutations'];
        self::$nextInsertId = $snapshot['nextInsertId'];
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

    public static function connection(): FakeCapsuleConnection
    {
        if (self::$failure !== null) {
            throw self::$failure;
        }

        if (!self::$enabled) {
            throw new \RuntimeException('FakeCapsule disabled: no WHMCS database in this test');
        }

        return self::$connection ??= new FakeCapsuleConnection();
    }
}

/** Seam fiel ao lifecycle mínimo de `Illuminate\Database\Connection`. */
final class FakeCapsuleConnection
{
    private FakeCapsulePdo $writePdo;
    private FakeCapsulePdo $readPdo;
    private int $transactions;

    public function __construct()
    {
        $this->writePdo = new FakeCapsulePdo('write', FakeCapsule::$ambientTransaction);
        $this->readPdo = new FakeCapsulePdo('read');
        $this->transactions = FakeCapsule::$ambientTransactionLevel;
    }

    public function getPdo(): FakeCapsulePdo
    {
        return $this->writePdo;
    }

    public function getReadPdo(): FakeCapsulePdo
    {
        return $this->transactions > 0 ? $this->writePdo : $this->readPdo;
    }

    public function transactionLevel(): int
    {
        return $this->transactions;
    }

    public function beginTransaction(): bool|null
    {
        $result = $this->writePdo->beginTransaction();
        // O Illuminate incrementa o nível após chamar o PDO; mesmo um driver
        // que devolve false precisa ser detectado pelo boundary pós-begin.
        $this->transactions++;
        FakeCapsule::pushTransactionSnapshot();

        return $result;
    }

    public function commit(): bool|null
    {
        $result = $this->writePdo->commit();
        $this->transactions = max(0, $this->transactions - 1);
        FakeCapsule::discardTransactionSnapshot();

        return $result;
    }

    public function rollBack(): bool|null
    {
        $result = $this->writePdo->rollBack();
        $this->transactions = 0;
        FakeCapsule::restoreTransactionSnapshot();

        return $result;
    }

    public function table(string $table): FakeCapsuleQuery
    {
        $this->getReadPdo()->prepare("query {$table}");

        return FakeCapsule::table($table);
    }

    /**
     * Reproduz `Illuminate\Database\Connection::transaction()`: begin, executa
     * o callback, commit; qualquer exceção faz rollback e relança.
     *
     * @param callable(): mixed $callback
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
        $this->commit();

        return $result;
    }

    public function getSchemaBuilder(): FakeCapsuleSchemaBuilder
    {
        return new FakeCapsuleSchemaBuilder($this);
    }

    public function markSchemaQuery(): void
    {
        $this->getReadPdo()->prepare('schema');
    }
}

final class FakeCapsulePdo
{
    private bool $inTransaction;

    public function __construct(private readonly string $role, bool $inTransaction = false)
    {
        $this->inTransaction = $inTransaction;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function exec(string $statement): int|false
    {
        FakeCapsule::$snapshotCalls[] = "{$this->role}:{$statement}";
        $failure = FakeCapsule::$snapshotFailures['set'];
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        if ($failure === false) {
            return false;
        }

        return 0;
    }

    public function beginTransaction(): bool
    {
        FakeCapsule::$snapshotCalls[] = "{$this->role}:begin";
        $failure = FakeCapsule::$snapshotFailures['begin'];
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        if ($failure === false) {
            return false;
        }
        $this->inTransaction = true;

        return true;
    }

    public function commit(): bool
    {
        FakeCapsule::$snapshotCalls[] = "{$this->role}:commit";
        $failure = FakeCapsule::$snapshotFailures['commit'];
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        if ($failure === false) {
            return false;
        }
        $this->inTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        FakeCapsule::$snapshotCalls[] = "{$this->role}:rollback";
        $failure = FakeCapsule::$snapshotFailures['rollback'];
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        if ($failure === false) {
            return false;
        }
        $this->inTransaction = false;

        return true;
    }

    public function prepare(string $statement): bool
    {
        FakeCapsule::$pdoCalls[] = "{$this->role}:{$statement}";

        return true;
    }
}

final class FakeCapsuleSchemaBuilder
{
    public function __construct(private readonly FakeCapsuleConnection $connection)
    {
    }

    public function hasTable(string $table): bool
    {
        $this->connection->markSchemaQuery();

        return FakeSchemaBuilder::builder()->hasTable($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        $this->connection->markSchemaQuery();

        return FakeSchemaBuilder::builder()->hasColumn($table, $column);
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

    /** @var array<int, FakeCapsuleWhereGroup> grupos `where(Closure)` — `(a OR b)` */
    private array $whereGroups = [];

    /** @var array<string, array<int, int|string>> */
    private array $inWheres = [];

    /** @var array<int, array{0:string,1:string,2:mixed}> */
    private array $comparisons = [];

    /** @var array<int, string> */
    private array $nullWheres = [];

    /** @var array<int, array{0:string,1:string}> */
    private array $orders = [];

    /** @var array<int, string> ignorados na filtragem — só registrados (ex.: `table_schema = database()`) */
    private array $rawWheres = [];

    /** @var array<int, string> */
    private array $groupByColumns = [];

    /** @var array<int, string> expressões cruas de `selectRaw()`, ex. `'COUNT(*) as aggregate_count'` */
    private array $selectRaws = [];

    private ?int $take = null;
    private int $skip = 0;

    /** Registrado só para o teste provar que a linha foi lida sob lock. */
    private bool $lockedForUpdate = false;

    public function __construct(private readonly string $table) {}

    public function lockForUpdate(): self
    {
        FakeCapsule::$calls[] = 'lockForUpdate()';
        $this->lockedForUpdate = true;

        return $this;
    }

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

    /** Não filtra — registrado só para a cadeia observada (`table_schema = database()`). */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        FakeCapsule::$calls[] = "whereRaw({$sql})";
        $this->rawWheres[] = $sql;

        return $this;
    }

    /** @param array<int,string>|string $columns */
    public function selectRaw(string $expression, array $bindings = []): self
    {
        FakeCapsule::$calls[] = "selectRaw({$expression})";
        $this->selectRaws[] = $expression;

        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        FakeCapsule::$calls[] = 'groupBy(' . implode(',', $columns) . ')';
        $this->groupByColumns = $columns;

        return $this;
    }

    /**
     * Aceita `where($col, $value)`, `where($col, $operator, $value)` e
     * `where(Closure)` (grupo `(a OR b)`), como o builder real — o keyset das
     * varreduras usa a forma de três argumentos, e o filtro de custom field
     * admin-only usa a forma de closure.
     */
    public function where(string|\Closure $column, mixed $operatorOrValue = self::NO_VALUE, mixed $value = self::NO_VALUE): self
    {
        if ($column instanceof \Closure) {
            FakeCapsule::$calls[] = 'where(group)';
            $group = new FakeCapsuleWhereGroup();
            $column($group);
            $this->whereGroups[] = $group;

            return $this;
        }

        if ($operatorOrValue === self::NO_VALUE) {
            throw new \RuntimeException("FakeCapsule: where({$column}) requires a value or operator.");
        }

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

        return $this->computeRows()[0] ?? null;
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

    public function delete(): int
    {
        FakeCapsule::$calls[] = 'delete()';
        $matchingIds = array_fill_keys(
            array_map(static fn(object $row): int => spl_object_id($row), $this->matchingRows()),
            true
        );
        $deleted = count($matchingIds);
        FakeCapsule::$rows[$this->table] = array_values(array_filter(
            FakeCapsule::$rows[$this->table] ?? [],
            static fn(object $row): bool => !isset($matchingIds[spl_object_id($row)])
        ));
        FakeCapsule::$mutations[] = ['verb' => 'DELETE', 'table' => $this->table, 'values' => []];

        return $deleted;
    }

    /**
     * @return array<int, object>|FakeCollection linhas com APENAS as colunas
     *     projetadas. Devolve `FakeCollection` (Traversable não-array) quando a
     *     tabela está em `FakeCapsule::$collectionTables` — reproduz
     *     `Illuminate\Support\Collection`, que é o que o WHMCS real devolve.
     */
    public function get(): array|FakeCollection
    {
        FakeCapsule::$calls[] = 'get()';

        $rows = $this->computeRows();

        return in_array($this->table, FakeCapsule::$collectionTables, true)
            ? new FakeCollection($rows)
            : $rows;
    }

    /** @return array<int, object> */
    private function computeRows(): array
    {
        if ($this->table === 'information_schema.tables') {
            return $this->informationSchemaRows();
        }

        $rows = $this->matchingRows();

        if ($this->groupByColumns !== []) {
            return $this->groupedRows($rows);
        }

        foreach (array_reverse($this->orders) as [$column, $direction]) {
            usort($rows, static function (object $a, object $b) use ($column, $direction): int {
                $left = $a->{$column} ?? null;
                $right = $b->{$column} ?? null;
                $comparison = \NtMcp\Crm\CrmSchema::isIntegerColumn($column)
                    ? self::compareIntegerStrings((string) $left, (string) $right)
                    : strcmp((string) $left, (string) $right);

                return $direction === 'desc' ? -$comparison : $comparison;
            });
        }

        if ($this->skip > 0) {
            $rows = array_slice($rows, $this->skip);
        }

        if ($this->take !== null) {
            $rows = array_slice($rows, 0, $this->take);
        }

        if ($this->columns === [] && $this->selectRaws === []) {
            return array_values($rows);
        }

        // Projeção real: só as colunas pedidas (+ `selectRaw()`) sobrevivem,
        // como no driver. `selectRaw()` só entende o padrão
        // `LEFT(coluna, N) as alias`, único usado em produção.
        return array_values(array_map(function (object $row): object {
            $projected = new \stdClass();
            foreach ($this->columns as $column) {
                $projected->{$column} = $row->{$column} ?? null;
            }
            foreach ($this->selectRaws as $expression) {
                if (preg_match('/^left\(\s*(\w+)\s*,\s*(\d+)\s*\)\s+as\s+(\w+)$/i', trim($expression), $matches) === 1) {
                    $value = (string) ($row->{$matches[1]} ?? '');
                    $projected->{$matches[3]} = mb_substr($value, 0, (int) $matches[2]);
                }
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

        foreach ($this->whereGroups as $group) {
            $rows = array_filter($rows, static fn(object $row): bool => $group->matches($row));
        }

        return array_values($rows);
    }

    /**
     * Simula `information_schema.tables` para `CapsuleEngineProbe`: uma linha
     * sintética por `table_name` pedido, com `engine` de
     * `FakeCapsule::$tableEngines[$table] ?? FakeCapsule::$defaultEngine`
     * (default `'InnoDB'`, para não quebrar testes que nunca tocam o assunto).
     * `table_schema = database()` (`whereRaw`) não filtra — é ignorado de
     * propósito, o fake não modela múltiplos schemas.
     *
     * @return array<int, object>
     */
    private function informationSchemaRows(): array
    {
        $tableName = $this->wheres['table_name'] ?? null;
        if (!is_string($tableName) || $tableName === '') {
            return [];
        }

        $engine = FakeCapsule::$tableEngines[$tableName] ?? FakeCapsule::$defaultEngine;
        if ($engine === null || $engine === '') {
            return [];
        }

        $row = new \stdClass();
        $row->table_schema = 'nt_mcp_test';
        $row->table_name = $tableName;
        $row->{FakeCapsule::$informationSchemaEngineKey} = $engine;

        return [$row];
    }

    /**
     * Agrega `$rows` por `$this->groupByColumns`, aplicando `selectRaw()` do
     * tipo `'COUNT(*) as <alias>'` como contagem do grupo. Suficiente para o
     * único uso de produção (`TranslationStatusReader`) — não é um SQL
     * genérico.
     *
     * @param array<int, object> $rows
     * @return array<int, object>
     */
    private function groupedRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $keyParts = [];
            foreach ($this->groupByColumns as $column) {
                $keyParts[] = (string) ($row->{$column} ?? '');
            }
            $key = implode("\0", $keyParts);

            $groups[$key]['row'] ??= $row;
            $groups[$key]['count'] = ($groups[$key]['count'] ?? 0) + 1;
        }

        $result = [];
        foreach ($groups as $group) {
            $projected = new \stdClass();
            foreach ($this->groupByColumns as $column) {
                $projected->{$column} = $group['row']->{$column} ?? null;
            }
            foreach ($this->selectRaws as $expression) {
                if (preg_match('/count\(\*\)\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $expression, $matches) === 1) {
                    $projected->{$matches[1]} = $group['count'];
                }
            }
            $result[] = $projected;
        }

        return $result;
    }

    private static function compareIntegerStrings(string $left, string $right): int
    {
        $leftDigits = ltrim(ltrim(trim($left), '+-'), '0');
        $rightDigits = ltrim(ltrim(trim($right), '+-'), '0');
        $leftDigits = $leftDigits === '' ? '0' : $leftDigits;
        $rightDigits = $rightDigits === '' ? '0' : $rightDigits;
        $leftNegative = str_starts_with($left, '-') && $leftDigits !== '0';
        $rightNegative = str_starts_with($right, '-') && $rightDigits !== '0';

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $comparison = strlen($leftDigits) <=> strlen($rightDigits);
        if ($comparison === 0) {
            $comparison = strcmp($leftDigits, $rightDigits);
        }

        return $leftNegative ? -$comparison : $comparison;
    }
}

/**
 * Reproduz o formato real de `->get()` no WHMCS (`Illuminate\Support\Collection`):
 * Traversable + Countable, NÃO um array. Usada só pelas tabelas listadas em
 * `FakeCapsule::$collectionTables`, para provar que código de produção que
 * aplica `array_map()`/`array_filter()`/`array_slice()` direto sobre o
 * retorno de `get()` quebra com `TypeError` no ambiente real.
 *
 * @implements \IteratorAggregate<int, object>
 */
final class FakeCollection implements \IteratorAggregate, \Countable
{
    /** @param array<int, object> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->rows);
    }

    public function count(): int
    {
        return count($this->rows);
    }
}

/**
 * Grupo `where(function ($q) { $q->where(...)->orWhereNull(...); })` — só o
 * subconjunto usado em produção (`DynamicTranslationRepository`, filtro
 * admin-only de custom field): uma condição `where($col,$val)` inicial,
 * seguida de `orWhereNull($col)`. Não é um builder genérico.
 */
final class FakeCapsuleWhereGroup
{
    /** @var array<int, array{bool:string, type:string, column:string, value:mixed}> */
    private array $conditions = [];

    public function where(string $column, mixed $value): self
    {
        $this->conditions[] = ['bool' => 'and', 'type' => 'eq', 'column' => $column, 'value' => $value];

        return $this;
    }

    public function orWhereNull(string $column): self
    {
        $this->conditions[] = ['bool' => 'or', 'type' => 'null', 'column' => $column, 'value' => null];

        return $this;
    }

    public function matches(object $row): bool
    {
        $result = null;
        foreach ($this->conditions as $condition) {
            $actual = $row->{$condition['column']} ?? null;
            $conditionResult = $condition['type'] === 'null'
                ? $actual === null
                : $actual == $condition['value'];

            if ($result === null) {
                $result = $conditionResult;
                continue;
            }

            $result = $condition['bool'] === 'or' ? ($result || $conditionResult) : ($result && $conditionResult);
        }

        return $result ?? true;
    }
}
