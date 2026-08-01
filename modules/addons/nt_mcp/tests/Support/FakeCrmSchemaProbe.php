<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

use NtMcp\Crm\CrmSchemaProbe;

/**
 * Probe de metadata em memória. Fechado por construção: só responde sobre as
 * tabelas/colunas que o teste instalou, e não tem nenhuma noção de linha.
 */
final class FakeCrmSchemaProbe implements CrmSchemaProbe
{
    /** @var array<int, string> */
    public array $calls = [];

    /** @param array<string, array<int, string>> $tables tabela => colunas */
    public function __construct(private array $tables = [])
    {
    }

    /** Instalação completa e correta do contrato mgCRM2 esperado. */
    public static function healthy(): self
    {
        return new self(CrmSchemaFixture::completeInstallation());
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

    public function hasTable(string $table): bool
    {
        $this->calls[] = "hasTable({$table})";

        return array_key_exists($table, $this->tables);
    }

    public function hasColumn(string $table, string $column): bool
    {
        $this->calls[] = "hasColumn({$table},{$column})";

        return in_array($column, $this->tables[$table] ?? [], true);
    }
}
