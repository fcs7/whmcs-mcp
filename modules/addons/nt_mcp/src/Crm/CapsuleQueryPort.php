<?php

declare(strict_types=1);

namespace NtMcp\Crm;

use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\AuditMetadata;
use NtMcp\Whmcs\Diagnostics;
use NtMcp\Whmcs\LocalApiClient;
use WHMCS\Database\Capsule;

/**
 * Execução real sobre o Capsule do WHMCS.
 *
 * A projeção é SEMPRE explícita — `select($select->columns)` com a lista que o
 * `CrmSelect` validou. Não existe caminho que produza `SELECT *`, nem por
 * default nem por lista vazia: o value object recusa projeção vazia na
 * construção.
 *
 * Falha de driver não atravessa: vira `CrmException::downstream()` com
 * correlação, e o detalhe (categoria, classe, fingerprint) vai só para o
 * diagnóstico. A mensagem de um `PDOException` pode carregar credencial de
 * conexão, fragmento de SQL e valores da linha — nada disso pode chegar ao
 * chamador MCP nem ao Activity Log.
 */
final class CapsuleQueryPort implements CrmQueryPort
{
    public function __construct(private readonly CrmWriteGate $gate = new CrmWriteGate())
    {
    }

    public function selectRows(CrmSelect $select): array
    {
        LocalApiClient::auditLog(ActivityEvent::DB_SELECT, AuditMetadata::ids($select->auditIds()));

        return $this->run('SELECT', function () use ($select): array {
            $query = Capsule::table($select->table)->select($select->columns);

            foreach ($select->conditions as $column => $value) {
                $query->where($column, $value);
            }

            foreach ($select->nullColumns as $column) {
                $query->whereNull($column);
            }

            foreach ($select->order as [$column, $direction]) {
                $query->orderBy($column, $direction);
            }

            $rows = $query->skip($select->offset)->take($select->limit)->get();

            $result = [];
            foreach ($rows as $row) {
                $result[] = (array) $row;
            }

            return $result;
        });
    }

    public function insert(CrmMutation $mutation): int
    {
        $this->gate->assertWritable($mutation->auditIds());

        $correlationId = LocalApiClient::auditLog(
            ActivityEvent::DB_INSERT,
            AuditMetadata::ids($mutation->auditIds())
        );

        return $this->run('INSERT', static function () use ($mutation): int {
            return (int) Capsule::table($mutation->table)->insertGetId($mutation->values);
        }, $correlationId);
    }

    public function update(CrmMutation $mutation): int
    {
        $this->gate->assertWritable($mutation->auditIds());

        $correlationId = LocalApiClient::auditLog(
            ActivityEvent::DB_UPDATE,
            AuditMetadata::ids($mutation->auditIds())
        );

        return $this->run('UPDATE', static function () use ($mutation): int {
            $query = Capsule::table($mutation->table);

            foreach ($mutation->conditions as $column => $value) {
                $query->where($column, $value);
            }

            foreach ($mutation->nullConditions as $column) {
                $query->whereNull($column);
            }

            return (int) $query->update($mutation->values);
        }, $correlationId);
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function run(string $verb, callable $operation, ?string $correlationId = null): mixed
    {
        try {
            $outcome = $operation();
        } catch (\Throwable $e) {
            $correlationId = LocalApiClient::auditLog(ActivityEvent::DB_EXCEPTION, null, $correlationId);
            Diagnostics::log($correlationId, Diagnostics::CATEGORY_DB_EXCEPTION, 'crm_' . strtolower($verb), $e);

            throw CrmException::downstream(
                $correlationId,
                Diagnostics::fingerprint($e->getMessage()),
                get_class($e)
            );
        }

        if ($verb !== 'SELECT') {
            LocalApiClient::auditLog(ActivityEvent::DB_OK, null, $correlationId);
        }

        return $outcome;
    }
}
