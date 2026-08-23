<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Barreira de schema, por capacidade, ANTES de qualquer consulta operacional.
 *
 * Quatro desfechos, e só quatro:
 *
 *  - tabela mínima ausente            → `crm_unavailable`
 *  - tabela presente, coluna ausente  → `crm_schema_mismatch`
 *  - metadata indisponível (erro)     → `downstream`, com a correlação do probe
 *  - tudo presente                    → passa
 *
 * A distinção entre os dois primeiros é o que o operador precisa: "o addon não
 * está instalado" e "o addon está instalado numa versão que não corresponde ao
 * contrato" pedem ações diferentes. O terceiro existe porque uma falha do
 * driver não é nenhuma das duas — publicá-la como ausência levava caller e
 * operador à correção errada.
 *
 * Memorização por instância: só CONCLUSÕES entram no cache. Uma falha de
 * metadata é reavaliada na próxima pergunta, para que uma indisponibilidade
 * transitória não fique congelada pela request inteira.
 *
 * Não existe fallback: nenhuma tabela alternativa é tentada, em particular
 * nenhum nome do contrato fictício anterior.
 */
final class CrmSchemaGuard
{
    /** @var array<string, true|CrmException> apenas decisões conclusivas */
    private array $decided = [];

    public function __construct(private readonly CrmSchemaProbe $probe)
    {
    }

    /** @throws CrmException */
    public function assert(CrmCapability $capability): void
    {
        if (isset($this->decided[$capability->value])) {
            $decision = $this->decided[$capability->value];
            if ($decision instanceof CrmException) {
                throw $decision;
            }

            return;
        }

        // Uma decisão de metadata indisponível NÃO é memorizada: `decide()`
        // lança direto e nada é gravado.
        $decision = $this->decide($capability);

        $this->decided[$capability->value] = $decision;

        if ($decision instanceof CrmException) {
            throw $decision;
        }
    }

    /** Conveniência para quem precisa de várias capacidades de uma vez. */
    public function assertAll(CrmCapability ...$capabilities): void
    {
        foreach ($capabilities as $capability) {
            $this->assert($capability);
        }
    }

    /**
     * @return true|CrmException conclusão memorizável
     * @throws CrmException `downstream` quando a metadata é indisponível
     */
    private function decide(CrmCapability $capability): bool|CrmException // PHP 8.1: sem tipo `true` standalone; só devolve true
    {
        $requirements = CrmSchema::requirementsFor($capability);

        if ($requirements === []) {
            // Capacidade sem requisitos declarados seria um gate vazio: fecha.
            return CrmException::unavailable($capability);
        }

        foreach ($requirements as $table => $columns) {
            $tableFact = $this->probe->hasTable($table);

            if ($tableFact->isUnknown()) {
                throw CrmException::downstream((string) $tableFact->correlationId);
            }

            if ($tableFact->isAbsent()) {
                return CrmException::unavailable($capability);
            }

            foreach ($columns as $column) {
                $columnFact = $this->probe->hasColumn($table, $column);

                if ($columnFact->isUnknown()) {
                    throw CrmException::downstream((string) $columnFact->correlationId);
                }

                if ($columnFact->isAbsent()) {
                    return CrmException::schemaMismatch($capability);
                }
            }
        }

        return true;
    }
}
