<?php
// src/Tools/QuoteTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\LocalApiClient;
use PhpMcp\Server\Attributes\McpTool;

class QuoteTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_list_quotes', description: 'Lista orçamentos com filtros')]
    public function listQuotes(
        int $clientid = 0,
        int $quoteid = 0,
        int $limitstart = 0,
        int $limitnum = 25,
        string $subject = '',
        string $stage = '',
        string $datecreated = '',
        string $lastmodified = ''
    ): string {
        $params = ['limitstart' => $limitstart, 'limitnum' => $limitnum];
        if ($clientid > 0) $params['userid'] = $clientid;
        if ($quoteid > 0) $params['quoteid'] = $quoteid;
        if ($subject !== '') $params['subject'] = $subject;
        if ($stage !== '') $params['stage'] = $stage;
        if ($datecreated !== '') $params['datecreated'] = $datecreated;
        if ($lastmodified !== '') $params['lastmodified'] = $lastmodified;
        return json_encode($this->api->call('GetQuotes', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_quote', description: 'Obtém detalhes de um orçamento')]
    public function getQuote(int $quoteid): string
    {
        return json_encode($this->api->call('GetQuotes', ['quoteid' => $quoteid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_create_quote', description: 'Cria um novo orçamento')]
    public function createQuote(
        string $subject,
        string $stage,
        string $proposal,
        int $userid = 0,
        string $validuntil = '',
        int $currencyid = 0,
        string $datecreated = '',
        string $customernotes = '',
        string $adminnotes = '',
        array $lineitems = []
    ): string {
        $params = ['subject' => $subject, 'stage' => $stage, 'proposal' => $proposal];
        if ($userid > 0) $params['userid'] = $userid;
        if ($validuntil !== '') $params['validuntil'] = $validuntil;
        if ($currencyid > 0) $params['currencyid'] = $currencyid;
        if ($datecreated !== '') $params['datecreated'] = $datecreated;
        if ($customernotes !== '') $params['customernotes'] = $customernotes;
        if ($adminnotes !== '') $params['adminnotes'] = $adminnotes;
        $allowedKeys = ['description', 'quantity', 'unitprice', 'discount', 'taxable'];
        foreach ($lineitems as $i => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("lineitems[{$i}] must be an associative array");
            }
            foreach ($item as $key => $value) {
                if (!is_scalar($value)) {
                    throw new \InvalidArgumentException("lineitems[{$i}][{$key}] must be a scalar value");
                }
                if (!in_array($key, $allowedKeys, true)) {
                    throw new \InvalidArgumentException("lineitems[{$i}][{$key}] is not an allowed key. Allowed: " . implode(', ', $allowedKeys));
                }
                $params["lineitems[{$i}][{$key}]"] = $value;
            }
        }
        return json_encode($this->api->call('CreateQuote', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_update_quote', description: 'Atualiza um orçamento existente')]
    public function updateQuote(
        int $quoteid,
        string $subject = '',
        string $stage = '',
        string $proposal = '',
        string $validuntil = '',
        string $datecreated = '',
        string $customernotes = '',
        string $adminnotes = '',
        array $lineitems = []
    ): string {
        $params = ['quoteid' => $quoteid];
        if ($subject !== '') $params['subject'] = $subject;
        if ($stage !== '') $params['stage'] = $stage;
        if ($proposal !== '') $params['proposal'] = $proposal;
        if ($validuntil !== '') $params['validuntil'] = $validuntil;
        if ($datecreated !== '') $params['datecreated'] = $datecreated;
        if ($customernotes !== '') $params['customernotes'] = $customernotes;
        if ($adminnotes !== '') $params['adminnotes'] = $adminnotes;
        $allowedKeys = ['description', 'quantity', 'unitprice', 'discount', 'taxable'];
        foreach ($lineitems as $i => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("lineitems[{$i}] must be an associative array");
            }
            foreach ($item as $key => $value) {
                if (!is_scalar($value)) {
                    throw new \InvalidArgumentException("lineitems[{$i}][{$key}] must be a scalar value");
                }
                if (!in_array($key, $allowedKeys, true)) {
                    throw new \InvalidArgumentException("lineitems[{$i}][{$key}] is not an allowed key. Allowed: " . implode(', ', $allowedKeys));
                }
                $params["lineitems[{$i}][{$key}]"] = $value;
            }
        }
        return json_encode($this->api->call('UpdateQuote', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_send_quote', description: 'Envia um orçamento por e-mail ao cliente')]
    public function sendQuote(int $quoteid): string
    {
        return json_encode($this->api->call('SendQuote', ['quoteid' => $quoteid]), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_accept_quote', description: 'Aceita um orçamento')]
    public function acceptQuote(int $quoteid): string
    {
        return json_encode($this->api->call('AcceptQuote', ['quoteid' => $quoteid]), JSON_PRETTY_PRINT);
    }
}
