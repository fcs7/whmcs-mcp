<?php

declare(strict_types=1);

namespace NtMcp\Crm;

use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\AuditMetadata;
use NtMcp\Whmcs\Diagnostics;
use NtMcp\Whmcs\LocalApiClient;
use WHMCS\Database\Capsule;

/**
 * Execução real sobre o Capsule do WHMCS — apenas leitura.
 *
 * A projeção é SEMPRE explícita: `select($select->columns)` com a lista que o
 * `CrmSelect` validou. Não existe caminho que produza `SELECT *`, nem por
 * default nem por lista vazia — o value object recusa projeção vazia na
 * construção.
 *
 * Não há método de escrita aqui, e isso é estrutural: enquanto CRM-3 não
 * introduzir writes pelos métodos do repositório, nenhum executor deste addon
 * consegue gravar em `crm_*`, com ou sem `admin_id` forjado.
 *
 * Falha de driver não atravessa: vira `CrmException::downstream()` com
 * correlação, e o detalhe (categoria, classe, fingerprint) vai só para o
 * diagnóstico. A mensagem de um `PDOException` pode carregar credencial de
 * conexão, fragmento de SQL e valores da linha — nada disso pode chegar ao
 * chamador MCP nem ao Activity Log.
 */
final class CapsuleQueryPort implements CrmQueryPort
{
    public function selectRows(CrmSelect $select): array
    {
        LocalApiClient::auditLog(ActivityEvent::DB_SELECT, AuditMetadata::ids($select->auditIds()));

        try {
            $query = Capsule::table($select->table)->select($select->columns);

            foreach ($select->conditions as $column => $value) {
                $query->where($column, $value);
            }

            foreach ($select->inConditions as $column => $values) {
                $query->whereIn($column, $values);
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
        } catch (\Throwable $e) {
            throw $this->downstream($e);
        }
    }

    /**
     * `COUNT` sob o mesmo filtro do select correspondente. Nenhuma linha é
     * materializada: o driver devolve um escalar, então não existe aqui o
     * caminho de exfiltração que uma projeção teria.
     */
    public function countRows(CrmCount $count): int
    {
        LocalApiClient::auditLog(ActivityEvent::DB_SELECT, AuditMetadata::ids($count->auditIds()));

        try {
            $query = Capsule::table($count->table);

            foreach ($count->conditions as $column => $value) {
                $query->where($column, $value);
            }

            foreach ($count->nullColumns as $column) {
                $query->whereNull($column);
            }

            return max(0, (int) $query->count());
        } catch (\Throwable $e) {
            throw $this->downstream($e);
        }
    }

    /**
     * Fronteira ÚNICA em que a mensagem da causa é tocada, e só para virar
     * fingerprint. Centralizada para que um caminho novo não possa esquecer a
     * higienização — a mensagem de um `PDOException` pode carregar credencial
     * de conexão, fragmento de SQL e valores da linha.
     */
    private function downstream(\Throwable $e): CrmException
    {
        $correlationId = LocalApiClient::auditLog(ActivityEvent::DB_EXCEPTION);
        Diagnostics::log($correlationId, Diagnostics::CATEGORY_DB_EXCEPTION, 'crm_select', $e);

        return CrmException::downstream(
            $correlationId,
            Diagnostics::fingerprint($e->getMessage()),
            get_class($e)
        );
    }
}
