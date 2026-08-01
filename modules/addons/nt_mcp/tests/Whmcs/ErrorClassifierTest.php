<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use NtMcp\Tests\Support\ActivityLogSpy;
use NtMcp\Tests\Support\ErrorLogSpy;
use NtMcp\Whmcs\ErrorClassifier;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * D6 — erros do WHMCS viram código estável nosso, sem que um byte do texto
 * downstream atravesse.
 */
class ErrorClassifierTest extends TestCase
{
    protected function setUp(): void
    {
        ActivityLogSpy::start();
        ErrorLogSpy::start();
    }

    protected function tearDown(): void
    {
        ErrorLogSpy::stop();
        ActivityLogSpy::stop();
    }

    // ---------------------------------------------------------------
    // Classificação isolada
    // ---------------------------------------------------------------

    #[DataProvider('knownErrorProvider')]
    public function test_known_errors_map_to_stable_codes(string $command, string $message, string $code, string $category): void
    {
        $result = ErrorClassifier::classify($command, $message);

        $this->assertSame($code, $result['code']);
        $this->assertSame($category, $result['category']);
    }

    public static function knownErrorProvider(): array
    {
        return [
            'email duplicado'     => ['AddClient', 'Email Address Already Exists', 'client_email_exists', ErrorClassifier::CONFLICT],
            'email inválido'      => ['AddClient', 'Invalid Email Address', 'client_email_invalid', ErrorClassifier::VALIDATION],
            'cliente inexistente' => ['GetClientsDetails', 'Client Not Found', 'client_not_found', ErrorClassifier::NOT_FOUND],
            'quote inexistente'   => ['AcceptQuote', 'Quote ID Not Found', 'quote_not_found', ErrorClassifier::NOT_FOUND],
            'quote já aceita'     => ['AcceptQuote', 'Quote Already Accepted', 'quote_already_accepted', ErrorClassifier::CONFLICT],
            'gateway inválido'    => ['UpdateInvoice', 'Invalid Payment Method', 'invoice_paymentmethod_invalid', ErrorClassifier::VALIDATION],
            'ticket inexistente'  => ['UpdateTicket', 'Ticket ID Not Found', 'ticket_not_found', ErrorClassifier::NOT_FOUND],
            'acesso negado'       => ['GetClients', 'Access Denied', 'access_denied', ErrorClassifier::DENIED],
        ];
    }

    /** Comandos distintos produzem códigos distintos para o mesmo cenário. */
    public function test_distinct_commands_produce_distinct_codes(): void
    {
        $client = ErrorClassifier::classify('GetClientsDetails', 'Client Not Found');
        $quote = ErrorClassifier::classify('AcceptQuote', 'Quote ID Not Found');
        $ticket = ErrorClassifier::classify('GetTicket', 'Ticket Not Found');

        $codes = [$client['code'], $quote['code'], $ticket['code']];
        $this->assertSame($codes, array_unique($codes), 'códigos precisam distinguir os casos');
        foreach ([$client, $quote, $ticket] as $r) {
            $this->assertSame(ErrorClassifier::NOT_FOUND, $r['category']);
        }
    }

    public function test_normalisation_tolerates_case_spacing_and_punctuation(): void
    {
        foreach (['Client Not Found', 'client not found', '  CLIENT   NOT FOUND. ', "Client\tNot\nFound!"] as $variant) {
            $this->assertSame(
                'client_not_found',
                ErrorClassifier::classify('GetClientsDetails', $variant)['code'],
                "variante não reconhecida: {$variant}"
            );
        }
    }

    // ---------------------------------------------------------------
    // Ancoragem — o ponto que o revisor exigiu
    // ---------------------------------------------------------------

    /**
     * O padrão é ANCORADO. Uma mensagem cuja parte variável CONTÉM a frase-alvo
     * não pode ser classificada como se fosse a frase — senão um erro qualquer
     * que ecoe input vira `not_found`.
     */
    #[DataProvider('nonAnchoredProvider')]
    public function test_substring_matches_do_not_classify(string $message): void
    {
        $result = ErrorClassifier::classify('GetClientsDetails', $message);

        $this->assertSame('downstream_error', $result['code']);
        $this->assertSame(ErrorClassifier::DOWNSTREAM, $result['category']);
    }

    public static function nonAnchoredProvider(): array
    {
        return [
            'prefixo'   => ['Warning: Client Not Found while processing'],
            'sufixo'    => ['Client Not Found in cache, retrying upstream'],
            'com dados' => ['Client Not Found for tax_id 123.456.789-00'],
            'eco'       => ['Rejected value "Client Not Found" supplied by caller'],
        ];
    }

    #[DataProvider('unknownMessageProvider')]
    public function test_unknown_messages_fall_back_to_downstream(?string $message): void
    {
        $result = ErrorClassifier::classify('AddClient', $message);

        $this->assertSame('downstream_error', $result['code']);
        $this->assertSame(ErrorClassifier::DOWNSTREAM, $result['category']);
    }

    public static function unknownMessageProvider(): array
    {
        return [
            'null'         => [null],
            'vazia'        => [''],
            'desconhecida' => ['Something entirely unexpected happened'],
            'só segredo'   => ['tok_abcdef0123456789'],
        ];
    }

    /** Comando sem tabela própria ainda cai nos padrões globais e no fallback. */
    public function test_unmapped_command_uses_global_patterns_then_downstream(): void
    {
        $this->assertSame('access_denied', ErrorClassifier::classify('GetStats', 'Access Denied')['code']);
        $this->assertSame('downstream_error', ErrorClassifier::classify('GetStats', 'Whatever')['code']);
    }

    // ---------------------------------------------------------------
    // Nada do texto sai — nem quando o padrão conhecido vem com segredo
    // ---------------------------------------------------------------

    /**
     * Padrão conhecido MISTURADO com segredo. Duas coisas precisam valer ao
     * mesmo tempo: nenhum byte vaza, e a ancoragem impede que a frase-alvo
     * embutida numa mensagem maior classifique como se fosse ela — cair em
     * `downstream` é o comportamento correto e conservador.
     */
    public function test_known_phrase_mixed_with_secrets_leaks_nothing_and_does_not_misclassify(): void
    {
        $poison = 'Client Not Found tok_abcdef0123456789 123.456.789-00 /var/www/configuration.php';

        $api = new LocalApiClient('testadmin');
        $api->setCallable(fn() => ['result' => 'error', 'message' => $poison]);

        $response = $api->call('GetClientsDetails', ['clientid' => 999]);

        $sinks = [
            'resposta'     => json_encode($response),
            'Activity Log' => implode("\n", ActivityLogSpy::entries()),
            'error_log'    => ErrorLogSpy::contents(),
        ];

        foreach ($sinks as $name => $content) {
            foreach (['tok_abcdef0123456789', '123.456.789-00', '/var/www/configuration.php'] as $secret) {
                $this->assertStringNotContainsString($secret, $content, "vazou em {$name}");
            }
            $this->assertStringNotContainsString('Client Not Found', $content, "texto downstream em {$name}");
        }

        $this->assertSame('downstream_error', $response['error_code'], 'substring não pode classificar');
        $this->assertSame(ErrorClassifier::DOWNSTREAM, $response['error_category']);
    }

    /** Mensagem exatamente igual ao padrão: classifica, e nada do texto sai. */
    public function test_exact_phrase_classifies_while_params_carrying_secrets_leak_nothing(): void
    {
        $api = new LocalApiClient('testadmin');
        $api->setGates(['write' => true]);
        $api->setCallable(fn() => ['result' => 'error', 'message' => 'Email Address Already Exists']);

        $response = $api->call('AddClient', [
            'firstname' => 'Ana',
            'password2' => 'hunter2SuperSecret',
            'tax_id' => '123.456.789-00',
            'noemail' => true,
        ]);

        $this->assertSame('client_email_exists', $response['error_code']);
        $this->assertSame(ErrorClassifier::CONFLICT, $response['error_category']);

        $sinks = [
            'resposta'     => json_encode($response),
            'Activity Log' => implode("\n", ActivityLogSpy::entries()),
            'error_log'    => ErrorLogSpy::contents(),
        ];
        foreach ($sinks as $name => $content) {
            foreach (['hunter2SuperSecret', '123.456.789-00', 'Email Address Already Exists'] as $secret) {
                $this->assertStringNotContainsString($secret, $content, "vazou em {$name}");
            }
        }
    }

    public function test_error_response_carries_code_category_and_correlation(): void
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable(fn() => ['result' => 'error', 'message' => 'Email Address Already Exists']);
        $api->setGates(['write' => true]);

        $response = $api->call('AddClient', ['firstname' => 'a', 'noemail' => true]);

        $this->assertSame('error', $response['result']);
        $this->assertSame('client_email_exists', $response['error_code']);
        $this->assertSame(ErrorClassifier::CONFLICT, $response['error_category']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $response['correlation_id']);
    }

    /** O Activity Log registra o código, que é nosso — não a mensagem. */
    public function test_activity_log_records_the_stable_code(): void
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable(fn() => ['result' => 'error', 'message' => 'Client Not Found']);

        $api->call('GetClientsDetails', ['clientid' => 1]);

        $this->assertTrue(ActivityLogSpy::hasEntryContaining('MCP API ERROR GetClientsDetails (client_not_found)'));
    }
}
