<?php
// src/Whmcs/CapsuleClient.php
namespace NtMcp\Whmcs;

use WHMCS\Database\Capsule;

class CapsuleClient
{
    // ---------------------------------------------------------------
    // SECURITY FIX (F2 -- CVSS 9.9): Strict table and column allowlists.
    //
    // Before this fix, select/insert/update/delete accepted ANY table
    // name, allowing an attacker who controls MCP tool parameters to
    // read or mutate tbladmins, tblconfiguration, or any other WHMCS
    // core table.
    // ---------------------------------------------------------------

    /** Tables the CRM module is permitted to access. */
    private const ALLOWED_TABLES = [
        'mod_mgcrm_contacts',
        'mod_mgcrm_followups',
        'mod_mgcrm_notes',
    ];

    /**
     * Columns that may be written (INSERT / UPDATE) per table.
     * SELECT always uses the allowlist for the WHERE clause keys,
     * but may project any stored column (read is lower risk than write).
     */
    private const ALLOWED_COLUMNS = [
        'mod_mgcrm_contacts' => [
            'type', 'name', 'email', 'phone', 'company',
            'notes', 'status', 'stage', 'created',
        ],
        'mod_mgcrm_followups' => [
            'contact_id', 'note', 'duedate', 'created',
        ],
        'mod_mgcrm_notes' => [
            'contact_id', 'note', 'created',
        ],
    ];

    /** Columns that may appear in WHERE clauses per table. */
    private const ALLOWED_WHERE_COLUMNS = [
        'mod_mgcrm_contacts'  => ['id', 'type', 'name', 'email', 'status', 'stage'],
        'mod_mgcrm_followups' => ['id', 'contact_id'],
        'mod_mgcrm_notes'     => ['id', 'contact_id'],
    ];

    // ---------------------------------------------------------------
    // SECURITY FIX (F16 -- LOW): Hard upper bound on query results.
    //
    // Prevents unbounded SELECT queries from exhausting memory or
    // being weaponised for data exfiltration.  Any caller-supplied
    // limit above MAX_QUERY_LIMIT is silently clamped.
    // ---------------------------------------------------------------
    private const MAX_QUERY_LIMIT = 500;

    // ---------------------------------------------------------------
    // Validation helpers
    // ---------------------------------------------------------------

    private function assertTableAllowed(string $table): void
    {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new \InvalidArgumentException(
                "CapsuleClient: access to table '{$table}' is not permitted."
            );
        }
    }

    /**
     * Validates that every key in $data is present in the column allowlist
     * for the given table and operation.
     *
     * @param array<string, mixed> $data
     * @param array<string>        $allowlist
     */
    private function assertColumnsAllowed(string $table, array $data, array $allowlist): void
    {
        $invalid = array_diff(array_keys($data), $allowlist);
        if ($invalid !== []) {
            throw new \InvalidArgumentException(
                sprintf(
                    "CapsuleClient: column(s) [%s] are not permitted for table '%s'.",
                    implode(', ', $invalid),
                    $table
                )
            );
        }
    }

    // ---------------------------------------------------------------
    // Public API (signatures unchanged for backwards compatibility)
    // ---------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function select(
        string $table,
        array $where = [],
        array $columns = ['*'],
        int $limit = 100,
        int $offset = 0
    ): array {
        $this->assertTableAllowed($table);
        if ($where !== []) {
            $this->assertColumnsAllowed($table, $where, self::ALLOWED_WHERE_COLUMNS[$table]);
        }

        // SECURITY FIX (F16): Clamp limit to prevent unbounded queries
        $limit = min(max($limit, 1), self::MAX_QUERY_LIMIT);

        // SECURITY FIX (F8): Audit log for DB reads
        LocalApiClient::auditLog("MCP DB SELECT: {$table}", [
            'where' => $where,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $query = Capsule::table($table)->select($columns);

        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return $query->skip($offset)->take($limit)->get()->map(fn($r) => (array) $r)->toArray();
    }

    public function insert(string $table, array $data): int
    {
        $this->assertTableAllowed($table);
        $this->assertColumnsAllowed($table, $data, self::ALLOWED_COLUMNS[$table]);

        // SECURITY FIX (F8): Audit log for DB writes
        LocalApiClient::auditLog("MCP DB INSERT: {$table}", $data);

        return Capsule::table($table)->insertGetId($data);
    }

    public function update(string $table, array $where, array $data): int
    {
        $this->assertTableAllowed($table);
        $this->assertColumnsAllowed($table, $where, self::ALLOWED_WHERE_COLUMNS[$table]);
        $this->assertColumnsAllowed($table, $data, self::ALLOWED_COLUMNS[$table]);

        // SECURITY FIX (F8): Audit log for DB mutations
        LocalApiClient::auditLog("MCP DB UPDATE: {$table}", [
            'where' => $where,
            'data' => $data,
        ]);

        $query = Capsule::table($table);
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }
        return $query->update($data);
    }

    public function delete(string $table, array $where): int
    {
        $this->assertTableAllowed($table);
        $this->assertColumnsAllowed($table, $where, self::ALLOWED_WHERE_COLUMNS[$table]);

        if ($where === []) {
            throw new \InvalidArgumentException(
                'CapsuleClient: DELETE without WHERE conditions is not permitted.'
            );
        }

        // SECURITY FIX (F8): Audit log for DB deletions
        LocalApiClient::auditLog("MCP DB DELETE: {$table}", ['where' => $where]);

        $query = Capsule::table($table);
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }
        return $query->delete();
    }
}
