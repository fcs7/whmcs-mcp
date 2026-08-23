<?php
// tests/Whmcs/CustomFieldDirectoryTest.php
namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\CustomFieldDirectory;
use PHPUnit\Framework\TestCase;

class CustomFieldDirectoryTest extends TestCase
{
    public function test_labels_come_from_rows_in_either_array_or_object_shape(): void
    {
        $directory = new CustomFieldDirectory();
        $directory->setResolver(static fn() => [
            ['id' => 4, 'fieldname' => 'CPF/CNPJ', 'fieldtype' => 'text'],
            (object) ['id' => 134, 'fieldname' => 'Inscrição Estadual', 'fieldtype' => 'text'],
        ]);

        $this->assertSame(['name' => 'CPF/CNPJ', 'type' => 'text'], $directory->labelFor(4));
        $this->assertSame(['name' => 'Inscrição Estadual', 'type' => 'text'], $directory->labelFor(134));
        $this->assertNull($directory->labelFor(999));
        $this->assertNull($directory->labelFor(0));
    }

    /** O admin do WHMCS aceita HTML no nome do campo; o rótulo é lido como texto. */
    public function test_html_in_the_field_name_is_reduced_to_text(): void
    {
        $directory = new CustomFieldDirectory();
        $directory->setResolver(static fn() => [
            ['id' => 1, 'fieldname' => '<b>CPF</b>&nbsp;do titular', 'fieldtype' => 'text'],
        ]);

        $this->assertSame('CPF do titular', $directory->labelFor(1)['name']);
    }

    public function test_unusable_rows_are_skipped_without_dropping_the_good_ones(): void
    {
        $directory = new CustomFieldDirectory();
        $directory->setResolver(static fn() => [
            ['id' => 0, 'fieldname' => 'id inválido'],
            ['id' => 2, 'fieldname' => '   '],
            ['id' => 3, 'fieldname' => 'Contrato'],
            'linha que não é linha',
        ]);

        $this->assertNull($directory->labelFor(0));
        $this->assertNull($directory->labelFor(2));
        $this->assertSame('Contrato', $directory->labelFor(3)['name']);
        $this->assertSame('text', $directory->labelFor(3)['type'], 'fieldtype ausente vira text');
    }

    /**
     * Falha SUAVE: este diretório só enriquece uma leitura. Derrubar
     * `whmcs_get_client` inteiro por causa de um rótulo trocaria inconveniente
     * por indisponibilidade.
     */
    public function test_a_failing_source_yields_no_labels_instead_of_an_exception(): void
    {
        $directory = new CustomFieldDirectory();
        $directory->setResolver(static function (): array {
            throw new \RuntimeException('db down');
        });

        $this->assertSame([], $directory->fields());
        $this->assertNull($directory->labelFor(4));
    }

    public function test_source_is_read_once_per_request(): void
    {
        $calls = 0;
        $directory = new CustomFieldDirectory();
        $directory->setResolver(static function () use (&$calls): array {
            $calls++;
            return [['id' => 4, 'fieldname' => 'CPF', 'fieldtype' => 'text']];
        });

        $directory->labelFor(4);
        $directory->labelFor(4);
        $directory->fields();

        $this->assertSame(1, $calls);
    }
}
