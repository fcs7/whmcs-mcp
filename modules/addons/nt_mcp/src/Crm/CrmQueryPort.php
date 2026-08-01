<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Seam de EXECUÇÃO. Só aceita consultas e mutações já fechadas — não existe
 * assinatura que receba nome de tabela, coluna ou operador solto.
 *
 * Toda implementação deve converter falha de driver em
 * `CrmException::downstream()`, sem transportar mensagem, SQL ou path.
 */
interface CrmQueryPort
{
    /**
     * @return array<int, array<string, mixed>> linhas já projetadas
     * @throws CrmException
     */
    public function selectRows(CrmSelect $select): array;

    /**
     * @return int id gerado
     * @throws CrmException
     */
    public function insert(CrmMutation $mutation): int;

    /**
     * @return int linhas afetadas
     * @throws CrmException
     */
    public function update(CrmMutation $mutation): int;
}
