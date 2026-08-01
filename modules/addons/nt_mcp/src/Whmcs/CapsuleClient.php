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

    /** Override do gate de escrita para testes (null = usa config WHMCS). */
    private ?bool $writableOverride = null;
    public function setWritableForTests(bool $writable): void { $this->writableOverride = $writable; }

    /**
     * Bloqueio de write no Capsule agora é AUDITADO (m1): antes, um write CRM
     * negado não deixava rastro nenhum no Activity Log, porque esta rota nunca
     * passa por LocalApiClient::call(). Reusa a mesma redação central.
     */
    private function assertWritable(string $operation, string $table, array $context = []): void
    {
        if ($this->writableOverride !== null) {
            if (!$this->writableOverride) {
                $this->denyWrite($operation, $table, $context);
            }
            return;
        }
        // Espelha LocalApiClient::gateEnabled(): o gate WRITE tem default
        // DESLIGADO no rollout, e uma falha de leitura da config cai no mesmo
        // default (fail-closed).
        if ($this->isReadonly() || !$this->boolCfg('nt_mcp_enable_write', false)) {
            $this->denyWrite($operation, $table, $context);
        }
    }

    private function denyWrite(string $operation, string $table, array $context): never
    {
        LocalApiClient::auditLog("MCP BLOCKED DB {$operation}: {$table} (read-only / write gate)", $context);

        throw new \InvalidArgumentException('CapsuleClient: writes disabled (read-only / write gate).');
    }

    /**
     * readonly master switch — FAIL-CLOSED em três frentes, pelo mesmo
     * ConfigFlag do LocalApiClient: falha de leitura bloqueia; ausência usa o
     * default decidido; e valor PRESENTE porém não canônico (`'true'`, `'yes'`,
     * `'garbage'`, `2`) bloqueia escrita e é auditado.
     */
    private function isReadonly(): bool
    {
        // Fora de um WHMCS bootstrapado (ex.: testes) não há config a proteger.
        // Sob WHMCS, uma falha de leitura cai no catch e falha FECHADO.
        if (!class_exists('\WHMCS\Config\Setting')) {
            return false;
        }
        try {
            $raw = \WHMCS\Config\Setting::getValue('nt_mcp_readonly');
        } catch (\Throwable $e) {
            self::auditConfig('NT MCP CapsuleClient: readonly config read failed — failing closed: ' . $e->getMessage());
            return true;
        }

        return ConfigFlag::parse($raw)->resolve(
            default: false,
            failClosed: true,
            key: 'nt_mcp_readonly',
            auditor: self::auditConfig(...),
        );
    }

    private function boolCfg(string $key, bool $default): bool
    {
        try {
            $raw = \WHMCS\Config\Setting::getValue($key);
        } catch (\Throwable $e) {
            return $default;
        }

        return ConfigFlag::parse($raw)->resolve(
            default: $default,
            failClosed: false,
            key: $key,
            auditor: self::auditConfig(...),
        );
    }

    private static function auditConfig(string $message): void
    {
        error_log($message);
        LocalApiClient::auditLog($message, []);
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
        $this->assertWritable('INSERT', $table, $data);
        $this->assertColumnsAllowed($table, $data, self::ALLOWED_COLUMNS[$table]);

        // SECURITY FIX (F8): Audit log for DB writes
        $correlationId = LocalApiClient::auditLog("MCP DB INSERT: {$table}", $data);

        return $this->runWithOutcome(
            'INSERT',
            $table,
            $correlationId,
            static fn(): int => Capsule::table($table)->insertGetId($data)
        );
    }

    public function update(string $table, array $where, array $data): int
    {
        $this->assertTableAllowed($table);
        $this->assertWritable('UPDATE', $table, ['where' => $where, 'data' => $data]);
        $this->assertColumnsAllowed($table, $where, self::ALLOWED_WHERE_COLUMNS[$table]);
        $this->assertColumnsAllowed($table, $data, self::ALLOWED_COLUMNS[$table]);

        // SECURITY FIX (F8): Audit log for DB mutations
        $correlationId = LocalApiClient::auditLog("MCP DB UPDATE: {$table}", [
            'where' => $where,
            'data' => $data,
        ]);

        return $this->runWithOutcome('UPDATE', $table, $correlationId, static function () use ($table, $where, $data): int {
            $query = Capsule::table($table);
            foreach ($where as $column => $value) {
                $query->where($column, $value);
            }
            return $query->update($data);
        });
    }

    public function delete(string $table, array $where): int
    {
        $this->assertTableAllowed($table);
        $this->assertWritable('DELETE', $table, ['where' => $where]);
        $this->assertColumnsAllowed($table, $where, self::ALLOWED_WHERE_COLUMNS[$table]);

        if ($where === []) {
            throw new \InvalidArgumentException(
                'CapsuleClient: DELETE without WHERE conditions is not permitted.'
            );
        }

        // SECURITY FIX (F8): Audit log for DB deletions
        $correlationId = LocalApiClient::auditLog("MCP DB DELETE: {$table}", ['where' => $where]);

        return $this->runWithOutcome('DELETE', $table, $correlationId, static function () use ($table, $where): int {
            $query = Capsule::table($table);
            foreach ($where as $column => $value) {
                $query->where($column, $value);
            }
            return $query->delete();
        });
    }

    /**
     * m1.1: as três mutações registravam apenas o início. Agora toda mutação
     * emite desfecho — OK com a contagem de linhas afetadas, ou EXCEPTION — sem
     * repetir os dados (já gravados, redigidos, na linha de início) e sem levar
     * a mensagem do driver ao Activity Log (F2: pode carregar credencial de
     * conexão). O detalhe redigido vai só para o error_log.
     *
     * `protected` (e não `private`) apenas para permitir exercitar sucesso e
     * exceção sem um WHMCS bootstrapado; o comportamento é idêntico.
     *
     * @param callable():int $operation
     */
    protected function runWithOutcome(string $verb, string $table, string $correlationId, callable $operation): int
    {
        try {
            $affected = $operation();
        } catch (\Throwable $e) {
            LocalApiClient::auditLog("MCP DB {$verb} EXCEPTION: {$table}", [], $correlationId);
            error_log(sprintf(
                '[NT-MCP] [corr:%s] DB %s %s: %s',
                $correlationId,
                $verb,
                $table,
                TextRedactor::scrub($e->getMessage())
            ));
            throw $e;
        }

        LocalApiClient::auditLog("MCP DB {$verb} OK: {$table} (rows: {$affected})", [], $correlationId);

        return $affected;
    }
}
