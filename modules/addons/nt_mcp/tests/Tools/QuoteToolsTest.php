<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\QuoteTools;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\PaymentGatewayDirectory;
use PHPUnit\Framework\TestCase;

class QuoteToolsTest extends TestCase
{
    protected function setUp(): void
    {
        \NtMcp\Tests\Support\WhmcsDateFormat::reset();
    }

    protected function tearDown(): void
    {
        \NtMcp\Tests\Support\WhmcsDateFormat::reset();
    }

    /**
     * Os gates nascem DESLIGADOS. Cada teste declara a classe de efeito que
     * exercita: WRITE para criar/atualizar/duplicar, FINANCIAL para a conversão
     * e DESTRUCTIVE para a exclusão.
     */
    private function makeTools(
        ?callable $callable = null,
        array $gates = ['write' => true],
        ?PaymentGatewayDirectory $gateways = null
    ): QuoteTools {
        $api = new LocalApiClient('testadmin');
        $api->setGates($gates);
        $api->setCallable($callable ?? function (string $cmd, array $params) {
            return ['result' => 'success'];
        });

        return new QuoteTools($api, $gateways ?? self::gatewayDirectory());
    }

    /** Diretório de gateways com os system names configurados no "WHMCS". */
    private static function gatewayDirectory(array $names = ['banktransfer', 'paypal']): PaymentGatewayDirectory
    {
        $directory = new PaymentGatewayDirectory();
        $directory->setResolver(static fn() => $names);

        return $directory;
    }

    /** Resposta GetQuotes de uma cotação ainda não aceita. */
    private static function quoteResponse(int $id = 10, string $stage = 'Delivered', array $extra = []): array
    {
        return [
            'result' => 'success',
            'quotes' => ['quote' => [array_merge([
                'id' => $id,
                'userid' => 3,
                'subject' => 'Original quote',
                'stage' => $stage,
                'validuntil' => '2026-08-01',
                'datecreated' => '2026-07-01',
                'currency' => 1,
                'proposal' => 'Original proposal',
                'customernotes' => 'Customer note',
                'adminnotes' => 'Admin note',
            ], $extra)]],
        ];
    }

    // ---------------------------------------------------------------
    // Lineitems (WRITE)
    // ---------------------------------------------------------------

    public function test_create_quote_serializes_lineitems_for_whmcs_localapi(): void
    {
        $captured = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$captured) {
            $captured = ['cmd' => $cmd, 'params' => $params];
            return ['result' => 'success', 'quoteid' => 10];
        });

        $tools->createQuote(
            subject: 'New quote',
            stage: 'Draft',
            proposal: 'Proposal',
            validuntil: '2026-09-01',
            userid: 7,
            currencyid: 2,
            lineitems: [[
                'description' => 'Setup',
                'quantity' => 1,
                'unitprice' => 100.0,
                'discount' => 0,
                'taxable' => false,
            ]]
        );

        $this->assertSame('CreateQuote', $captured['cmd']);
        $this->assertSame(2, $captured['params']['currency']);
        $this->assertArrayNotHasKey('currencyid', $captured['params']);

        $lineitems = unserialize(base64_decode($captured['params']['lineitems']));
        $this->assertSame([[
            'desc' => 'Setup',
            'qty' => 1,
            'up' => 100.0,
            'discount' => 0,
            'taxable' => false,
        ]], $lineitems);
    }

    public function test_update_quote_accepts_existing_lineitem_id(): void
    {
        $captured = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$captured) {
            $captured = ['cmd' => $cmd, 'params' => $params];
            return ['result' => 'success'];
        });

        $tools->updateQuote(quoteid: 3, lineitems: [[
            'id' => 9,
            'description' => 'Updated service',
            'quantity' => 2,
            'unitprice' => 50,
            'discount' => 5,
            'taxable' => true,
        ]]);

        $this->assertSame('UpdateQuote', $captured['cmd']);
        $lineitems = unserialize(base64_decode($captured['params']['lineitems']));
        $this->assertSame([[
            'id' => 9,
            'desc' => 'Updated service',
            'qty' => 2,
            'up' => 50,
            'discount' => 5,
            'taxable' => true,
        ]], $lineitems);
    }

    public function test_update_quote_rejects_unknown_lineitem_key(): void
    {
        $tools = $this->makeTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not an allowed key');

        $tools->updateQuote(quoteid: 3, lineitems: [['description' => 'Item', 'amount' => 10]]);
    }

    public function test_create_quote_blocked_when_write_gate_off(): void
    {
        $tools = $this->makeTools(null, []); // defaults de produção

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('class WRITE disabled');

        $tools->createQuote(subject: 'x', stage: 'Draft', proposal: 'p', validuntil: '2026-09-01');
    }

    // ---------------------------------------------------------------
    // duplicate_quote (WRITE)
    // ---------------------------------------------------------------

    public function test_duplicate_quote_fetches_and_creates_copy_with_overrides(): void
    {
        $calls = [];
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$calls) {
            $calls[] = ['cmd' => $cmd, 'params' => $params];

            if ($cmd === 'GetQuotes') {
                return self::quoteResponse(extra: [
                    'lineitems' => [
                        'lineitem' => [[
                            'id' => 44,
                            'description' => 'Hosting',
                            'quantity' => 1,
                            'unitprice' => '100.00',
                            'discount' => '0.00',
                            'taxable' => '0',
                        ]],
                    ],
                ]);
            }

            return ['result' => 'success', 'quoteid' => 11];
        });

        $json = $tools->duplicateQuote(
            quoteid: 10,
            subject: 'Copied quote',
            stage: 'Draft',
            userid: 4,
            adminnotes: 'New admin note'
        );
        $result = json_decode($json, true);

        $this->assertSame('success', $result['result']);
        $this->assertSame('GetQuotes', $calls[0]['cmd']);
        $this->assertSame(['quoteid' => 10], $calls[0]['params']);
        $this->assertSame('CreateQuote', $calls[1]['cmd']);

        $createParams = $calls[1]['params'];
        $this->assertSame('Copied quote', $createParams['subject']);
        $this->assertSame('Draft', $createParams['stage']);
        $this->assertSame(4, $createParams['userid']);
        $this->assertSame('Original proposal', $createParams['proposal']);
        $this->assertSame('Customer note', $createParams['customernotes']);
        $this->assertSame('New admin note', $createParams['adminnotes']);
        // CreateQuote exige data LOCALIZADA — inclusive nos valores HERDADOS de
        // GetQuotes, que responde em Y-m-d. O stub usa DD/MM/YYYY.
        $this->assertSame('01/08/2026', $createParams['validuntil']);
        $this->assertSame('01/07/2026', $createParams['datecreated']);
        $this->assertSame(1, $createParams['currency']);

        $lineitems = unserialize(base64_decode($createParams['lineitems']));
        $this->assertArrayNotHasKey('id', $lineitems[0]);
        $this->assertSame('Hosting', $lineitems[0]['desc']);
    }

    public function test_duplicate_quote_returns_error_when_source_and_override_validuntil_are_empty(): void
    {
        $calls = [];
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$calls) {
            $calls[] = ['cmd' => $cmd, 'params' => $params];

            if ($cmd === 'GetQuotes') {
                // Zero-date nulificada por ResponseRedactor: `validuntil`
                // simplesmente ausente do payload de origem.
                return self::quoteResponse(extra: ['validuntil' => null]);
            }

            return ['result' => 'success', 'quoteid' => 11];
        });

        $json = $tools->duplicateQuote(quoteid: 10);
        $result = json_decode($json, true);

        $this->assertSame('error', $result['result']);
        $this->assertSame(10, $result['quoteid']);
        $this->assertStringContainsString('validuntil', $result['message']);
        // Falha ANTES do segundo efeito — nunca chama CreateQuote sem validuntil.
        $this->assertSame(['GetQuotes'], array_column($calls, 'cmd'));
    }

    public function test_duplicate_quote_returns_error_when_quote_not_found(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return ['result' => 'success', 'quotes' => ['quote' => []]];
        });

        $json = $tools->duplicateQuote(quoteid: 999);
        $result = json_decode($json, true);

        $this->assertSame('error', $result['result']);
        $this->assertSame(999, $result['quoteid']);
        $this->assertSame('Quote not found', $result['message']);
    }

    // ---------------------------------------------------------------
    // convert_quote_to_invoice (FINANCIAL)
    // ---------------------------------------------------------------

    private function makeConverter(callable $callable, ?PaymentGatewayDirectory $gateways = null): QuoteTools
    {
        return $this->makeTools($callable, ['financial' => true], $gateways);
    }

    public function test_convert_quote_to_invoice_accepts_quote(): void
    {
        $calls = [];
        $tools = $this->makeConverter(function (string $cmd, array $params) use (&$calls) {
            $calls[] = ['cmd' => $cmd, 'params' => $params];
            if ($cmd === 'GetQuotes') {
                return self::quoteResponse();
            }
            return ['result' => 'success', 'invoiceid' => 99];
        });

        $json = $tools->convertQuoteToInvoice(quoteid: 10);
        $result = json_decode($json, true);

        $this->assertSame(['GetQuotes', 'AcceptQuote'], array_column($calls, 'cmd'));
        $this->assertSame(['quoteid' => 10], $calls[1]['params']);
        $this->assertSame('success', $result['result']);
        $this->assertSame(10, $result['quoteid']);
        $this->assertSame(99, $result['invoiceid']);
    }

    public function test_convert_quote_to_invoice_updates_invoice_options(): void
    {
        $calls = [];
        $tools = $this->makeConverter(function (string $cmd, array $params) use (&$calls) {
            $calls[] = ['cmd' => $cmd, 'params' => $params];
            if ($cmd === 'GetQuotes') {
                return self::quoteResponse();
            }
            if ($cmd === 'AcceptQuote') {
                return ['result' => 'success', 'invoiceid' => 99];
            }

            return ['result' => 'success'];
        });

        $tools->convertQuoteToInvoice(
            quoteid: 10,
            paymentmethod: 'banktransfer',
            duedate: '2026-08-10',
            taxrate: 12.5
        );

        $this->assertSame('UpdateInvoice', $calls[2]['cmd']);
        $this->assertSame([
            'invoiceid' => 99,
            'paymentmethod' => 'banktransfer',
            'duedate' => '2026-08-10',
            'taxrate' => 12.5,
        ], $calls[2]['params']);
    }

    /** Decisão fechada: a conversão não produz efeito COMMS. */
    public function test_convert_quote_to_invoice_never_sends_email(): void
    {
        $calls = [];
        $tools = $this->makeConverter(function (string $cmd, array $params) use (&$calls) {
            $calls[] = ['cmd' => $cmd, 'params' => $params];
            if ($cmd === 'GetQuotes') {
                return self::quoteResponse();
            }
            if ($cmd === 'AcceptQuote') {
                return ['result' => 'success', 'invoiceid' => 99];
            }
            return ['result' => 'success'];
        });

        $tools->convertQuoteToInvoice(quoteid: 10, paymentmethod: 'banktransfer');

        foreach ($calls as $call) {
            $this->assertArrayNotHasKey('publishandsendemail', $call['params']);
            $this->assertArrayNotHasKey('sendinvoice', $call['params']);
        }
    }

    public function test_convert_quote_to_invoice_has_no_sendinvoice_parameter(): void
    {
        $params = (new \ReflectionMethod(QuoteTools::class, 'convertQuoteToInvoice'))->getParameters();
        $names = array_map(fn(\ReflectionParameter $p) => $p->getName(), $params);

        $this->assertSame(['quoteid', 'paymentmethod', 'duedate', 'taxrate'], $names);
    }

    public function test_convert_quote_to_invoice_reports_partial_update_error(): void
    {
        $tools = $this->makeConverter(function (string $cmd, array $params) {
            if ($cmd === 'GetQuotes') {
                return self::quoteResponse();
            }
            if ($cmd === 'AcceptQuote') {
                return ['result' => 'success', 'invoiceid' => 99];
            }

            return ['result' => 'error', 'message' => 'Invalid payment method'];
        });

        // O gateway é válido (M4 rejeitaria um inválido antes do efeito); a
        // falha vem do WHMCS no segundo passo, que é o cenário parcial legítimo.
        $json = $tools->convertQuoteToInvoice(quoteid: 10, paymentmethod: 'banktransfer');
        $result = json_decode($json, true);

        $this->assertSame('error', $result['result']);
        $this->assertTrue($result['partial'], 'falha após efeito parcial deve ser sinalizada');
        $this->assertSame(10, $result['quoteid']);
        $this->assertSame(99, $result['invoiceid']);
        $this->assertStringContainsString('Quote converted, but invoice update failed', $result['message']);
        $this->assertStringContainsString('NÃO repetir', $result['warning']);
        // F2: a mensagem downstream do WHMCS não é mais ecoada no payload MCP.
        $this->assertArrayNotHasKey('invoice_update', $result);
        $this->assertStringNotContainsString('Invalid payment method', json_encode($result));
        $this->assertNotEmpty($result['correlation_id']);
    }

    // ---------------------------------------------------------------
    // M2 — exceção/estado indeterminado depois do primeiro efeito
    // ---------------------------------------------------------------

    public function test_convert_reports_partial_when_update_invoice_throws(): void
    {
        $tools = $this->makeConverter(function (string $cmd) {
            if ($cmd === 'GetQuotes') {
                return self::quoteResponse();
            }
            if ($cmd === 'AcceptQuote') {
                return ['result' => 'success', 'invoiceid' => 99];
            }

            throw new \RuntimeException('transport exploded');
        });

        $json = $tools->convertQuoteToInvoice(quoteid: 10, paymentmethod: 'banktransfer');
        $result = json_decode($json, true);

        $this->assertSame('error', $result['result']);
        $this->assertTrue($result['partial']);
        $this->assertSame(10, $result['quoteid']);
        $this->assertSame(99, $result['invoiceid']);
        // F2: texto de exceção downstream não vai para o payload MCP.
        $this->assertStringNotContainsString('transport exploded', json_encode($result));
        $this->assertStringContainsString('Quote converted, but invoice update failed', $result['message']);
        $this->assertStringContainsString('NÃO repetir', $result['warning']);
    }

    /** Exceção do PRIMEIRO efeito é indeterminada: pode ter persistido. */
    public function test_convert_reports_partial_when_accept_quote_throws(): void
    {
        $tools = $this->makeConverter(function (string $cmd) {
            if ($cmd === 'GetQuotes') {
                return self::quoteResponse();
            }

            throw new \RuntimeException('gateway timeout');
        });

        $json = $tools->convertQuoteToInvoice(quoteid: 10);
        $result = json_decode($json, true);

        $this->assertSame('error', $result['result']);
        $this->assertTrue($result['partial']);
        $this->assertSame(10, $result['quoteid']);
        $this->assertArrayNotHasKey('invoiceid', $result, 'invoice desconhecida não pode ser inventada');
        $this->assertStringContainsString('indeterminate', $result['message']);
        $this->assertStringContainsString('MAY have been accepted', $result['message']);
        $this->assertStringContainsString('NÃO repetir', $result['warning']);
    }

    /** Retorno não-array do WHMCS vira RuntimeException — também é parcial. */
    public function test_convert_reports_partial_when_accept_quote_returns_non_array(): void
    {
        $tools = $this->makeConverter(function (string $cmd) {
            if ($cmd === 'GetQuotes') {
                return self::quoteResponse();
            }

            return 'not an array';
        });

        $json = $tools->convertQuoteToInvoice(quoteid: 10);
        $result = json_decode($json, true);

        $this->assertSame('error', $result['result']);
        $this->assertTrue($result['partial']);
        $this->assertStringContainsString('NÃO repetir', $result['warning']);
    }

    /**
     * A ÚNICA exceção ao contrato parcial: recusa de AUTORIZAÇÃO no primeiro
     * efeito não produz efeito nenhum, então precisa subir como negação — não
     * pode ser mascarada como conversão parcial.
     */
    public function test_convert_does_not_report_partial_when_first_effect_is_denied(): void
    {
        $tools = $this->makeTools(function (string $cmd) {
            if ($cmd === 'GetQuotes') {
                return self::quoteResponse();
            }
            return ['result' => 'success', 'invoiceid' => 99];
        }, ['write' => true]); // FINANCIAL off ⇒ AcceptQuote negado

        $this->expectException(\NtMcp\Whmcs\AuthorizationException::class);
        $this->expectExceptionMessage('class FINANCIAL disabled');

        $tools->convertQuoteToInvoice(quoteid: 10);
    }

    // ---------------------------------------------------------------
    // M4 — paymentmethod validado ANTES do primeiro efeito
    // ---------------------------------------------------------------

    public function test_convert_rejects_unknown_payment_gateway_before_any_effect(): void
    {
        $calls = [];
        $tools = $this->makeConverter(function (string $cmd) use (&$calls) {
            $calls[] = $cmd;
            return ['result' => 'success', 'invoiceid' => 99];
        });

        try {
            $tools->convertQuoteToInvoice(quoteid: 10, paymentmethod: 'missing');
            $this->fail('gateway inexistente deveria ser rejeitado');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not a configured WHMCS payment gateway', $e->getMessage());
            $this->assertStringContainsString('banktransfer', $e->getMessage());
        }

        $this->assertSame([], $calls, 'nenhum efeito — nem o preflight GetQuotes — pode ter ocorrido');
    }

    /**
     * F3: casar case-insensitive é permitido, mas o que segue para o
     * `UpdateInvoice` tem de ser o system name EXATO do banco — nunca a
     * capitalização digitada pelo chamador.
     */
    public function test_convert_forwards_the_canonical_gateway_not_the_typed_one(): void
    {
        $calls = [];
        $tools = $this->makeConverter(function (string $cmd, array $params) use (&$calls) {
            $calls[] = ['cmd' => $cmd, 'params' => $params];
            if ($cmd === 'GetQuotes') {
                return self::quoteResponse();
            }
            return ['result' => 'success', 'invoiceid' => 99];
        }, self::gatewayDirectory(['banktransfer']));

        $tools->convertQuoteToInvoice(quoteid: 10, paymentmethod: 'BankTransfer');

        $this->assertSame(['GetQuotes', 'AcceptQuote', 'UpdateInvoice'], array_column($calls, 'cmd'));
        $this->assertSame('banktransfer', $calls[2]['params']['paymentmethod']);
    }

    /**
     * Linha do banco fora da sintaxe oficial (maiúscula, dígito ou underscore
     * inicial) invalida a introspecção ANTES de GetQuotes/AcceptQuote.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidGatewayRowProvider')]
    public function test_convert_fails_closed_on_non_canonical_gateway_rows(string $row): void
    {
        $calls = [];
        $tools = $this->makeConverter(function (string $cmd) use (&$calls) {
            $calls[] = $cmd;
            return ['result' => 'success', 'invoiceid' => 99];
        }, self::gatewayDirectory(['banktransfer', $row]));

        $this->expectException(\RuntimeException::class);

        try {
            $tools->convertQuoteToInvoice(quoteid: 10, paymentmethod: 'banktransfer');
        } finally {
            $this->assertSame([], $calls, "linha '{$row}' não pode deixar nada rodar");
        }
    }

    public static function invalidGatewayRowProvider(): array
    {
        return [
            'maiúscula'      => ['PayPal'],
            'dígito inicial' => ['1paypal'],
            'underscore ini' => ['_paypal'],
        ];
    }

    /** Gateway em branco é rejeitado antes de qualquer efeito. */
    public function test_convert_rejects_blank_gateway_before_any_effect(): void
    {
        $calls = [];
        $tools = $this->makeConverter(function (string $cmd) use (&$calls) {
            $calls[] = $cmd;
            return ['result' => 'success', 'invoiceid' => 99];
        });

        $this->expectException(\InvalidArgumentException::class);

        try {
            $tools->convertQuoteToInvoice(quoteid: 10, paymentmethod: ' ');
        } finally {
            $this->assertSame([], $calls, 'nem o preflight pode rodar');
        }
    }

    /** Diretório com linha inválida invalida tudo — fail-closed antes do efeito. */
    public function test_convert_fails_closed_when_directory_has_an_invalid_row(): void
    {
        $calls = [];
        $tools = $this->makeConverter(function (string $cmd) use (&$calls) {
            $calls[] = $cmd;
            return ['result' => 'success', 'invoiceid' => 99];
        }, self::gatewayDirectory(['banktransfer', ' ']));

        $this->expectException(\RuntimeException::class);

        try {
            $tools->convertQuoteToInvoice(quoteid: 10, paymentmethod: 'banktransfer');
        } finally {
            $this->assertSame([], $calls);
        }
    }

    /** Introspecção indisponível é conservadora: recusa antes de converter. */
    public function test_convert_fails_closed_when_gateway_introspection_unavailable(): void
    {
        $calls = [];
        $broken = new PaymentGatewayDirectory();
        $broken->setResolver(static function () {
            throw new \RuntimeException('db down');
        });

        $tools = $this->makeConverter(function (string $cmd) use (&$calls) {
            $calls[] = $cmd;
            return ['result' => 'success', 'invoiceid' => 99];
        }, $broken);

        $this->expectException(\RuntimeException::class);

        try {
            $tools->convertQuoteToInvoice(quoteid: 10, paymentmethod: 'banktransfer');
        } finally {
            $this->assertSame([], $calls, 'nada pode ser convertido sem validar o gateway');
        }
    }

    /** Sem paymentmethod a conversão não depende de introspecção alguma. */
    public function test_convert_without_paymentmethod_does_not_need_gateway_introspection(): void
    {
        $broken = new PaymentGatewayDirectory();
        $broken->setResolver(static function () {
            throw new \RuntimeException('db down');
        });

        $tools = $this->makeConverter(function (string $cmd) {
            if ($cmd === 'GetQuotes') {
                return self::quoteResponse();
            }
            return ['result' => 'success', 'invoiceid' => 99];
        }, $broken);

        $result = json_decode($tools->convertQuoteToInvoice(quoteid: 10), true);

        $this->assertSame('success', $result['result']);
    }

    public function test_convert_quote_to_invoice_reports_partial_when_invoiceid_missing(): void
    {
        $tools = $this->makeConverter(function (string $cmd, array $params) {
            if ($cmd === 'GetQuotes') {
                return self::quoteResponse();
            }
            return ['result' => 'success']; // AcceptQuote sem invoiceid
        });

        $json = $tools->convertQuoteToInvoice(quoteid: 10, paymentmethod: 'banktransfer');
        $result = json_decode($json, true);

        $this->assertSame('error', $result['result']);
        $this->assertTrue($result['partial']);
        $this->assertSame(10, $result['quoteid']);
        $this->assertStringContainsString('NÃO repetir', $result['warning']);
    }

    /** Argumentos são validados ANTES do primeiro efeito persistente. */
    public function test_convert_quote_to_invoice_validates_arguments_before_any_effect(): void
    {
        foreach ([
            ['duedate' => 'ontem'],
            ['duedate' => '2026-02-31'],
            ['taxrate' => 150.0],
            ['taxrate' => -1.0],
        ] as $badArgs) {
            $called = [];
            $tools = $this->makeConverter(function (string $cmd) use (&$called) {
                $called[] = $cmd;
                return ['result' => 'success', 'invoiceid' => 1];
            });

            try {
                $tools->convertQuoteToInvoice(
                    quoteid: 10,
                    duedate: $badArgs['duedate'] ?? '',
                    taxrate: $badArgs['taxrate'] ?? null,
                );
                $this->fail('argumento inválido deveria ser rejeitado: ' . json_encode($badArgs));
            } catch (\InvalidArgumentException $e) {
                $this->assertSame([], $called, 'nenhuma chamada pode ocorrer antes da validação');
            }
        }
    }

    /** Guarda de repetição: cotação já aceita não é convertida de novo. */
    public function test_convert_quote_to_invoice_refuses_already_accepted_quote(): void
    {
        $calls = [];
        $tools = $this->makeConverter(function (string $cmd, array $params) use (&$calls) {
            $calls[] = $cmd;
            if ($cmd === 'GetQuotes') {
                return self::quoteResponse(stage: 'Accepted');
            }
            return ['result' => 'success', 'invoiceid' => 99];
        });

        $json = $tools->convertQuoteToInvoice(quoteid: 10);
        $result = json_decode($json, true);

        $this->assertSame(['GetQuotes'], $calls, 'AcceptQuote não pode ser chamado');
        $this->assertSame('error', $result['result']);
        $this->assertSame('Accepted', $result['stage']);
        $this->assertStringContainsString('already in stage', $result['message']);
    }

    public function test_convert_quote_to_invoice_blocked_when_financial_gate_off(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            if ($cmd === 'GetQuotes') {
                return self::quoteResponse();
            }
            return ['result' => 'success', 'invoiceid' => 99];
        }, ['write' => true]); // WRITE on, FINANCIAL off

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('class FINANCIAL disabled');

        $tools->convertQuoteToInvoice(quoteid: 10);
    }

    // ---------------------------------------------------------------
    // delete_quote (DESTRUCTIVE)
    // ---------------------------------------------------------------

    public function test_delete_quote_requires_confirm_true(): void
    {
        $called = false;
        $tools = $this->makeTools(function () use (&$called) {
            $called = true;
            return ['result' => 'success'];
        }, ['destructive' => true]);

        $json = $tools->deleteQuote(quoteid: 7, confirm: false);
        $result = json_decode($json, true);

        $this->assertFalse($called);
        $this->assertSame('error', $result['result']);
        $this->assertSame(7, $result['quoteid']);
        $this->assertSame('Deletion requires confirm=true', $result['message']);
    }

    public function test_delete_quote_calls_delete_quote_when_confirmed(): void
    {
        $captured = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$captured) {
            $captured = ['cmd' => $cmd, 'params' => $params];
            return ['result' => 'success'];
        }, ['destructive' => true]);

        $json = $tools->deleteQuote(quoteid: 7, confirm: true);
        $result = json_decode($json, true);

        $this->assertSame('DeleteQuote', $captured['cmd']);
        $this->assertSame(['quoteid' => 7], $captured['params']);
        $this->assertSame('success', $result['result']);
        $this->assertSame(7, $result['quoteid']);
    }

    public function test_delete_quote_outside_client_allowlist_never_reaches_destructive_api(): void
    {
        $calls = [];
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$calls) {
            $calls[] = $cmd;
            if ($cmd === 'GetQuotes') {
                return [
                    'result' => 'success',
                    'quotes' => ['quote' => [[
                        'id' => (int) $params['quoteid'],
                        'userid' => 99,
                    ]]],
                ];
            }
            return ['result' => 'success'];
        }, ['destructive' => true, 'allowlist_clientids' => [5]]);

        try {
            $tools->deleteQuote(quoteid: 7, confirm: true);
            $this->fail('cotação fora da allowlist deveria ser negada');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('write_target_not_allowed', $exception->getMessage());
        }

        $this->assertSame(['GetQuotes'], $calls, 'DeleteQuote nunca pode chegar à LocalAPI');
    }

    /** confirm=true é defesa adicional — NÃO substitui o gate. */
    public function test_delete_quote_blocked_by_gate_even_with_confirm_true(): void
    {
        $tools = $this->makeTools(null, ['write' => true]); // DESTRUCTIVE off

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('class DESTRUCTIVE disabled');

        $tools->deleteQuote(quoteid: 7, confirm: true);
    }

    // ---------------------------------------------------------------
    // #18 — shape estável de GetQuotes
    // ---------------------------------------------------------------

    public function test_list_quotes_has_stable_shape_for_orphan_and_normal_quotes(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return ['result' => 'success', 'totalresults' => 2, 'quotes' => ['quote' => [
                ['id' => 1, 'userid' => '0', 'datecreated' => '2026-01-01'],
                ['id' => 2, 'userid' => '31', 'subject' => 'Proposta', 'stage' => 'Draft',
                 'datecreated' => null, 'validuntil' => null, 'datesent' => null,
                 'client' => ['id' => 31, 'firstname' => 'A', 'email' => 'a@example.test', 'status' => 'Active']],
            ]]];
        });
        $data = json_decode($tools->listQuotes(), true);
        [$orphan, $normal] = $data['quotes']['quote'];

        $this->assertNull($orphan['client']);
        $this->assertSame('', $orphan['subject']);
        $this->assertSame('', $orphan['stage']);
        $this->assertSame(0, $orphan['userid']);
        $this->assertTrue($orphan['is_orphan']);

        $this->assertSame(31, $normal['userid']);
        $this->assertArrayNotHasKey('is_orphan', $normal);
        $this->assertSame(['id' => 31, 'status' => 'Active'], $normal['client']);
        $this->assertNull($normal['validuntil']);
    }

    public function test_get_quote_defaults_to_lite_and_normalizes_single_quote_shape(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'quotes' => ['quote' => [
            'id' => 8,
            'userid' => '31',
            'subject' => 'Proposta',
            'stage' => 'Draft',
            'firstname' => 'Ana',
            'email' => 'ana@example.test',
            'client' => [
                'id' => 31,
                'datecreated' => '2020-01-01',
                'groupid' => 2,
                'status' => 'Active',
                'firstname' => 'Ana',
                'email' => 'ana@example.test',
            ],
        ]]]);

        $quote = json_decode($tools->getQuote(8), true)['quotes']['quote'][0];

        $this->assertArrayNotHasKey('firstname', $quote);
        $this->assertArrayNotHasKey('email', $quote);
        $this->assertSame([
            'id' => 31,
            'datecreated' => '2020-01-01',
            'groupid' => 2,
            'status' => 'Active',
        ], $quote['client']);
    }

    public function test_get_quote_full_keeps_customer_identity(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'quotes' => ['quote' => [[
            'id' => 8,
            'userid' => 31,
            'firstname' => 'Ana',
            'email' => 'ana@example.test',
            'client' => ['id' => 31, 'firstname' => 'Ana', 'email' => 'ana@example.test'],
        ]]]]);

        $quote = json_decode($tools->getQuote(8, 'full'), true)['quotes']['quote'][0];

        $this->assertSame('Ana', $quote['firstname']);
        $this->assertSame('ana@example.test', $quote['email']);
        $this->assertSame('Ana', $quote['client']['firstname']);
        $this->assertSame('ana@example.test', $quote['client']['email']);
    }

    public function test_get_quote_lite_preserves_orphan_marker(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'quotes' => ['quote' => [[
            'id' => 9,
            'userid' => 0,
            'firstname' => 'Contato sem cadastro',
        ]]]]);

        $quote = json_decode($tools->getQuote(9), true)['quotes']['quote'][0];

        $this->assertNull($quote['client']);
        $this->assertTrue($quote['is_orphan']);
        $this->assertArrayNotHasKey('firstname', $quote);
    }

    public function test_quote_reads_reject_invalid_fields(): void
    {
        $tools = $this->makeTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("fields deve ser 'lite' ou 'full'");
        $tools->listQuotes(fields: 'pii');
    }
}
