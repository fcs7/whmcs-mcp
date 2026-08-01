<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Barreira de schema, por capacidade, ANTES de qualquer consulta operacional.
 *
 * Três desfechos, e só três:
 *
 *  - tabela mínima ausente            → `crm_unavailable`
 *  - tabela presente, coluna ausente  → `crm_schema_mismatch`
 *  - tudo presente                    → shape com as colunas opcionais provadas
 *
 * A distinção entre os dois primeiros é o que o operador precisa: "o addon não
 * está instalado" e "o addon está instalado numa versão que não corresponde ao
 * contrato" pedem ações diferentes. O chamador MCP recebe o código; nenhum nome
 * de tabela ou coluna atravessa.
 *
 * O resultado — inclusive a FALHA — é memorizado por instância. Um schema não
 * muda no meio de uma request, e repetir o probe a cada validação de catálogo
 * multiplicaria consultas de metadata sem alterar a resposta.
 *
 * Não existe fallback: nenhuma tabela alternativa é tentada, em particular
 * nenhum nome do contrato fictício anterior.
 */
final class CrmSchemaGuard
{
    /** @var array<string, CrmCapabilityShape|CrmException> */
    private array $decided = [];

    public function __construct(private readonly CrmSchemaProbe $probe)
    {
    }

    /** @throws CrmException */
    public function assert(CrmCapability $capability): CrmCapabilityShape
    {
        $decision = $this->decided[$capability->value] ??= $this->decide($capability);

        if ($decision instanceof CrmException) {
            throw $decision;
        }

        return $decision;
    }

    /** Conveniência para quem precisa de várias capacidades de uma vez. */
    public function assertAll(CrmCapability ...$capabilities): void
    {
        foreach ($capabilities as $capability) {
            $this->assert($capability);
        }
    }

    public function isAvailable(CrmCapability $capability): bool
    {
        try {
            $this->assert($capability);

            return true;
        } catch (CrmException) {
            return false;
        }
    }

    private function decide(CrmCapability $capability): CrmCapabilityShape|CrmException
    {
        $requirements = CrmSchema::requirementsFor($capability);

        if ($requirements === []) {
            // Capacidade sem requisitos declarados seria um gate vazio: fecha.
            return CrmException::unavailable($capability);
        }

        foreach ($requirements as $table => $columns) {
            if (!$this->probe->hasTable($table)) {
                return CrmException::unavailable($capability);
            }

            foreach ($columns as $column) {
                if (!$this->probe->hasColumn($table, $column)) {
                    return CrmException::schemaMismatch($capability);
                }
            }
        }

        $optional = [];
        foreach (CrmSchema::optionalColumnsFor($capability) as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->probe->hasColumn($table, $column)) {
                    $optional[$table][] = $column;
                }
            }
        }

        return new CrmCapabilityShape($capability, $optional);
    }
}
