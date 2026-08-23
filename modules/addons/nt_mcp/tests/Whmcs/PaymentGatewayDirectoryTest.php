<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Whmcs\PaymentGatewayDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M4 + F3 — introspecção read-only dos gateways, usada para validar
 * `paymentmethod` ANTES do primeiro efeito financeiro, e para resolvê-lo ao
 * system name EXATO armazenado.
 */
class PaymentGatewayDirectoryTest extends TestCase
{
    protected function setUp(): void
    {
        FakeCapsule::reset();
    }

    protected function tearDown(): void
    {
        FakeCapsule::reset();
    }

    /** Diretório alimentado por resolver (equivale à projeção da coluna). */
    private function directory(array $rows): PaymentGatewayDirectory
    {
        $d = new PaymentGatewayDirectory();
        $d->setResolver(static fn() => $rows);

        return $d;
    }

    /**
     * Fake do Capsule: devolve linhas no MESMO formato do driver real
     * (objetos com a propriedade `gateway`), exercitando a desembalagem da
     * projeção — não só o atalho de array de strings.
     */
    private function directoryFromCapsuleRows(array $gatewayValues): PaymentGatewayDirectory
    {
        // Linhas no MESMO formato do driver (objetos com ->gateway), entregues
        // CRUAS ao diretório: a desembalagem exercitada é a de produção.
        $rows = array_map(static function ($value) {
            $row = new \stdClass();
            $row->gateway = $value;
            return $row;
        }, $gatewayValues);

        $d = new PaymentGatewayDirectory();
        $d->setResolver(static fn() => $rows);

        return $d;
    }

    // ---------------------------------------------------------------
    // Resolução canônica
    // ---------------------------------------------------------------

    public function test_resolves_exact_configured_gateway(): void
    {
        $this->assertSame('paypal', $this->directory(['banktransfer', 'paypal'])->resolve('paypal'));
    }

    /**
     * F3: casar case-insensitive é aceitável, mas o valor DEVOLVIDO tem de ser
     * o exato do banco — encaminhar a capitalização do chamador dependeria de
     * coerção não documentada do WHMCS.
     */
    public function test_case_insensitive_input_resolves_to_the_exact_stored_value(): void
    {
        $directory = $this->directory(['banktransfer']);

        $this->assertSame('banktransfer', $directory->resolve('BANKTRANSFER'));
        $this->assertSame('banktransfer', $directory->resolve('BankTransfer'));
        $this->assertSame('banktransfer', $directory->resolve('banktransfer'));
    }

    public function test_input_is_trimmed_before_resolution(): void
    {
        $this->assertSame('paypal', $this->directory(['paypal'])->resolve('  paypal  '));
    }

    public function test_rejects_unknown_gateway_and_lists_configured_ones(): void
    {
        try {
            $this->directory(['banktransfer', 'paypal'])->resolve('missing');
            $this->fail('deveria rejeitar gateway inexistente');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('"missing" is not a configured WHMCS payment gateway', $e->getMessage());
            $this->assertStringContainsString('banktransfer, paypal', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------
    // Input inválido — rejeitado antes de qualquer leitura
    // ---------------------------------------------------------------

    /** O caso reproduzido pela revisão: `paymentmethod=' '` passava. */
    #[DataProvider('invalidInputProvider')]
    public function test_rejects_syntactically_invalid_input(string $input): void
    {
        $consulted = false;
        $d = new PaymentGatewayDirectory();
        $d->setResolver(function () use (&$consulted) {
            $consulted = true;
            return ['paypal'];
        });

        try {
            $d->resolve($input);
            $this->fail("input inválido aceito: '{$input}'");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('gateway system name', $e->getMessage());
        }

        $this->assertFalse($consulted, 'input inválido nem deveria consultar o diretório');
    }

    public static function invalidInputProvider(): array
    {
        return [
            'vazio'            => [''],
            'só espaço'        => [' '],
            'tabs'             => ["\t"],
            'com espaço'       => ['bank transfer'],
            'com barra'        => ['../etc/passwd'],
            'com aspas'        => ["paypal'"],
            'com ponto'        => ['pay.pal'],
            'com hífen'        => ['bank-transfer'],
            'dígito inicial'   => ['1paypal'],
            'underscore ini'   => ['_paypal'],
        ];
    }

    public function test_field_name_appears_in_the_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('gateway_field "x"');

        $this->directory(['paypal'])->resolve('x', 'gateway_field');
    }

    // ---------------------------------------------------------------
    // Linhas inválidas invalidam a introspecção INTEIRA
    // ---------------------------------------------------------------

    /**
     * F3: descartar só a linha ruim deixaria um diretório parcial passar por
     * completo. Uma linha corrompida torna a lista não confiável.
     */
    #[DataProvider('invalidRowProvider')]
    public function test_invalid_row_invalidates_the_whole_directory(array $rows): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unreliable directory');

        $this->directory($rows)->resolve('paypal');
    }

    /**
     * A documentação oficial de gateway exige filename minúsculo começando por
     * letra. `PayPal`, `1paypal` e `_paypal` não são system names carregáveis —
     * se chegassem ao `UpdateInvoice`, a falha só apareceria DEPOIS da cotação
     * aceita, recriando a parcial que o F3 existe para evitar.
     */
    public static function invalidRowProvider(): array
    {
        return [
            'linha vazia'      => [['paypal', '']],
            'só espaço'        => [['paypal', ' ']],
            'espaço em branco' => [['paypal', "\t\n"]],
            'sintaxe inválida' => [['paypal', 'bank transfer']],
            'não-string'       => [['paypal', 123]],
            'null'             => [['paypal', null]],
            'maiúscula'        => [['paypal', 'PayPal']],
            'toda maiúscula'   => [['paypal', 'STRIPE']],
            'dígito inicial'   => [['paypal', '1paypal']],
            'underscore ini'   => [['paypal', '_paypal']],
            'hífen'            => [['paypal', 'bank-transfer']],
        ];
    }

    /**
     * A linha é validada CRUA. Aparar antes do regex fazia `' banktransfer '`
     * passar e chegar ao `UpdateInvoice` aparado — valor que não é o exato do
     * banco. Espaço em volta é coluna suja e invalida o diretório.
     */
    #[DataProvider('whitespaceRowProvider')]
    public function test_rows_with_surrounding_whitespace_invalidate_the_directory(string $row): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unreliable directory');

        $this->directory(['paypal', $row])->resolve('paypal');
    }

    public static function whitespaceRowProvider(): array
    {
        return [
            'espaço à esquerda'  => [' banktransfer'],
            'espaço à direita'   => ['banktransfer '],
            'espaço nos dois'    => [' banktransfer '],
            'newline à direita'  => ["banktransfer\n"],
            'tab à esquerda'     => ["\tbanktransfer"],
        ];
    }

    public function test_exact_duplicates_are_deduplicated(): void
    {
        $this->assertSame(['paypal', 'banktransfer'], $this->directory(['paypal', 'paypal', 'banktransfer'])->configuredGateways());
    }

    /**
     * Duas linhas que só diferem por capitalização são impossíveis agora — a
     * de maiúscula já é rejeitada pela sintaxe canônica, antes de virar
     * ambiguidade. De um jeito ou de outro, falha fechado.
     */
    public function test_case_differing_duplicates_fail_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unreliable directory');

        $this->directory(['PayPal', 'paypal'])->resolve('paypal');
    }

    public function test_exact_duplicates_of_a_canonical_name_are_accepted(): void
    {
        $this->assertSame('paypal', $this->directory(['paypal', 'paypal'])->resolve('paypal'));
    }

    // ---------------------------------------------------------------
    // Introspecção indisponível
    // ---------------------------------------------------------------

    public function test_empty_directory_fails_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no payment gateway is configured');

        $this->directory([])->resolve('paypal');
    }

    public function test_introspection_failure_fails_closed_without_leaking_driver_message(): void
    {
        $d = new PaymentGatewayDirectory();
        $d->setResolver(static function () {
            throw new \RuntimeException('SQLSTATE[28000] password=hunter2');
        });

        try {
            $d->resolve('paypal');
            $this->fail('deveria falhar fechado');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed to read configured payment gateways', $e->getMessage());
            // F2: mensagem do driver pode carregar credencial de conexão.
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------
    // Caminho REAL do Capsule — sem resolver injetado
    // ---------------------------------------------------------------

    public function test_reads_through_the_real_capsule_query_and_projects_only_gateway(): void
    {
        FakeCapsule::withGateways(['banktransfer', 'paypal']);

        $directory = new PaymentGatewayDirectory();

        $this->assertSame(['banktransfer', 'paypal'], $directory->configuredGateways());
        $this->assertSame(
            ['table(tblpaymentgateways)', 'select(gateway)', 'distinct()', 'get()'],
            FakeCapsule::$calls,
            'a consulta de produção precisa ser exatamente esta'
        );
        // A projeção nunca traz as colunas de credencial.
        $this->assertStringNotContainsString('sk_live', json_encode($directory->configuredGateways()));
    }

    public function test_resolves_through_the_real_capsule_query(): void
    {
        FakeCapsule::withGateways(['banktransfer']);

        $this->assertSame('banktransfer', (new PaymentGatewayDirectory())->resolve('BankTransfer'));
    }

    /** Driver fora do ar: falha fechada, sem propagar a mensagem. */
    public function test_capsule_failure_fails_closed_without_leaking(): void
    {
        FakeCapsule::$failure = new \RuntimeException('SQLSTATE[28000] password=hunter2SuperSecret');

        try {
            (new PaymentGatewayDirectory())->resolve('paypal');
            $this->fail('deveria falhar fechado');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed to read configured payment gateways', $e->getMessage());
            $this->assertStringNotContainsString('hunter2SuperSecret', $e->getMessage());
        }
    }

    /** Capsule indisponível (sem WHMCS) continua fail-closed. */
    public function test_without_whmcs_database_it_fails_closed(): void
    {
        // FakeCapsule desligado no setUp reproduz o ambiente sem banco.
        $this->expectException(\RuntimeException::class);

        (new PaymentGatewayDirectory())->resolve('paypal');
    }

    // ---------------------------------------------------------------
    // Projeção no formato do driver + cache
    // ---------------------------------------------------------------

    public function test_unwraps_capsule_style_rows(): void
    {
        $directory = $this->directoryFromCapsuleRows(['banktransfer', 'paypalcheckout']);

        $this->assertSame(['banktransfer', 'paypalcheckout'], $directory->configuredGateways());
        $this->assertSame('paypalcheckout', $directory->resolve('PayPalCheckout'));
    }

    public function test_resolver_is_only_consulted_once_per_instance(): void
    {
        $calls = 0;
        $d = new PaymentGatewayDirectory();
        $d->setResolver(static function () use (&$calls) {
            $calls++;
            return ['paypal'];
        });

        $d->resolve('paypal');
        $d->resolve('paypal');

        $this->assertSame(1, $calls, 'resultado deve ser cacheado por request');
    }
}
