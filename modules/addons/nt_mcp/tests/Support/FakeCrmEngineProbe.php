<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

use NtMcp\Crm\CrmEngineProbe;
use NtMcp\Crm\CrmSchemaFact;

/**
 * Probe de engine em memória, mesmo espírito de `FakeCrmSchemaProbe`: fechado
 * por construção, sem nenhuma noção de linha.
 */
final class FakeCrmEngineProbe implements CrmEngineProbe
{
    /** @var array<int, string> */
    public array $calls = [];

    private ?string $failureCorrelationId = null;

    /** @param array<string, bool> $innoDbByTable tabela => é InnoDB */
    public function __construct(private array $innoDbByTable = [])
    {
    }

    public function failWith(string $correlationId = 'deadbeef'): self
    {
        $this->failureCorrelationId = $correlationId;

        return $this;
    }

    public function isInnoDb(string $table): CrmSchemaFact
    {
        $this->calls[] = "isInnoDb({$table})";

        if ($this->failureCorrelationId !== null) {
            return CrmSchemaFact::unknown($this->failureCorrelationId);
        }

        return ($this->innoDbByTable[$table] ?? false)
            ? CrmSchemaFact::present()
            : CrmSchemaFact::absent();
    }
}
