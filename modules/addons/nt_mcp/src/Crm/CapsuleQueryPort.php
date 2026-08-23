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
    private const SNAPSHOT_CONFIGURATION = 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY';

    /** A conexão fixa enquanto uma resposta CRM está sendo materializada. */
    private mixed $snapshotConnection = null;

    public function withinReadSnapshot(callable $operation): mixed
    {
        if ($this->snapshotConnection !== null) {
            // A reentrância não pode "adotar" a transação externa nem fechar
            // o snapshot que o chamador externo ainda está montando.
            throw $this->downstream(new \RuntimeException('nested read snapshot'));
        }

        $connection = null;
        $pdo = null;
        $beginInvoked = false;

        try {
            $connection = Capsule::connection();
            $pdo = $connection->getPdo();

            // Não é seguro herdar isolamento, read-only ou ownership de uma
            // transação que este boundary não abriu — nem no PDO nem no
            // contador que faz o Illuminate escolher o write PDO em selects.
            if ($pdo->inTransaction() || $connection->transactionLevel() !== 0) {
                throw new \RuntimeException('ambient transaction');
            }

            // `SET TRANSACTION` vale somente para a próxima transação. Assim
            // não contaminamos a conexão reaproveitada pelo WHMCS. A abertura
            // em si é pelo lifecycle do Illuminate: ele eleva transactionLevel
            // para 1 e força Query/Schema Builder a permanecer no write PDO.
            if ($pdo->exec(self::SNAPSHOT_CONFIGURATION) === false) {
                throw new \RuntimeException('snapshot configuration failed');
            }

            $beginInvoked = true;
            if ($connection->beginTransaction() === false) {
                throw new \RuntimeException('snapshot begin failed');
            }

            $this->assertOpen($connection, $pdo);
        } catch (\Throwable $e) {
            // Um begin parcial pode ter alterado só uma das duas camadas. Só
            // fazemos cleanup se esta chamada chegou a tentar abrir a visão;
            // um ambiente pré-existente é recusado sem tomar sua ownership.
            if ($beginInvoked && $connection !== null && $pdo !== null) {
                try {
                    $this->rollbackSnapshot($connection, $pdo);
                } catch (\Throwable $cleanup) {
                    throw $this->downstream($cleanup);
                }
            }

            throw $this->downstream($e);
        }

        $this->snapshotConnection = $connection;

        try {
            $result = $operation();
        } catch (CrmException $e) {
            try {
                $this->rollbackSnapshot($connection, $pdo);
            } catch (\Throwable $cleanup) {
                throw $this->downstream($cleanup);
            } finally {
                $this->snapshotConnection = null;
            }

            // O erro de domínio já é sanitizado e deve sobreviver quando o
            // rollback funcionou.
            throw $e;
        } catch (\Throwable $e) {
            try {
                $this->rollbackSnapshot($connection, $pdo);
            } catch (\Throwable $cleanup) {
                throw $this->downstream($cleanup);
            } finally {
                $this->snapshotConnection = null;
            }

            throw $this->downstream($e);
        }

        try {
            if ($connection->commit() === false) {
                throw new \RuntimeException('snapshot commit failed');
            }
            $this->assertClosed($connection, $pdo);
        } catch (\Throwable $e) {
            // `commit()` que falha pode deixar PDO e contador em estados
            // distintos. Refaça o cleanup pelo lifecycle e, só se o framework
            // já tiver perdido o nível, force o mesmo PDO capturado a fechar.
            try {
                $this->rollbackSnapshot($connection, $pdo);
            } catch (\Throwable $cleanup) {
                throw $this->downstream($cleanup);
            } finally {
                $this->snapshotConnection = null;
            }

            throw $this->downstream($e);
        }

        $this->snapshotConnection = null;

        return $result;
    }

    public function selectRows(CrmSelect $select): array
    {
        LocalApiClient::auditLog(ActivityEvent::DB_SELECT, AuditMetadata::ids($select->auditIds()));

        try {
            $query = $this->connection()->table($select->table)->select($select->columns);

            foreach ($select->conditions as $column => $value) {
                $query->where($column, $value);
            }

            foreach ($select->inConditions as $column => $values) {
                $query->whereIn($column, $values);
            }

            // Keyset: coluna e operadores são literais nossos, nunca do chamador.
            if ($select->afterId !== null) {
                $query->where(CrmSchema::COLUMN_ID, '>', $select->afterId);
            }

            if ($select->throughId !== null) {
                $query->where(CrmSchema::COLUMN_ID, '<=', $select->throughId);
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

    /** Schema builder na MESMA Connection capturada pela READ, sem tocar DB no wiring. */
    public function schemaBuilder(): mixed
    {
        return $this->connection()->getSchemaBuilder();
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
            $query = $this->connection()->table($count->table);

            foreach ($count->conditions as $column => $value) {
                $query->where($column, $value);
            }

            foreach ($count->inConditions as $column => $values) {
                $query->whereIn($column, $values);
            }

            if ($count->throughId !== null) {
                $query->where(CrmSchema::COLUMN_ID, '<=', $count->throughId);
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

    private function connection(): mixed
    {
        return $this->snapshotConnection ?? Capsule::connection();
    }

    private function isOpen(mixed $pdo): bool
    {
        try {
            return (bool) $pdo->inTransaction();
        } catch (\Throwable) {
            // Não há como provar que a sessão fechou: o caller recebe falha
            // sanitizada, nunca uma continuação sobre estado desconhecido.
            return true;
        }
    }

    private function assertOpen(mixed $connection, mixed $pdo): void
    {
        if (!$this->isOpen($pdo) || $connection->transactionLevel() !== 1) {
            throw new \RuntimeException('read snapshot begin state is inconsistent');
        }
    }

    private function assertClosed(mixed $connection, mixed $pdo): void
    {
        if ($this->isOpen($pdo) || $connection->transactionLevel() !== 0) {
            throw new \RuntimeException('read snapshot cleanup left a transaction open');
        }
    }

    private function rollbackSnapshot(mixed $connection, mixed $pdo): void
    {
        $frameworkFailure = null;

        try {
            if ($connection->transactionLevel() > 0 && $connection->rollBack() === false) {
                $frameworkFailure = new \RuntimeException('snapshot rollback failed');
            }
        } catch (\Throwable $e) {
            $frameworkFailure = $e;
        }

        // Illuminate pode diminuir o contador mesmo se o driver devolver
        // `false` no commit/rollback. Nesse caso não há nível para o framework
        // fechar, mas o PDO que esta chamada capturou ainda precisa ser limpo.
        if ($this->isOpen($pdo)) {
            try {
                if ($pdo->rollBack() === false) {
                    throw new \RuntimeException('snapshot PDO cleanup failed');
                }
            } catch (\Throwable $e) {
                throw $e;
            }
        }

        $this->assertClosed($connection, $pdo);

        if ($frameworkFailure !== null) {
            throw $frameworkFailure;
        }
    }
}
