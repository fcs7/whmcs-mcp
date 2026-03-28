<?php
// src/Whmcs/CapsuleClient.php
namespace NtMcp\Whmcs;

use WHMCS\Database\Capsule;

class CapsuleClient
{
    /**
     * Executa query em qualquer tabela WHMCS/modulo
     * @return array<int, array<string, mixed>>
     */
    public function select(
        string $table,
        array $where = [],
        array $columns = ['*'],
        int $limit = 100,
        int $offset = 0
    ): array {
        $query = Capsule::table($table)->select($columns);

        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return $query->skip($offset)->take($limit)->get()->map(fn($r) => (array) $r)->toArray();
    }

    public function insert(string $table, array $data): int
    {
        return Capsule::table($table)->insertGetId($data);
    }

    public function update(string $table, array $where, array $data): int
    {
        $query = Capsule::table($table);
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }
        return $query->update($data);
    }

    public function delete(string $table, array $where): int
    {
        $query = Capsule::table($table);
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }
        return $query->delete();
    }
}
