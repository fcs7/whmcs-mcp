<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

use NtMcp\Crm\CrmSchemaFact;
use NtMcp\Crm\CrmSchemaProbe;

/**
 * Probe de metadata em memória. Fechado por construção: só responde sobre as
 * tabelas/colunas que o teste instalou, e não tem nenhuma noção de linha.
 *
 * `failWith()` injeta o TERCEIRO estado — metadata indisponível — que precisa
 * ser distinguível de ausência real.
 */
final class FakeCrmSchemaProbe implements CrmSchemaProbe
{
    /** @var array<int, string> */
    public array $calls = [];

    private ?string $failureCorrelationId = null;

    /** @param array<string, array<int, string>> $tables tabela => colunas */
    public function __construct(private array $tables = [])
    {
    }

    /** Instalação completa e correta do contrato mgCRM2 esperado. */
    public static function healthy(): self
    {
        return new self(CrmSchemaFixture::completeInstallation());
    }

    /** Passa a responder `unknown` — driver de metadata fora do ar. */
    public function failWith(string $correlationId = 'deadbeef'): self
    {
        $this->failureCorrelationId = $correlationId;

        return $this;
    }

    public function dropTable(string $table): self
    {
        unset($this->tables[$table]);

        return $this;
    }

    public function dropColumn(string $table, string $column): self
    {
        $this->tables[$table] = array_values(array_filter(
            $this->tables[$table] ?? [],
            static fn(string $name): bool => $name !== $column
        ));

        return $this;
    }

    public function hasTable(string $table): CrmSchemaFact
    {
        $this->calls[] = "hasTable({$table})";

        if ($this->failureCorrelationId !== null) {
            return CrmSchemaFact::unknown($this->failureCorrelationId);
        }

        return array_key_exists($table, $this->tables)
            ? CrmSchemaFact::present()
            : CrmSchemaFact::absent();
    }

    public function hasColumn(string $table, string $column): CrmSchemaFact
    {
        $this->calls[] = "hasColumn({$table},{$column})";

        if ($this->failureCorrelationId !== null) {
            return CrmSchemaFact::unknown($this->failureCorrelationId);
        }

        return in_array($column, $this->tables[$table] ?? [], true)
            ? CrmSchemaFact::present()
            : CrmSchemaFact::absent();
    }
}
