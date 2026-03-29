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
}
