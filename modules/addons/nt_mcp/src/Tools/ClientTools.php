<?php
// src/Tools/ClientTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use PhpMcp\Server\Attributes\McpTool;

class ClientTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(
        name: 'whmcs_list_clients',
        description: 'Lista clientes do WHMCS com filtros opcionais'
    )]
    public function listClients(
        string $search = '',
        int $limitstart = 0,
        int $limitnum = 25
    ): string {
        $params = ['limitstart' => $limitstart, 'limitnum' => $limitnum];
        if ($search !== '') $params['search'] = $search;

        return json_encode($this->api->call('GetClients', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(
        name: 'whmcs_get_client',
        description: 'Obtém detalhes completos de um cliente por ID'
    )]
    public function getClient(int $clientid): string
    {
        return json_encode(
            $this->api->call('GetClientsDetails', ['clientid' => $clientid, 'stats' => true]),
            JSON_PRETTY_PRINT
        );
    }

    #[McpTool(
        name: 'whmcs_create_client',
        description: 'Cria um novo cliente no WHMCS'
    )]
    public function createClient(
        string $firstname,
        string $lastname,
        string $email,
        string $password2,
        string $address1 = '',
        string $city = '',
        string $state = '',
        string $postcode = '',
        string $country = 'BR',
        string $phonenumber = ''
    ): string {
        return json_encode($this->api->call('AddClient', compact(
            'firstname', 'lastname', 'email', 'password2',
            'address1', 'city', 'state', 'postcode', 'country', 'phonenumber'
        )), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_update_client', description: 'Atualiza dados de um cliente existente')]
    public function updateClient(int $clientid, array $fields = []): string
    {
        return json_encode($this->api->call('UpdateClient', array_merge(['clientid' => $clientid], $fields)), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_close_client', description: 'Fecha/cancela a conta de um cliente')]
    public function closeClient(int $clientid): string
    {
        return json_encode($this->api->call('CloseClient', ['clientid' => $clientid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_client_products', description: 'Lista produtos/serviços ativos de um cliente')]
    public function getClientProducts(int $clientid): string
    {
        return json_encode($this->api->call('GetClientsProducts', ['clientid' => $clientid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_client_domains', description: 'Lista domínios de um cliente')]
    public function getClientDomains(int $clientid): string
    {
        return json_encode($this->api->call('GetClientsDomains', ['clientid' => $clientid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_client_invoices', description: 'Lista faturas de um cliente')]
    public function getClientInvoices(int $clientid, string $status = ''): string
    {
        $params = ['userid' => $clientid];
        if ($status !== '') $params['status'] = $status;
        return json_encode($this->api->call('GetInvoices', $params), JSON_PRETTY_PRINT);
    }
}
