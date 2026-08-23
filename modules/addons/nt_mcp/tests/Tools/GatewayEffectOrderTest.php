<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tools\QuoteTools;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\PaymentGatewayDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F3 combinado: linha REAL vinda do Capsule + `QuoteTools`, provando que uma
 * linha fora da sintaxe canônica não produz efeito nenhum.
 *
 * Os testes anteriores exercitavam o diretório e a tool separadamente, então
 * nenhum deles provava a propriedade que importa — que a ordem
 * `GetQuotes → AcceptQuote → UpdateInvoice` nunca começa quando o diretório é
 * suspeito.
 */
class GatewayEffectOrderTest extends TestCase
{
    protected function setUp(): void
    {
        FakeCapsule::reset();
    }

    protected function tearDown(): void
    {
        FakeCapsule::reset();
    }

    /** @param array<int,string> $rows */
    private function toolsWithGatewayRows(array $rows, array &$calls): QuoteTools
    {
        FakeCapsule::withGateways($rows);

        $api = new LocalApiClient('testadmin');
        $api->setGates(['financial' => true]);
        $api->setCallable(function (string $cmd) use (&$calls) {
            $calls[] = $cmd;
            if ($cmd === 'GetQuotes') {
                return ['result' => 'success', 'quotes' => ['quote' => [['id' => 10, 'stage' => 'Delivered']]]];
            }
            return ['result' => 'success', 'invoiceid' => 99];
        });

        // Diretório REAL, sem resolver injetado: lê pelo Capsule fake.
        return new QuoteTools($api, new PaymentGatewayDirectory());
    }

    /**
     * Cada uma destas linhas foi reproduzida (ou é da mesma família) chegando a
     * `AcceptQuote` antes da correção.
     */
    #[DataProvider('poisonedRowProvider')]
    public function test_non_canonical_row_produces_zero_effects(string $row): void
    {
        $calls = [];
        $tools = $this->toolsWithGatewayRows(['banktransfer', $row], $calls);

        try {
            $tools->convertQuoteToInvoice(quoteid: 10, paymentmethod: 'banktransfer');
            $this->fail("linha '{$row}' deveria invalidar o diretório");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unreliable directory', $e->getMessage());
        }

        $this->assertSame([], $calls, "linha '{$row}' não pode deixar nenhum efeito rodar");
    }

    public static function poisonedRowProvider(): array
    {
        return [
            'espaço nos dois lados' => [' banktransfer '],
            'espaço à esquerda'     => [' banktransfer'],
            'espaço à direita'      => ['banktransfer '],
            'newline final'         => ["banktransfer\n"],
            'maiúscula'             => ['PayPal'],
            'toda maiúscula'        => ['STRIPE'],
            'dígito inicial'        => ['1paypal'],
            'underscore inicial'    => ['_paypal'],
            'hífen'                 => ['bank-transfer'],
            'ponto'                 => ['pay.pal'],
            'vazia'                 => [''],
        ];
    }

    /** Com o diretório íntegro, a ordem esperada acontece e usa o canônico. */
    public function test_canonical_row_allows_the_expected_effect_order(): void
    {
        $calls = [];
        $tools = $this->toolsWithGatewayRows(['banktransfer', 'paypal'], $calls);

        $tools->convertQuoteToInvoice(quoteid: 10, paymentmethod: 'BankTransfer');

        $this->assertSame(['GetQuotes', 'AcceptQuote', 'UpdateInvoice'], $calls);
    }

    /** Input sintaticamente inválido morre antes até de ler o diretório. */
    public function test_invalid_input_produces_zero_effects(): void
    {
        $calls = [];
        $tools = $this->toolsWithGatewayRows(['banktransfer'], $calls);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $tools->convertQuoteToInvoice(quoteid: 10, paymentmethod: ' ');
        } finally {
            $this->assertSame([], $calls);
            $this->assertSame([], FakeCapsule::$calls, 'nem o diretório deveria ser consultado');
        }
    }
}
