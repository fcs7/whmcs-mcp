<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Seam de EXECUÇÃO — SOMENTE LEITURA nesta tranche.
 *
 * A versão anterior expunha `insert()`/`update()` "para CRM-3 herdar pronto", e
 * a revisão fria mostrou o custo disso: era possível montar uma mutação com
 * `admin_id` arbitrário e chamar o port diretamente, sem passar pelo resolver
 * OAuth nem pelo repositório. A promessa "`admin_id` sempre vem do OAuth"
 * dependia de disciplina futura, não da estrutura.
 *
 * A superfície gravável foi REMOVIDA. Em CRM-1 não existe nenhum caminho de
 * driver que escreva, então não há o que contornar. CRM-3 introduz escrita
 * pelos métodos do repositório, que recebem username OAuth e dados SEM
 * `admin_id`, resolvendo a autoria internamente.
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
     * Total EXATO de linhas sob o mesmo filtro de um select — o que sustenta
     * `count`/`total`/`has_more` do contrato público de CRM-2.
     *
     * Continua sendo leitura: devolve um inteiro, nunca uma linha, e o filtro
     * aceito é o mesmo conjunto fechado de `CrmSelect`. A alternativa —
     * paginar até o fim para contar — seria justamente a "leitura ilimitada"
     * que o contrato proíbe.
     *
     * @throws CrmException
     */
    public function countRows(CrmCount $count): int;
}
