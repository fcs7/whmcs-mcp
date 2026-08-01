<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

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
        $rows = array_map(static function ($value) {
            $row = new \stdClass();
            $row->gateway = $value;
            return $row;
        }, $gatewayValues);

        $d = new PaymentGatewayDirectory();
        $d->setResolver(static fn() => array_map(
            static fn(\stdClass $r) => $r->gateway,
            $rows
        ));

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
        $directory = $this->directory(['bankTransfer']);

        $this->assertSame('bankTransfer', $directory->resolve('BANKTRANSFER'));
        $this->assertSame('bankTransfer', $directory->resolve('banktransfer'));
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
            'vazio'       => [''],
            'só espaço'   => [' '],
            'tabs'        => ["\t"],
            'com espaço'  => ['bank transfer'],
            'com barra'   => ['../etc/passwd'],
            'com aspas'   => ["paypal'"],
            'com ponto'   => ['pay.pal'],
            'com hífen'   => ['bank-transfer'],
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

    public static function invalidRowProvider(): array
    {
        return [
            'linha vazia'      => [['paypal', '']],
            'só espaço'        => [['paypal', ' ']],
            'espaço em branco' => [['paypal', "\t\n"]],
            'sintaxe inválida' => [['paypal', 'bank transfer']],
            'não-string'       => [['paypal', 123]],
            'null'             => [['paypal', null]],
        ];
    }

    /** Linha com espaço em volta é limpa, não rejeitada. */
    public function test_rows_are_trimmed(): void
    {
        $this->assertSame(['paypal', 'banktransfer'], $this->directory(['  paypal ', "banktransfer\n"])->configuredGateways());
    }

    public function test_exact_duplicates_are_deduplicated(): void
    {
        $this->assertSame(['paypal', 'banktransfer'], $this->directory(['paypal', 'paypal', 'banktransfer'])->configuredGateways());
    }

    /**
     * Duas linhas que só diferem por capitalização tornam o canônico
     * indeterminável — não há como escolher qual mandar ao UpdateInvoice.
     */
    public function test_case_insensitive_duplicates_are_ambiguous_and_fail_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ambiguous');

        $this->directory(['PayPal', 'paypal'])->resolve('paypal');
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

    public function test_without_whmcs_capsule_it_fails_closed(): void
    {
        $this->assertFalse(
            class_exists('\WHMCS\Database\Capsule'),
            'pré-condição do teste: o suite não bootstrapa o Capsule do WHMCS'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Capsule unavailable');

        (new PaymentGatewayDirectory())->resolve('paypal');
    }

    // ---------------------------------------------------------------
    // Projeção no formato do driver + cache
    // ---------------------------------------------------------------

    public function test_unwraps_capsule_style_rows(): void
    {
        $directory = $this->directoryFromCapsuleRows(['banktransfer', 'PayPalCheckout']);

        $this->assertSame(['banktransfer', 'PayPalCheckout'], $directory->configuredGateways());
        $this->assertSame('PayPalCheckout', $directory->resolve('paypalcheckout'));
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
