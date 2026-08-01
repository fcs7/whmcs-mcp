<?php
// tests/Whmcs/LocalApiClientTest.php
namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LocalApiClientTest extends TestCase
{
    public function test_call_returns_result_on_success(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setCallable(function (string $cmd, array $params) {
            return ['result' => 'success', 'numreturns' => 1];
        });

        $result = $client->call('GetClients', []);
        $this->assertEquals('success', $result['result']);
    }

    /**
     * F2: o `message` do WHMCS é texto arbitrário e não atravessa mais. O
     * chamador recebe contrato estável + correlação para o operador achar o
     * incidente no log protegido.
     */
    public function test_call_returns_stable_error_contract_without_downstream_text(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setCallable(function () {
            return ['result' => 'error', 'message' => 'Client not found'];
        });

        $result = $client->call('GetClientsDetails', ['clientid' => 999]);

        $this->assertSame('error', $result['result']);
        $this->assertStringNotContainsString('Client not found', json_encode($result));
        $this->assertStringContainsString('GetClientsDetails', $result['message']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $result['correlation_id']);
    }

    /**
     * m1.1 (P2): só `result === 'success'` é sucesso. Array malformado é
     * indeterminado e falha fechado — antes gerava um `OK` falso.
     *
     * @param mixed $response
     */
    #[DataProvider('malformedResponseProvider')]
    public function test_malformed_array_response_fails_closed(mixed $response): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setCallable(fn() => $response);

        $this->expectException(\NtMcp\Whmcs\DownstreamFailureException::class);
        $this->expectExceptionMessage('did not complete successfully');

        $client->call('GetClients', []);
    }

    public static function malformedResponseProvider(): array
    {
        return [
            'array vazio'        => [[]],
            'sem result'         => [['numreturns' => 1]],
            'result null'        => [[['result' => null]][0]],
            'result desconhecido'=> [['result' => 'partial']],
            'result vazio'       => [['result' => '']],
            'não-array string'   => ['nope'],
            'não-array null'     => [null],
            'não-array bool'     => [false],
        ];
    }

    public function test_success_response_passes_through(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setCallable(fn() => ['result' => 'success', 'numreturns' => 2]);

        $result = $client->call('GetClients', []);

        $this->assertSame('success', $result['result']);
        $this->assertSame(2, $result['numreturns']);
    }

    /** Exceção downstream vira contrato estável, sem texto arbitrário. */
    public function test_downstream_exception_is_wrapped_in_a_stable_contract(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setCallable(function () {
            throw new \RuntimeException('SQLSTATE[42000] token=abcdef0123456789 /var/www/secret.php');
        });

        try {
            $client->call('GetClients', []);
            $this->fail('deveria lançar');
        } catch (\NtMcp\Whmcs\DownstreamFailureException $e) {
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
            $this->assertStringNotContainsString('abcdef0123456789', $e->getMessage());
            $this->assertStringNotContainsString('/var/www', $e->getMessage());
            $this->assertStringContainsString('correlation id', $e->getMessage());
            // A causa NÃO é encadeada: `(string)$exception` incluiria a cadeia
            // anterior e reintroduziria o texto downstream em qualquer handler
            // que estringifique a exceção. Classe e fingerprint viajam como
            // dado estruturado.
            $this->assertNull($e->getPrevious());
            $this->assertSame(\RuntimeException::class, $e->causeClass);
            // D10: sem chave provisionada o fingerprint é OMITIDO — nunca
            // derivado de path/PID. Com chave, tem 128 bits.
            $this->assertNull($e->causeFingerprint, 'sem chave, o fingerprint não pode existir');

            \NtMcp\Whmcs\Diagnostics::setFingerprintKey(hash('sha256', 'nt-mcp local-api test diagnostics key'));
            try {
                $client->call('GetClients', []);
            } catch (\NtMcp\Whmcs\DownstreamFailureException $keyed) {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\z/', (string) $keyed->causeFingerprint);
            } finally {
                \NtMcp\Whmcs\Diagnostics::resetFingerprintKey();
            }
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $e->correlationId);
            // E a estringificação completa também não carrega o texto.
            $this->assertStringNotContainsString('hunter', (string) $e);
            $this->assertStringNotContainsString('SQLSTATE', (string) $e);
        }
    }

    /** Negação de autorização mantém semântica própria (mensagem é nossa). */
    public function test_authorization_exception_is_not_wrapped(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates([]); // WRITE off
        $client->setCallable(fn() => ['result' => 'success']);

        $this->expectException(\NtMcp\Whmcs\AuthorizationException::class);
        $client->call('AddClient', ['firstname' => 'a', 'noemail' => true]);
    }

    public function test_call_rejects_unlisted_command(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setCallable(function () {
            return ['result' => 'success'];
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not in the allowed list');
        $client->call('AddAdmin', ['username' => 'hacker']);
    }

    // ---------------------------------------------------------------
    // Contrato do allowlist: exatamente os 51 comandos requeridos pelas
    // 64 tools canônicas, todos com classificação explícita.
    // ---------------------------------------------------------------

    /** @return array<string> */
    private static function allowedCommands(): array
    {
        return (new \ReflectionClass(LocalApiClient::class))
            ->getReflectionConstant('ALLOWED_COMMANDS')->getValue();
    }

    /** @return array<string, string> */
    private static function commandClasses(): array
    {
        return (new \ReflectionClass(LocalApiClient::class))
            ->getReflectionConstant('COMMAND_CLASS')->getValue();
    }

    public function test_allowed_commands_match_64_tool_profile(): void
    {
        $commands = self::allowedCommands();

        $this->assertCount(51, $commands);
        $this->assertSame($commands, array_values(array_unique($commands)), 'sem duplicatas');
        $this->assertContains('AcceptQuote', $commands);
        $this->assertContains('DeleteQuote', $commands);
        $this->assertContains('UpdateInvoice', $commands);

        foreach ([
            'ModuleSuspend',
            'ModuleUnsuspend',
            'UpgradeProduct',
            'AcceptOrder',
            'AddOrder',
            'GetOrderStatuses',
            'GetProducts',
            'GetPromotions',
            'DomainRegister',
            'DomainRenew',
            'DomainUpdateNameservers',
            'UpdateClientDomain',
            'SendEmail',
            'GetCurrencies',
            'GetEmailTemplates',
            'GetPaymentMethods',
            'GetToDoItemStatuses',
            'LogActivity',
            'DeleteProjectTask',
            'SendQuote',
            'GetTicketNotes',
            'GetTicketPredefinedCats',
            'GetTicketPredefinedReplies',
            'GetTicketAttachment',
        ] as $removedCommand) {
            $this->assertNotContains($removedCommand, $commands);
        }
    }

    public function test_every_allowed_command_is_explicitly_classified(): void
    {
        $commands = self::allowedCommands();
        $classes = self::commandClasses();

        $this->assertCount(51, $classes, 'COMMAND_CLASS não pode ter entrada órfã');

        foreach ($commands as $command) {
            $this->assertArrayHasKey($command, $classes, "'{$command}' sem classe explícita");
        }
        foreach (array_keys($classes) as $classified) {
            $this->assertContains($classified, $commands, "'{$classified}' classificado mas fora do allowlist");
        }
    }

    /**
     * Nenhum comando órfão e nenhuma tool chamando comando ausente: o allowlist
     * é exatamente o conjunto de comandos que as 64 tools realmente invocam.
     */
    public function test_allowlist_matches_exactly_the_commands_the_tools_call(): void
    {
        $used = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Tools/*.php') as $file) {
            if (preg_match_all('/->call\(\s*[\'"]([A-Za-z]+)[\'"]/', (string) file_get_contents($file), $m)) {
                foreach ($m[1] as $command) {
                    $used[$command] = true;
                }
            }
        }

        $used = array_keys($used);
        $allowed = self::allowedCommands();
        sort($used);
        sort($allowed);

        $this->assertSame(
            $allowed,
            $used,
            "allowlist e comandos usados divergem.\n" .
            'usados mas não permitidos: ' . implode(', ', array_diff($used, $allowed)) . "\n" .
            'permitidos mas não usados: ' . implode(', ', array_diff($allowed, $used))
        );
    }

    public function test_command_class_distribution_matches_the_effect_matrix(): void
    {
        $counts = array_count_values(self::commandClasses());

        $this->assertSame(29, $counts['READ'] ?? 0);
        $this->assertSame(18, $counts['WRITE'] ?? 0);
        $this->assertSame(2, $counts['FINANCIAL'] ?? 0);
        $this->assertSame(2, $counts['DESTRUCTIVE'] ?? 0);
        // Nenhuma tool da superfície é primariamente COST ou COMMS.
        $this->assertArrayNotHasKey('COST', $counts);
        $this->assertArrayNotHasKey('COMMS', $counts);
    }

    public function test_financial_and_destructive_commands_are_classified_as_such(): void
    {
        $classes = self::commandClasses();

        $this->assertSame('FINANCIAL', $classes['AcceptQuote']);
        $this->assertSame('FINANCIAL', $classes['UpdateInvoice']);
        $this->assertSame('DESTRUCTIVE', $classes['CancelOrder']);
        $this->assertSame('DESTRUCTIVE', $classes['DeleteQuote']);
    }

    /**
     * Fail-closed: um comando no allowlist mas sem classificação seria negado
     * antes de alcançar a localAPI — a mudança que teve de preceder a entrada
     * de UpdateInvoice/DeleteQuote no allowlist.
     */
    public function test_unclassified_command_is_denied_before_reaching_localapi(): void
    {
        $called = false;
        $client = new LocalApiClient('testadmin');
        $client->setGates(['write' => true, 'destructive' => true, 'financial' => true, 'cost' => true, 'comms' => true]);
        $client->setCallable(function () use (&$called) {
            $called = true;
            return ['result' => 'success'];
        });

        $method = new \ReflectionMethod(LocalApiClient::class, 'classOf');

        try {
            $method->invoke($client, 'SomeFutureCommand');
            $this->fail('comando sem classificação deveria falhar fechado');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no explicit security classification', $e->getMessage());
        }

        $this->assertFalse($called);
    }

}
