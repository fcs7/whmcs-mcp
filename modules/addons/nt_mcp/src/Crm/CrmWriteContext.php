<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Prova de que TODAS as barreiras anteriores a um efeito passaram: schema das
 * capacidades exigidas verificado, gate de escrita liberado e identidade
 * administrativa resolvida.
 *
 * Só `MgCrmRepository::prepareWrite()` constrói um destes. Uma escrita que não
 * tenha um em mãos não passou pelas barreiras — é um invariante que o CRM-3
 * herda pronto, em vez de reimplementar em cada uma das quatro tools.
 */
final class CrmWriteContext
{
    public function __construct(
        public readonly int $adminId,
        public readonly string $timestamp,
    ) {
    }
}
