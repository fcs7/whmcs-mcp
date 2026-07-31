<?php

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\ClientTools;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

class ClientToolsValidationTest extends TestCase
{
    /**
     * As tools de escrita de cliente são WRITE e o gate WRITE tem default
     * DESLIGADO (rollout read-only), então os testes de comportamento precisam
     * habilitá-lo. COMMS fica desligado — é o default de produção.
     */
    private function makeTools(?callable $callable = null, array $gates = ['write' => true]): ClientTools
    {
        $api = new LocalApiClient('testadmin');
        $api->setGates($gates);
        $api->setCallable($callable ?? function (string $cmd, array $params) {
            return ['result' => 'success', 'clientid' => 1];
        });
        return new ClientTools($api);
    }

    public function test_create_client_rejects_invalid_json_customfields(): void
    {
        $tools = $this->makeTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('valid JSON');

        $tools->createClient('John', 'Doe', 'john@example.com', 'pass123', customfields: 'not-json');
    }

    public function test_create_client_rejects_oversized_customfields(): void
    {
        $tools = $this->makeTools();

        // 51 fields exceeds the 50-field limit
        $fields = [];
        for ($i = 1; $i <= 51; $i++) {
            $fields[(string) $i] = 'val';
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('size limits');

        $tools->createClient('John', 'Doe', 'john@example.com', 'pass123', customfields: json_encode($fields));
    }

    public function test_create_client_rejects_large_customfields(): void
    {
        $tools = $this->makeTools();

        // Create a JSON string larger than 8192 bytes
        $fields = ['1' => str_repeat('x', 8200)];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('size limits');

        $tools->createClient('John', 'Doe', 'john@example.com', 'pass123', customfields: json_encode($fields));
    }

    public function test_create_client_rejects_non_scalar_customfields(): void
    {
        $tools = $this->makeTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-scalar');

        $tools->createClient('John', 'Doe', 'john@example.com', 'pass123', customfields: '{"1": [1,2]}');
    }

    public function test_create_client_accepts_valid_customfields(): void
    {
        $tools = $this->makeTools();

        $result = $tools->createClient('John', 'Doe', 'john@example.com', 'pass123', customfields: '{"4":"valor"}');

        $decoded = json_decode($result, true);
        $this->assertSame('success', $decoded['result']);
    }

    public function test_create_client_accepts_null_customfield_values(): void
    {
        $tools = $this->makeTools();

        $result = $tools->createClient('John', 'Doe', 'john@example.com', 'pass123', customfields: '{"1": null}');

        $decoded = json_decode($result, true);
        $this->assertSame('success', $decoded['result']);
    }

    public function test_create_client_works_without_customfields(): void
    {
        $tools = $this->makeTools();

        $result = $tools->createClient('John', 'Doe', 'john@example.com', 'pass123');

        $decoded = json_decode($result, true);
        $this->assertSame('success', $decoded['result']);
        $this->assertSame(1, $decoded['clientid']);
    }

    public function test_update_client_only_sends_non_empty_params(): void
    {
        $capturedParams = null;

        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->updateClient(42, firstname: 'Jane');

        $this->assertSame(['clientid' => 42, 'firstname' => 'Jane'], $capturedParams);
    }

    public function test_update_client_sends_customfields_encoded(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->updateClient(42, customfields: '{"4":"CPF"}');

        $this->assertSame(base64_encode(json_encode(['4' => 'CPF'])), $capturedParams['customfields']);
    }

    public function test_update_client_rejects_invalid_customfields(): void
    {
        $tools = $this->makeTools();

        $this->expectException(\InvalidArgumentException::class);

        $tools->updateClient(42, customfields: 'not-json');
    }

    public function test_create_client_sends_companyname_and_suppresses_email_by_default(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success', 'clientid' => 1];
        });

        $tools->createClient('John', 'Doe', 'john@example.com', 'pass123', companyname: 'ACME');

        $this->assertSame('ACME', $capturedParams['companyname']);
        $this->assertTrue($capturedParams['noemail'], 'default é NÃO notificar o cliente');
    }

    // ---------------------------------------------------------------
    // COMMS ortogonal: notify_client=true exige WRITE **e** COMMS.
    // ---------------------------------------------------------------

    public function test_create_client_with_notify_client_blocked_when_comms_gate_off(): void
    {
        $called = false;
        $tools = $this->makeTools(function () use (&$called) {
            $called = true;
            return ['result' => 'success'];
        }, ['write' => true, 'comms' => false]);

        try {
            $tools->createClient('John', 'Doe', 'john@example.com', 'pass123', notify_client: true);
            $this->fail('notify_client=true deveria ser bloqueado sem o gate COMMS');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('COMMS gate', $e->getMessage());
        }

        $this->assertFalse($called, 'a chamada não pode chegar à LocalAPI');
    }

    public function test_create_client_with_notify_client_allowed_when_write_and_comms_on(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success', 'clientid' => 1];
        }, ['write' => true, 'comms' => true]);

        $tools->createClient('John', 'Doe', 'john@example.com', 'pass123', notify_client: true);

        $this->assertArrayNotHasKey('noemail', $capturedParams, 'notificação liberada: sem bloqueio de e-mail');
    }

    public function test_list_clients_sends_status_filter(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success', 'clients' => []];
        });

        $tools->listClients(status: 'Active');

        $this->assertSame('Active', $capturedParams['status']);
    }
}
