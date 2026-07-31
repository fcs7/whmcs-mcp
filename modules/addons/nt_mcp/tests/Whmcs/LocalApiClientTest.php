<?php
// tests/Whmcs/LocalApiClientTest.php
namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\LocalApiClient;
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

    public function test_call_returns_error_response(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setCallable(function () {
            return ['result' => 'error', 'message' => 'Client not found'];
        });

        $result = $client->call('GetClientsDetails', ['clientid' => 999]);
        $this->assertEquals('error', $result['result']);
        $this->assertEquals('Client not found', $result['message']);
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

    // ---------------------------------------------------------------
    // redactParams tests (private static, accessed via Reflection)
    // ---------------------------------------------------------------

    private function callRedactParams(array $params, int $depth = 0): array
    {
        $method = new \ReflectionMethod(LocalApiClient::class, 'redactParams');
        return $method->invoke(null, $params, $depth);
    }

    public function test_redact_params_hides_password(): void
    {
        $result = $this->callRedactParams(['password' => 'secret']);
        $this->assertSame(['password' => '[REDACTED]'], $result);
    }

    public function test_redact_params_hides_card_fields(): void
    {
        $result = $this->callRedactParams([
            'cardnum' => '4111',
            'cvv' => '123',
            'expdate' => '12/26',
        ]);

        $this->assertSame('[REDACTED]', $result['cardnum']);
        $this->assertSame('[REDACTED]', $result['cvv']);
        $this->assertSame('[REDACTED]', $result['expdate']);
    }

    public function test_redact_params_preserves_safe_fields(): void
    {
        $input = ['clientid' => 1, 'firstname' => 'John'];
        $result = $this->callRedactParams($input);
        $this->assertSame($input, $result);
    }

    public function test_redact_params_recurses_nested_arrays(): void
    {
        $result = $this->callRedactParams(['data' => ['password' => 'secret']]);
        $this->assertSame(['data' => ['password' => '[REDACTED]']], $result);
    }

    public function test_redact_params_limits_depth_to_5(): void
    {
        // Build 7-level nested array: level0 > level1 > ... > level5 > {innerkey}
        // At depth 5, the array value for level5 triggers $depth >= 5 → '[NESTED]'
        $nested = ['innerkey' => 'innervalue'];
        for ($i = 5; $i >= 0; $i--) {
            $nested = ["level{$i}" => $nested];
        }

        $result = $this->callRedactParams($nested);

        // Traverse to level5 — its value should be '[NESTED]' (not recursed)
        $cursor = $result;
        for ($i = 0; $i < 5; $i++) {
            $this->assertIsArray($cursor["level{$i}"]);
            $cursor = $cursor["level{$i}"];
        }

        $this->assertSame('[NESTED]', $cursor['level5']);
    }

    public function test_redact_params_is_case_insensitive(): void
    {
        $result = $this->callRedactParams([
            'Password' => 'x',
            'CARDNUM' => '4111',
        ]);

        $this->assertSame('[REDACTED]', $result['Password']);
        $this->assertSame('[REDACTED]', $result['CARDNUM']);
    }

    /**
     * Guardrail do T1: credenciais, campos fiscais e dados de pagamento nunca
     * podem aparecer no Activity Log.
     */
    public function test_redact_params_hides_credentials_fiscal_and_payment_fields(): void
    {
        $result = $this->callRedactParams([
            // credenciais
            'password' => 'p1', 'password2' => 'p2', 'securityqans' => 'mother',
            // campo fiscal
            'tax_id' => '123.456.789-00',
            // dados de pagamento
            'cardnum' => '4111111111111111', 'cardnumber' => '4111111111111111',
            'cvv' => '123', 'cvc' => '456', 'expdate' => '12/26',
            'bankacct' => '00012345', 'bankcode' => '341',
            // não sensível: deve sobreviver
            'clientid' => 7,
        ]);

        foreach ([
            'password', 'password2', 'securityqans', 'tax_id',
            'cardnum', 'cardnumber', 'cvv', 'cvc', 'expdate',
            'bankacct', 'bankcode',
        ] as $sensitive) {
            $this->assertSame('[REDACTED]', $result[$sensitive], "campo sensível vazou: {$sensitive}");
        }

        $this->assertSame(7, $result['clientid']);
    }

    /**
     * A tentativa BLOQUEADA por gate também passa pela redação: o log de
     * bloqueio recebe os params, então não pode carregar segredo em claro.
     */
    public function test_blocked_attempt_params_go_through_redaction(): void
    {
        $client = new LocalApiClient('testadmin');
        $client->setGates([]); // WRITE off
        $client->setCallable(fn() => ['result' => 'success']);

        try {
            $client->call('AddClient', ['firstname' => 'a', 'password2' => 'secret', 'tax_id' => '111']);
            $this->fail('AddClient deveria estar bloqueado');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('blocked', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }

        $redacted = $this->callRedactParams(['firstname' => 'a', 'password2' => 'secret', 'tax_id' => '111']);
        $this->assertSame('[REDACTED]', $redacted['password2']);
        $this->assertSame('[REDACTED]', $redacted['tax_id']);
    }
}
