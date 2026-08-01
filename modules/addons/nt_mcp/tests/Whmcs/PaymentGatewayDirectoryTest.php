<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\PaymentGatewayDirectory;
use PHPUnit\Framework\TestCase;

/**
 * M4 — introspecção read-only dos gateways configurados, usada para validar
 * `paymentmethod` ANTES do primeiro efeito financeiro.
 */
class PaymentGatewayDirectoryTest extends TestCase
{
    private function directory(array $names): PaymentGatewayDirectory
    {
        $d = new PaymentGatewayDirectory();
        $d->setResolver(static fn() => $names);

        return $d;
    }

    public function test_accepts_configured_gateway(): void
    {
        $this->directory(['banktransfer', 'paypal'])->assertConfigured('paypal');
        $this->addToAssertionCount(1);
    }

    public function test_match_is_case_insensitive(): void
    {
        $this->directory(['bankTransfer'])->assertConfigured('BANKTRANSFER');
        $this->addToAssertionCount(1);
    }

    public function test_rejects_unknown_gateway_and_lists_configured_ones(): void
    {
        try {
            $this->directory(['banktransfer', 'paypal'])->assertConfigured('missing');
            $this->fail('deveria rejeitar gateway inexistente');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('"missing" is not a configured WHMCS payment gateway', $e->getMessage());
            $this->assertStringContainsString('banktransfer, paypal', $e->getMessage());
        }
    }

    public function test_field_name_appears_in_the_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('gateway_field "x"');

        $this->directory(['paypal'])->assertConfigured('x', 'gateway_field');
    }

    /** Sem gateway configurado não há como validar: fail-closed. */
    public function test_empty_directory_fails_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no payment gateway is configured');

        $this->directory([])->assertConfigured('paypal');
    }

    /** Erro de introspecção sobe — o chamador recusa a operação. */
    public function test_introspection_failure_propagates(): void
    {
        $d = new PaymentGatewayDirectory();
        $d->setResolver(static function () {
            throw new \RuntimeException('db down');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db down');

        $d->assertConfigured('paypal');
    }

    public function test_deduplicates_gateway_names(): void
    {
        $d = $this->directory(['paypal', 'paypal', 'banktransfer']);

        $this->assertSame(['paypal', 'banktransfer'], $d->configuredGateways());
    }

    public function test_resolver_is_only_consulted_once_per_instance(): void
    {
        $calls = 0;
        $d = new PaymentGatewayDirectory();
        $d->setResolver(static function () use (&$calls) {
            $calls++;
            return ['paypal'];
        });

        $d->assertConfigured('paypal');
        $d->assertConfigured('paypal');

        $this->assertSame(1, $calls, 'resultado deve ser cacheado por request');
    }

    /**
     * Sem WHMCS bootstrapado e sem resolver, a introspecção é indisponível —
     * e indisponível significa recusar, nunca "assumir que está tudo bem".
     */
    public function test_without_whmcs_capsule_it_fails_closed(): void
    {
        $this->assertFalse(
            class_exists('\WHMCS\Database\Capsule'),
            'pré-condição do teste: o suite não bootstrapa o Capsule do WHMCS'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Capsule unavailable');

        (new PaymentGatewayDirectory())->assertConfigured('paypal');
    }
}
