<?php
// src/Tools/ClientTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\ResponseRedactor;
use NtMcp\Whmcs\ToolJson;
use Mcp\Capability\Attribute\McpTool;

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

        return ToolJson::encode($this->api->call('GetClients', $params));
    }

    #[McpTool(
        name: 'whmcs_get_client',
        description: 'Obtém detalhes completos de um cliente por ID'
    )]
    public function getClient(int $clientid): string
    {
        $result = $this->api->call('GetClientsDetails', ['clientid' => $clientid, 'stats' => true]);
        ResponseRedactor::stripClientDetails($result);
        return ToolJson::encode($result);
    }

    /**
     * A classe primária é WRITE. `notify_client` é um efeito ORTOGONAL: o
     * default é NÃO notificar (`noemail=true`); pedir `notify_client=true`
     * exige, além do gate WRITE, o gate COMMS — verificado centralmente em
     * LocalApiClient, não aqui, para que nenhuma refatoração (ou chamada
     * direta ao cliente local) contorne a autorização.
     */
    #[McpTool(
        name: 'whmcs_create_client',
        description: 'Cria um novo cliente no WHMCS. Aceita customfields como JSON object mapeando field ID ao valor, ex: {"4":"valor","134":"valor"}. notify_client=true envia o e-mail de boas-vindas e exige o gate COMMS; o default não notifica.'
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
        bool $notify_client = false
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
        if (!$notify_client) {
            $params['noemail'] = true;
        }
        return ToolJson::encode($this->api->call('AddClient', $params));
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

        return ToolJson::encode($this->api->call('UpdateClient', $params));
    }

    /**
     * SECURITY FIX (S2A-02): validate the caller's JSON before translating it
     * to the legacy wire format required by AddClient/UpdateClient.
     *
     * WHMCS requires a Base64-encoded PHP array here. Base64(JSON) looks
     * plausible but is not decoded by the LocalAPI, so every required custom
     * field remains empty. `serialize()` is safe at this boundary because the
     * decoded tree is capped and restricted to arrays of scalar/null values;
     * no caller-supplied object can enter the serialized payload, and this
     * connector never unserializes it.
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
        return base64_encode(serialize($decoded));
    }

    #[McpTool(name: 'whmcs_get_client_products', description: 'Lista produtos/serviços ativos de um cliente')]
    public function getClientProducts(int $clientid): string
    {
        $result = $this->api->call('GetClientsProducts', ['clientid' => $clientid]);
        ResponseRedactor::stripProductPasswords($result);
        return ToolJson::encode($result);
    }

    #[McpTool(name: 'whmcs_get_client_domains', description: 'Lista domínios de um cliente')]
    public function getClientDomains(int $clientid): string
    {
        return ToolJson::encode($this->api->call('GetClientsDomains', ['clientid' => $clientid]));
    }

    #[McpTool(name: 'whmcs_get_client_invoices', description: 'Lista faturas de um cliente')]
    public function getClientInvoices(int $clientid, string $status = ''): string
    {
        $params = ['userid' => $clientid];
        if ($status !== '') $params['status'] = $status;
        return ToolJson::encode($this->api->call('GetInvoices', $params));
    }

    /**
     * `userid` e `clientid` são o MESMO id — a LocalAPI chama de `userid`, e o
     * resto desta superfície (e a UI do WHMCS) chama de `clientid`. Exigir
     * `userid` fazia esta tool falhar para quem acabou de ler um `clientid` em
     * qualquer outra tool. Os dois são aceitos; exatamente UM é obrigatório,
     * porque aceitar ambos abriria a pergunta de qual vence quando divergem.
     */
    #[McpTool(name: 'whmcs_get_contacts', description: 'Lista contatos/sub-contas de um cliente. Aceita clientid ou userid (são o mesmo id) — informe exatamente um.')]
    public function getContacts(int $userid = 0, int $clientid = 0, int $limitstart = 0, int $limitnum = 25): string
    {
        if (($userid > 0) === ($clientid > 0)) {
            throw new \InvalidArgumentException(
                'Provide exactly one of clientid or userid (they are the same WHMCS client id).'
            );
        }
        $params = [
            'userid'     => $userid > 0 ? $userid : $clientid,
            'limitstart' => $limitstart,
            'limitnum'   => $limitnum,
        ];
        return ToolJson::encode($this->api->call('GetContacts', $params));
    }

    #[McpTool(name: 'whmcs_add_contact', description: 'Adiciona um contato/sub-conta a um cliente')]
    public function addContact(
        int $clientid,
        string $firstname = '',
        string $lastname = '',
        string $email = '',
        string $address1 = '',
        string $address2 = '',
        string $city = '',
        string $state = '',
        string $postcode = '',
        string $country = 'BR',
        string $phonenumber = '',
        string $companyname = '',
        bool $generalemails = true,
        bool $invoiceemails = true,
        bool $productemails = true,
        bool $supportemails = true
    ): string {
        $params = ['clientid' => $clientid];
        foreach (['firstname', 'lastname', 'email', 'address1', 'address2', 'city', 'state', 'postcode', 'country', 'phonenumber', 'companyname'] as $field) {
            if ($$field !== '') $params[$field] = $$field;
        }
        $params['generalemails'] = $generalemails ? 1 : 0;
        $params['invoiceemails'] = $invoiceemails ? 1 : 0;
        $params['productemails'] = $productemails ? 1 : 0;
        $params['supportemails'] = $supportemails ? 1 : 0;
        return ToolJson::encode($this->api->call('AddContact', $params));
    }

    #[McpTool(name: 'whmcs_update_contact', description: 'Atualiza um contato/sub-conta existente')]
    public function updateContact(
        int $contactid,
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
        string $companyname = ''
    ): string {
        $params = ['contactid' => $contactid];
        foreach (['firstname', 'lastname', 'email', 'address1', 'address2', 'city', 'state', 'postcode', 'country', 'phonenumber', 'companyname'] as $field) {
            if ($$field !== '') $params[$field] = $$field;
        }
        return ToolJson::encode($this->api->call('UpdateContact', $params));
    }

    #[McpTool(name: 'whmcs_get_client_groups', description: 'Lista grupos de clientes configurados')]
    public function getClientGroups(): string
    {
        return ToolJson::encode($this->api->call('GetClientGroups', []));
    }

    #[McpTool(name: 'whmcs_get_clients_addons', description: 'Lista addons contratados por clientes')]
    public function getClientsAddons(int $clientid = 0, int $serviceid = 0, int $addonid = 0): string
    {
        $params = [];
        if ($clientid > 0) $params['clientid'] = $clientid;
        if ($serviceid > 0) $params['serviceid'] = $serviceid;
        if ($addonid > 0) $params['addonid'] = $addonid;
        return ToolJson::encode($this->api->call('GetClientsAddons', $params));
    }
}
