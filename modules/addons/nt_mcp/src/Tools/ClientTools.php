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
        int $limitnum = 25,
        string $status = '',
        string $sorting = '',
        string $orderby = ''
    ): string {
        $params = ['limitstart' => $limitstart, 'limitnum' => $limitnum];
        if ($search !== '') $params['search'] = $search;
        if ($status !== '') $params['status'] = $status;
        if ($sorting !== '') $params['sorting'] = $sorting;
        if ($orderby !== '') $params['orderby'] = $orderby;

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
        description: 'Cria um novo cliente no WHMCS. Aceita customfields como JSON object mapeando field ID ao valor, ex: {"4":"valor","134":"valor"}'
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
        string $phonenumber = '',
        string $customfields = '',
        string $companyname = '',
        string $address2 = '',
        string $notes = '',
        string $tax_id = '',
        bool $noemail = false
    ): string {
        $params = compact('firstname', 'lastname', 'email', 'password2');
        foreach (['address1', 'city', 'state', 'postcode', 'country', 'phonenumber', 'companyname', 'address2', 'notes', 'tax_id'] as $field) {
            if ($$field !== '') {
                $params[$field] = $$field;
            }
        }
        if ($customfields !== '') {
            $params['customfields'] = self::validateAndEncodeCustomFields($customfields);
        }
        if ($noemail) {
            $params['noemail'] = true;
        }
        return json_encode($this->api->call('AddClient', $params), JSON_PRETTY_PRINT);
    }

    /**
     * SECURITY FIX (F3 -- CVSS 9.1): Replace open-ended array $fields with
     * explicit named parameters.  The previous signature allowed callers to
     * set security-sensitive fields such as password2, credit, status,
     * securityqans, and permissions through mass-assignment.
     */
    #[McpTool(name: 'whmcs_update_client', description: 'Atualiza dados de um cliente existente')]
    public function updateClient(
        int $clientid,
        string $firstname = '',
        string $lastname = '',
        string $email = '',
        string $address1 = '',
        string $address2 = '',
        string $city = '',
        string $state = '',
        string $postcode = '',
        string $country = '',
        string $phonenumber = '',
        string $companyname = '',
        string $notes = '',
        string $language = '',
        string $paymentmethod = '',
        int $groupid = 0,
        string $customfields = ''
    ): string {
        $params = ['clientid' => $clientid];

        // Only include parameters that were explicitly provided (non-empty).
        foreach ([
            'firstname', 'lastname', 'email',
            'address1', 'address2', 'city', 'state', 'postcode',
            'country', 'phonenumber', 'companyname',
            'notes', 'language', 'paymentmethod',
        ] as $field) {
            if ($$field !== '') {
                $params[$field] = $$field;
            }
        }
        if ($groupid > 0) {
            $params['groupid'] = $groupid;
        }
        if ($customfields !== '') {
            $params['customfields'] = self::validateAndEncodeCustomFields($customfields);
        }

        return json_encode($this->api->call('UpdateClient', $params), JSON_PRETTY_PRINT);
    }

    /**
     * SECURITY FIX (S2A-02): Validate custom fields to prevent oversized
     * payloads and non-scalar values.  Use json_encode instead of serialize
     * to avoid latent deserialization surface in the WHMCS processing pipeline.
     */
    private static function validateAndEncodeCustomFields(string $customfields): string
    {
        $decoded = json_decode($customfields, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(
                'customfields must be a valid JSON object, got: ' . json_last_error_msg()
            );
        }
        if (count($decoded) > 50 || strlen($customfields) > 8192) {
            throw new \InvalidArgumentException('customfields exceeds size limits (max 50 fields, 8KB)');
        }
        foreach ($decoded as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException(
                    'customfields values must be scalars, got non-scalar for key: ' . $key
                );
            }
        }
        return base64_encode(json_encode($decoded));
    }

    #[McpTool(name: 'whmcs_close_client', description: 'Fecha/cancela a conta de um cliente')]
    public function closeClient(int $clientid): string
    {
        return json_encode($this->api->call('CloseClient', ['clientid' => $clientid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_client_products', description: 'Lista produtos/serviços ativos de um cliente')]
    public function getClientProducts(int $clientid): string
    {
        $result = $this->api->call('GetClientsProducts', ['clientid' => $clientid]);
        if (isset($result['products']['product'])) {
            foreach ($result['products']['product'] as &$p) {
                unset($p['password']);
            }
            unset($p);
        }
        return json_encode($result, JSON_PRETTY_PRINT);
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
