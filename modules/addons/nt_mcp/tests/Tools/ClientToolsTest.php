<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\ClientTools;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

class ClientToolsTest extends TestCase
{
    private function makeTools(?callable $callable = null): ClientTools
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable($callable ?? function (string $cmd, array $params) {
            return ['result' => 'success'];
        });
        return new ClientTools($api);
    }

    public function test_get_client_strips_password_and_securityqans(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return [
                'result' => 'success',
                'clientid' => 1,
                'firstname' => 'John',
                'password' => 'hash-of-secret',
                'securityqans' => 'my-answer',
            ];
        });

        $json = $tools->getClient(1, 'full');
        $data = json_decode($json, true);

        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('securityqans', $data);
        $this->assertSame('John', $data['firstname']);
    }

    public function test_get_client_no_password_field_survives_intact(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return ['result' => 'success', 'clientid' => 1, 'firstname' => 'Jane'];
        });

        $json = $tools->getClient(1, 'full');
        $data = json_decode($json, true);

        $this->assertSame('Jane', $data['firstname']);
    }

    public function test_get_client_sends_clientid_and_stats(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = ['cmd' => $cmd, 'params' => $params];
            return ['result' => 'success'];
        });

        $tools->getClient(7);

        $this->assertSame('GetClientsDetails', $capturedParams['cmd']);
        $this->assertSame(7, $capturedParams['params']['clientid']);
        $this->assertTrue($capturedParams['params']['stats']);
    }

    public function test_get_client_products_strips_password_from_products(): void
    {
        $tools = $this->makeTools(function (string $cmd, array $params) {
            return [
                'result' => 'success',
                'products' => [
                    'product' => [
                        ['id' => 1, 'name' => 'Hosting', 'password' => 's3cr3t'],
                    ],
                ],
            ];
        });

        $json = $tools->getClientProducts(1);
        $data = json_decode($json, true);

        $this->assertArrayNotHasKey('password', $data['products']['product'][0]);
        $this->assertSame('Hosting', $data['products']['product'][0]['name']);
    }

    /**
     * `userid` e `clientid` são o MESMO id: exigir `userid` fazia a tool falhar
     * para quem tinha acabado de ler um `clientid` em qualquer outra tool.
     */
    public function test_get_contacts_accepts_clientid_as_an_alias_of_userid(): void
    {
        $seen = [];
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$seen) {
            $seen[] = $params;
            return ['result' => 'success', 'contacts' => ['contact' => []]];
        });

        $tools->getContacts(userid: 31);
        $tools->getContacts(clientid: 31);

        $this->assertSame(31, $seen[0]['userid']);
        $this->assertSame(31, $seen[1]['userid']);
        $this->assertArrayNotHasKey('clientid', $seen[1]);
    }

    /** Aceitar os dois ao mesmo tempo abriria a pergunta de qual vence quando divergem. */
    public function test_get_contacts_requires_exactly_one_of_the_two_ids(): void
    {
        $tools = $this->makeTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one of clientid or userid');
        $tools->getContacts();
    }

    public function test_get_contacts_rejects_both_ids_at_once(): void
    {
        $tools = $this->makeTools();

        $this->expectException(\InvalidArgumentException::class);
        $tools->getContacts(userid: 31, clientid: 32);
    }

    public function test_get_contacts_returns_an_empty_list_instead_of_dropping_the_key(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'totalresults' => 0]);

        $data = json_decode($tools->getContacts(clientid: 31), true);

        $this->assertSame([], $data['contacts']);
    }

    public function test_get_client_defaults_to_lite_view(): void
    {
        $tools = $this->makeTools(function () {
            return [
                'result' => 'success',
                'clientid' => 1,
                'firstname' => 'John',
                'lastname' => 'Doe',
                'email' => 'john@example.com',
                'status' => 'active',
                'groupid' => 4,
                'currency' => 1,
                'currency_code' => 'BRL',
                'datecreated' => '2026-01-02',
                'stats' => ['numproducts' => 2],
                'address1' => '123 Main St',
                'phonenumber' => '555-1234',
                'customfields' => [['id' => 5, 'value' => 'test']],
                'lastlogin' => 'Date: 17/08/2026 10:19<br>IP Address: 1.2.3.4<br>Host: x',
            ];
        });

        $data = json_decode($tools->getClient(1), true);

        $this->assertSame([
            'result' => 'success',
            'clientid' => 1,
            'status' => 'active',
            'groupid' => 4,
            'currency' => 1,
            'currency_code' => 'BRL',
            'datecreated' => '2026-01-02',
            'stats' => ['numproducts' => 2],
        ], $data);
    }

    public function test_get_client_full_view_keeps_address_and_customfields(): void
    {
        $tools = $this->makeTools(function () {
            return [
                'result' => 'success',
                'clientid' => 1,
                'firstname' => 'John',
                'address1' => '123 Main St',
                'phonenumber' => '555-1234',
                'customfields' => [['id' => 5, 'value' => 'test']],
                'cclastfour' => '4242',
                'cardlastfour' => '1111',
            ];
        });

        $data = json_decode($tools->getClient(1, 'full'), true);

        // Full view keeps address and phone
        $this->assertSame('123 Main St', $data['address1']);
        $this->assertSame('555-1234', $data['phonenumber']);
        // But strips cclastfour and cardlastfour
        $this->assertArrayNotHasKey('cclastfour', $data);
        $this->assertArrayNotHasKey('cardlastfour', $data);
        // Customfields kept (as empty or filtered list based on config)
        $this->assertArrayHasKey('customfields', $data);
    }

    public function test_get_client_rejects_invalid_fields_parameter(): void
    {
        $tools = $this->makeTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("fields deve ser 'lite' ou 'full'");
        $tools->getClient(1, 'invalid');
    }

    public function test_get_client_lite_preserves_structured_error_details(): void
    {
        $tools = $this->makeTools(fn() => [
            'result' => 'error',
            'message' => 'Client Not Found',
            'error_code' => 'client_not_found',
        ]);

        $data = json_decode($tools->getClient(999), true);

        $this->assertSame('error', $data['result']);
        $this->assertStringStartsWith('No client exists with the given id in WHMCS.', $data['message']);
        $this->assertSame('client_not_found', $data['error_code']);
    }

    public function test_list_clients_defaults_to_lite_and_full_is_explicit(): void
    {
        $tools = $this->makeTools(fn() => [
            'result' => 'success',
            'totalresults' => 1,
            'clients' => ['client' => [[
                'id' => 41,
                'firstname' => 'Ana',
                'lastname' => 'Silva',
                'companyname' => 'Empresa',
                'email' => 'ana@example.test',
                'datecreated' => '2026-01-02',
                'groupid' => 3,
                'status' => 'Active',
            ]]],
        ]);

        $lite = json_decode($tools->listClients(), true);
        $full = json_decode($tools->listClients(fields: 'full'), true);

        $this->assertSame([
            'id' => 41,
            'datecreated' => '2026-01-02',
            'groupid' => 3,
            'status' => 'Active',
        ], $lite['clients']['client'][0]);
        $this->assertSame('Ana', $full['clients']['client'][0]['firstname']);
        $this->assertSame('ana@example.test', $full['clients']['client'][0]['email']);
    }

    public function test_get_client_invoices_defaults_to_lite_and_full_is_explicit(): void
    {
        $tools = $this->makeTools(fn() => [
            'result' => 'success',
            'invoices' => ['invoice' => [[
                'id' => 7,
                'userid' => 41,
                'firstname' => 'Ana',
                'lastname' => 'Silva',
                'email' => 'ana@example.test',
                'notes' => 'texto livre',
                'total' => '99.90',
                'status' => 'Paid',
            ]]],
        ]);

        $lite = json_decode($tools->getClientInvoices(41), true);
        $full = json_decode($tools->getClientInvoices(41, fields: 'full'), true);

        $this->assertSame([
            'id' => 7,
            'userid' => 41,
            'total' => '99.90',
            'status' => 'Paid',
        ], $lite['invoices']['invoice'][0]);
        $this->assertSame('Ana', $full['invoices']['invoice'][0]['firstname']);
        $this->assertSame('texto livre', $full['invoices']['invoice'][0]['notes']);
    }

    public function test_client_list_reads_reject_invalid_fields(): void
    {
        $tools = $this->makeTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("fields deve ser 'lite' ou 'full'");
        $tools->listClients(fields: 'pii');
    }

}
