<?php
// src/Tools/QuoteTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\AuditMetadata;
use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\AuthorizationException;
use NtMcp\Whmcs\DownstreamFailureException;
use NtMcp\Whmcs\DateNormalizer;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\LocalizedDate;
use NtMcp\Whmcs\PaymentGatewayDirectory;
use NtMcp\Whmcs\Diagnostics;
use Mcp\Capability\Attribute\McpTool;

class QuoteTools
{
    private ?PaymentGatewayDirectory $gateways;
    private ?LocalizedDate $localizedDate;

    public function __construct(
        private readonly LocalApiClient $api,
        ?PaymentGatewayDirectory $gateways = null,
        ?LocalizedDate $localizedDate = null,
    ) {
        $this->gateways = $gateways;
        $this->localizedDate = $localizedDate;
    }

    /** Introspecção read-only de gateways; instanciada sob demanda. */
    private function gateways(): PaymentGatewayDirectory
    {
        return $this->gateways ??= new PaymentGatewayDirectory();
    }

    private function localizedDate(): LocalizedDate
    {
        return $this->localizedDate ??= new LocalizedDate();
    }

    /**
     * `CreateQuote`/`UpdateQuote` documentam `datecreated` e `validuntil` como
     * "localised format (eg DD/MM/YYYY)" — ao contrário de `GetQuotes`, que
     * documenta `Y-m-d`. Convertemos para o formato localizado efetivo da
     * instalação.
     *
     * D9: a ENTRADA pública é sempre não ambígua — `Y-m-d` ou ISO-8601
     * date-time. Data já localizada não é aceita, porque `10/08/2026` não pode
     * ser distinguido entre 10 de agosto e 8 de outubro sem adivinhar a
     * configuração da instalação, e errar aqui muda a data de vencimento de uma
     * cotação sem nenhum erro visível.
     *
     * `datecreated` tem ainda o schema `date-time` da SDK, que recusa `Y-m-d`
     * antes da tool; `validuntil` aceita as duas formas. A assimetria é
     * limitação do heurístico da v1 e some no T2 — ambas continuam não
     * ambíguas, então não é bug de contrato.
     */
    private function toLocalized(string $value, string $field): string
    {
        return $this->localizedDate()->fromPublicInput($value, $field);
    }

    /**
     * @param string $datecreated  Filtro por data de criação. Aceita YYYY-MM-DD ou ISO-8601 date-time (ex.: 2026-08-10 ou 2026-08-10T00:00:00Z). Formatos localizados como DD/MM/YYYY nao sao aceitos por serem ambiguos.
     * @param string $lastmodified Filtro por data da última modificação. Mesmas formas aceitas de datecreated.
     */
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
        if ($datecreated !== '') $params['datecreated'] = DateNormalizer::toWhmcsDate($datecreated, 'datecreated');
        // `GetQuotes.lastmodified` também é documentado como `Y-m-d`. Passava
        // cru, então uma forma localizada atravessava sem erro — D9 estava
        // incompleta justamente no campo que ninguém olhou.
        if ($lastmodified !== '') $params['lastmodified'] = DateNormalizer::toWhmcsDate($lastmodified, 'lastmodified');
        return json_encode($this->api->call('GetQuotes', $params), JSON_PRETTY_PRINT);
    }

    #[McpTool(name: 'whmcs_get_quote', description: 'Obtém detalhes de um orçamento')]
    public function getQuote(int $quoteid): string
    {
        return json_encode($this->api->call('GetQuotes', ['quoteid' => $quoteid]), JSON_PRETTY_PRINT);
    }

    /**
     * @param string $validuntil  Validade do orçamento. Aceita YYYY-MM-DD ou ISO-8601 date-time (ex.: 2026-08-10 ou 2026-08-10T00:00:00Z). Formatos localizados como DD/MM/YYYY nao sao aceitos por serem ambiguos.
     * @param string $datecreated Data de criação. Aceita YYYY-MM-DD ou ISO-8601 date-time (ex.: 2026-08-10 ou 2026-08-10T00:00:00Z). Formatos localizados como DD/MM/YYYY nao sao aceitos por serem ambiguos.
     */
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
        if ($validuntil !== '') $params['validuntil'] = $this->toLocalized($validuntil, 'validuntil');
        if ($currencyid > 0) $params['currency'] = $currencyid;
        if ($datecreated !== '') $params['datecreated'] = $this->toLocalized($datecreated, 'datecreated');
        if ($customernotes !== '') $params['customernotes'] = $customernotes;
        if ($adminnotes !== '') $params['adminnotes'] = $adminnotes;
        $this->addSerializedLineItems($params, $lineitems);
        return json_encode($this->api->call('CreateQuote', $params), JSON_PRETTY_PRINT);
    }

    /**
     * @param string $validuntil  Validade do orçamento. Aceita YYYY-MM-DD ou ISO-8601 date-time (ex.: 2026-08-10 ou 2026-08-10T00:00:00Z). Formatos localizados como DD/MM/YYYY nao sao aceitos por serem ambiguos.
     * @param string $datecreated Data de criação. Aceita YYYY-MM-DD ou ISO-8601 date-time (ex.: 2026-08-10 ou 2026-08-10T00:00:00Z). Formatos localizados como DD/MM/YYYY nao sao aceitos por serem ambiguos.
     */
    #[McpTool(name: 'whmcs_update_quote', description: 'Atualiza campos de um orçamento existente; lineitems aceita description, quantity, unitprice, discount e taxable')]
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
        if ($validuntil !== '') $params['validuntil'] = $this->toLocalized($validuntil, 'validuntil');
        if ($datecreated !== '') $params['datecreated'] = $this->toLocalized($datecreated, 'datecreated');
        if ($customernotes !== '') $params['customernotes'] = $customernotes;
        if ($adminnotes !== '') $params['adminnotes'] = $adminnotes;
        $this->addSerializedLineItems($params, $lineitems);
        return json_encode($this->api->call('UpdateQuote', $params), JSON_PRETTY_PRINT);
    }

    /**
     * @param string $validuntil  Override da validade. Aceita YYYY-MM-DD ou ISO-8601 date-time (ex.: 2026-08-10 ou 2026-08-10T00:00:00Z). Formatos localizados como DD/MM/YYYY nao sao aceitos por serem ambiguos.
     * @param string $datecreated Override da data de criação. Aceita YYYY-MM-DD ou ISO-8601 date-time (ex.: 2026-08-10 ou 2026-08-10T00:00:00Z). Formatos localizados como DD/MM/YYYY nao sao aceitos por serem ambiguos.
     */
    #[McpTool(name: 'whmcs_duplicate_quote', description: 'Duplica uma cotação existente, com overrides opcionais')]
    public function duplicateQuote(
        int $quoteid,
        string $subject = '',
        string $stage = '',
        string $validuntil = '',
        string $datecreated = '',
        int $userid = 0,
        string $proposal = '',
        string $customernotes = '',
        string $adminnotes = ''
    ): string {
        $sourceResponse = $this->api->call('GetQuotes', ['quoteid' => $quoteid]);
        if (($sourceResponse['result'] ?? '') === 'error') {
            return json_encode($sourceResponse, JSON_PRETTY_PRINT);
        }

        $source = $this->extractQuote($sourceResponse, $quoteid);
        if ($source === null) {
            return json_encode([
                'result' => 'error',
                'quoteid' => $quoteid,
                'message' => 'Quote not found',
            ], JSON_PRETTY_PRINT);
        }

        $newQuote = [
            'subject' => $subject !== '' ? $subject : (string)($source['subject'] ?? ''),
            'stage' => $stage !== '' ? $stage : (string)($source['stage'] ?? ''),
            'proposal' => $proposal !== '' ? $proposal : (string)($source['proposal'] ?? ''),
        ];

        $sourceUserId = $this->intFromQuote($source, ['userid', 'clientid']);
        $targetUserId = $userid > 0 ? $userid : $sourceUserId;
        if ($targetUserId > 0) {
            $newQuote['userid'] = $targetUserId;
        } else {
            foreach (['firstname', 'lastname', 'companyname', 'email', 'address1', 'address2', 'city', 'state', 'postcode', 'country', 'phonenumber', 'tax_id'] as $field) {
                if (isset($source[$field]) && $source[$field] !== '') {
                    $newQuote[$field] = $source[$field];
                }
            }
        }

        $currency = $this->intFromQuote($source, ['currency', 'currencyid']);
        if ($currency > 0) {
            $newQuote['currency'] = $currency;
        }

        // O destino é CreateQuote, que exige data LOCALIZADA — tanto no override
        // explícito quanto no valor HERDADO de GetQuotes (que responde em Y-m-d).
        // Ambos passam pela mesma ponte, senão a duplicação reintroduz o formato
        // errado justamente pelo caminho que ninguém digita.
        $validUntilValue = $validuntil !== '' ? $validuntil : (string)($source['validuntil'] ?? '');
        if ($validUntilValue !== '') {
            $newQuote['validuntil'] = $this->toLocalized($validUntilValue, 'validuntil');
        }

        $dateCreatedValue = $datecreated !== '' ? $datecreated : (string)($source['datecreated'] ?? '');
        if ($dateCreatedValue !== '') {
            $newQuote['datecreated'] = $this->toLocalized($dateCreatedValue, 'datecreated');
        }

        $customerNotesValue = $customernotes !== '' ? $customernotes : (string)($source['customernotes'] ?? '');
        if ($customerNotesValue !== '') {
            $newQuote['customernotes'] = $customerNotesValue;
        }

        $adminNotesValue = $adminnotes !== '' ? $adminnotes : (string)($source['adminnotes'] ?? '');
        if ($adminNotesValue !== '') {
            $newQuote['adminnotes'] = $adminNotesValue;
        }

        $this->addSerializedLineItems($newQuote, $this->extractLineItems($source, includeIds: false));

        return json_encode($this->api->call('CreateQuote', $newQuote), JSON_PRETTY_PRINT);
    }

    /**
     * FINANCIAL. A operação NÃO é transacional: `AcceptQuote` gera a fatura e
     * `UpdateInvoice` aplica as opções de cobrança num segundo efeito. Por isso:
     *
     *  - TODOS os argumentos são validados antes do primeiro efeito, incluindo
     *    o system name do gateway em `paymentmethod` (que só seria descoberto
     *    inválido no segundo passo, já com a conversão persistida);
     *  - o estado atual da cotação é lido antes de converter, para reduzir
     *    repetição acidental (a operação NÃO é idempotente);
     *  - a partir do primeiro efeito, QUALQUER desfecho ruim — erro em array,
     *    exceção, retorno indeterminado — vira `result: "error"` com
     *    `partial: true`, os IDs conhecidos e um aviso explícito de que a tool
     *    não deve ser repetida automaticamente. Recusas de AUTORIZAÇÃO são a
     *    única exceção: elas não produzem efeito, então sobem intactas.
     *
     * A conversão não produz efeito COMMS: não existe parâmetro `sendinvoice` e
     * `publishandsendemail` nunca é enviado.
     */
    #[McpTool(name: 'whmcs_convert_quote_to_invoice', description: 'Converte uma cotação em fatura e aplica opções de cobrança quando fornecidas. Não envia e-mail. Operação não idempotente: não repetir automaticamente em caso de falha parcial. Datas aceitam YYYY-MM-DD ou date-time ISO-8601.')]
    public function convertQuoteToInvoice(
        int $quoteid,
        string $paymentmethod = '',
        string $duedate = '',
        ?float $taxrate = null
    ): string {
        // --- Validação completa antes de qualquer efeito persistente. ---
        if ($quoteid <= 0) {
            throw new \InvalidArgumentException('quoteid must be a positive integer');
        }
        $duedate = DateNormalizer::optional($duedate, 'duedate');
        if ($taxrate !== null && ($taxrate < 0 || $taxrate > 100)) {
            throw new \InvalidArgumentException('taxrate must be between 0 and 100');
        }
        if ($paymentmethod !== '') {
            // Read-only e fail-closed: se a introspecção não estiver disponível
            // ou for inválida, a exceção sobe e NADA é convertido. O retorno é o
            // system name EXATO do banco — só ele segue para o UpdateInvoice, e
            // nunca a capitalização que o chamador digitou.
            $paymentmethod = $this->gateways()->resolve($paymentmethod, 'paymentmethod');
        }

        // --- Guarda de repetição: leitura do estado atual (READ) antes de mutar. ---
        $currentResponse = $this->api->call('GetQuotes', ['quoteid' => $quoteid]);
        if (($currentResponse['result'] ?? '') === 'error') {
            if (!isset($currentResponse['quoteid'])) {
                $currentResponse['quoteid'] = $quoteid;
            }
            return json_encode($currentResponse, JSON_PRETTY_PRINT);
        }

        $current = $this->extractQuote($currentResponse, $quoteid);
        if ($current === null) {
            return json_encode([
                'result' => 'error',
                'quoteid' => $quoteid,
                'message' => 'Quote not found',
            ], JSON_PRETTY_PRINT);
        }

        $currentStage = (string)($current['stage'] ?? '');
        if (strcasecmp($currentStage, 'Accepted') === 0) {
            return json_encode([
                'result' => 'error',
                'quoteid' => $quoteid,
                'stage' => $currentStage,
                'message' => 'Quote is already in stage "Accepted" and was not converted again',
                'warning' => 'Esta operação não é idempotente. A cotação já foi aceita — converter de novo geraria efeito financeiro duplicado. Verifique a fatura existente antes de agir.',
            ], JSON_PRETTY_PRINT);
        }

        // --- Efeito 1: aceita a cotação e gera a fatura. ---
        // Uma exceção aqui é INDETERMINADA: o WHMCS pode ter persistido a
        // aceitação e falhado depois (transporte, timeout, retorno não-array).
        // Tratar como parcial é a leitura conservadora — o cliente precisa
        // conferir antes de repetir.
        try {
            $convertResponse = $this->api->call('AcceptQuote', ['quoteid' => $quoteid]);
        } catch (AuthorizationException $e) {
            throw $e; // negado antes de qualquer efeito: não é parcial
        } catch (\Throwable $e) {
            // F2: a exceção NÃO entra no payload MCP, no Activity Log nem no
            // error_log. O contrato parcial preserva flag, IDs, aviso e
            // correlação; do detalhe só saem classe e fingerprint.
            return self::partialFailure(
                $quoteid,
                null,
                'Quote conversion failed in an indeterminate state. The quote MAY have been accepted.',
                detail: $e
            );
        }

        if (($convertResponse['result'] ?? '') === 'error') {
            // M2: a chamada JÁ FOI FEITA. Um error-array não prova que nada
            // aconteceu — só prova que o WHMCS respondeu com erro, e o efeito
            // pode ter sido persistido antes da resposta. Devolver o erro puro
            // dizia ao cliente "não houve efeito", que é a afirmação que não
            // podemos fazer. Estado indeterminado, como no ramo de exceção.
            return self::partialFailure(
                $quoteid,
                null,
                'Quote conversion returned an error after the call was made. '
                . 'The quote MAY have been accepted.',
                causalCorrelationId: self::correlationFrom($convertResponse),
                errorCode: is_string($convertResponse['error_code'] ?? null) ? $convertResponse['error_code'] : null,
            );
        }

        $invoiceId = isset($convertResponse['invoiceid']) ? (int)$convertResponse['invoiceid'] : 0;
        $hasInvoiceOptions = $paymentmethod !== '' || $duedate !== '' || $taxrate !== null;

        // A partir daqui a cotação JÁ foi aceita: qualquer falha é parcial.
        if ($hasInvoiceOptions && $invoiceId <= 0) {
            return self::partialFailure(
                $quoteid,
                null,
                'Quote converted, but WHMCS did not return invoiceid; invoice options could not be applied'
            );
        }

        if ($hasInvoiceOptions) {
            // --- Efeito 2: aplica as opções de cobrança na fatura gerada. ---
            $invoiceParams = ['invoiceid' => $invoiceId];
            if ($paymentmethod !== '') {
                $invoiceParams['paymentmethod'] = $paymentmethod;
            }
            if ($duedate !== '') {
                $invoiceParams['duedate'] = $duedate;
            }
            if ($taxrate !== null) {
                $invoiceParams['taxrate'] = $taxrate;
            }

            try {
                $invoiceResponse = $this->api->call('UpdateInvoice', $invoiceParams);
            } catch (\Throwable $e) {
                // Inclui AuthorizationException: a conversão já ocorreu, então
                // o contrato parcial vale mesmo que o segundo passo tenha sido
                // NEGADO — o efeito financeiro existe e precisa ser reportado.
                return self::partialFailure(
                    $quoteid,
                    $invoiceId,
                    'Quote converted, but invoice update failed.',
                    detail: $e
                );
            }

            if (($invoiceResponse['result'] ?? '') === 'error') {
                // Nada da resposta é ecoado, MAS a correlação causal é
                // reaproveitada: gerar uma segunda desligava o payload do
                // diagnóstico que a LocalAPI já havia emitido, e o operador
                // ficava com dois identificadores sem ligação.
                return self::partialFailure(
                    $quoteid,
                    $invoiceId,
                    'Quote converted, but invoice update failed.',
                    causalCorrelationId: self::correlationFrom($invoiceResponse),
                    errorCode: is_string($invoiceResponse['error_code'] ?? null) ? $invoiceResponse['error_code'] : null,
                );
            }
        }

        $convertResponse['quoteid'] = $quoteid;
        if ($invoiceId > 0) {
            $convertResponse['invoiceid'] = $invoiceId;
        }

        return json_encode($convertResponse, JSON_PRETTY_PRINT);
    }

    /** Aviso obrigatório quando um efeito parcial já foi persistido. */
    private const NO_RETRY_WARNING = 'NÃO repetir esta tool automaticamente: a cotação já foi aceita e o efeito financeiro já existe. Repetir criaria cobrança duplicada. Revise a fatura no WHMCS e ajuste-a manualmente.';

    /**
     * Contrato ÚNICO de falha depois que um efeito financeiro pode ter ocorrido.
     * Toda rota pós-efeito passa por aqui para que nenhuma delas perca
     * `partial`, os IDs conhecidos ou o aviso de não-retry.
     *
     * `$message` é SEMPRE texto estável escrito aqui. `$detail` é a exceção
     * downstream e NUNCA é serializada — nem no payload MCP, nem no Activity
     * Log, nem no error_log; dela só saem classe e fingerprint.
     *
     * @param int|null $invoiceId null quando a fatura é desconhecida
     */
    /**
     * Extrai a correlação de uma resposta de erro da LocalAPI, se ela for
     * sintaticamente válida. Só o formato canônico é aceito — nada vindo da
     * resposta é usado sem validação.
     */
    private static function correlationFrom(mixed $response): ?string
    {
        $candidate = is_array($response) ? ($response['correlation_id'] ?? null) : null;

        return is_string($candidate) && preg_match('/^[0-9a-f]{8}\z/', $candidate) === 1
            ? $candidate
            : null;
    }

    private static function partialFailure(
        int $quoteid,
        ?int $invoiceId,
        string $message,
        ?\Throwable $detail = null,
        ?string $causalCorrelationId = null,
        ?string $errorCode = null,
    ): string {
        $payload = [
            'result' => 'error',
            'partial' => true,
            'quoteid' => $quoteid,
        ];
        if ($invoiceId !== null && $invoiceId > 0) {
            $payload['invoiceid'] = $invoiceId;
        }
        $payload['message'] = $message;
        $payload['warning'] = self::NO_RETRY_WARNING;
        if ($errorCode !== null && preg_match('/^[a-z][a-z0-9_]{0,63}\z/', $errorCode) === 1) {
            // Código do nosso enum (D6) — nunca texto do WHMCS.
            $payload['error_code'] = $errorCode;
        }

        // A correlação da CAUSA é reaproveitada quando existe — tanto do ramo
        // de exceção (wrapper) quanto do ramo de error-array (resposta da
        // LocalAPI). Gerar uma nova desligava o payload do diagnóstico que a
        // LocalAPI já havia emitido, e o operador ficava com dois
        // identificadores sem ligação.
        $causeCorrelation = $causalCorrelationId;
        if ($causeCorrelation === null && $detail instanceof DownstreamFailureException && $detail->correlationId !== '') {
            $causeCorrelation = $detail->correlationId;
        }

        $correlationId = LocalApiClient::auditLog(
            ActivityEvent::PARTIAL_FINANCIAL,
            AuditMetadata::ids(['quoteid' => $quoteid, 'invoiceid' => $invoiceId]),
            $causeCorrelation
        );
        $payload['correlation_id'] = $correlationId;

        if ($detail !== null) {
            // Fingerprint SEMPRE da causa. Fingerprintar a wrapper produzia
            // valores diferentes para a mesma causa, porque a mensagem dela
            // carrega uma correlação aleatória.
            $isWrapped = $detail instanceof DownstreamFailureException;

            Diagnostics::logWithFingerprint(
                $correlationId,
                Diagnostics::CATEGORY_PARTIAL_EFFECT,
                'convert_quote_to_invoice',
                $isWrapped ? $detail->causeFingerprint : Diagnostics::fingerprint($detail->getMessage()),
                $isWrapped ? $detail->causeClass : get_class($detail)
            );
        }

        return json_encode($payload, JSON_PRETTY_PRINT);
    }

    /**
     * DESTRUCTIVE. A exclusão é irreversível, portanto exige o gate DESTRUCTIVE
     * (desligado por padrão) E `confirm=true` como defesa adicional.
     */
    #[McpTool(name: 'whmcs_delete_quote', description: 'Exclui uma cotação existente. Irreversível: exige confirm=true e o gate DESTRUCTIVE.')]
    public function deleteQuote(int $quoteid, bool $confirm): string
    {
        if ($confirm !== true) {
            // m1: esta recusa retorna ANTES do cliente central, então precisa
            // auditar aqui — senão a tentativa não deixa rastro nenhum.
            LocalApiClient::auditLog(
                ActivityEvent::CONFIRM_REQUIRED,
                AuditMetadata::ids(['quoteid' => $quoteid])
            );

            return json_encode([
                'result' => 'error',
                'quoteid' => $quoteid,
                'message' => 'Deletion requires confirm=true',
            ], JSON_PRETTY_PRINT);
        }

        $response = $this->api->call('DeleteQuote', ['quoteid' => $quoteid]);
        if (!isset($response['quoteid'])) {
            $response['quoteid'] = $quoteid;
        }

        return json_encode($response, JSON_PRETTY_PRINT);
    }

    // ---------------------------------------------------------------
    // Helpers (não invocáveis como tool — sem #[McpTool])
    // ---------------------------------------------------------------

    private function addSerializedLineItems(array &$params, array $lineitems): void
    {
        if ($lineitems === []) {
            return;
        }

        $normalized = [];
        $allowedKeys = ['id', 'description', 'quantity', 'unitprice', 'discount', 'taxable', 'desc', 'qty', 'up'];
        foreach ($lineitems as $i => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("lineitems[{$i}] must be an associative array");
            }

            foreach ($item as $key => $value) {
                if (!in_array($key, $allowedKeys, true)) {
                    throw new \InvalidArgumentException("lineitems[{$i}][{$key}] is not an allowed key. Allowed: " . implode(', ', $allowedKeys));
                }
                if (!is_scalar($value)) {
                    throw new \InvalidArgumentException("lineitems[{$i}][{$key}] must be a scalar value");
                }
            }

            $line = [];
            $this->copyLineItemValue($line, $item, 'id', 'id');
            $this->copyLineItemValue($line, $item, 'description', 'desc', 'desc');
            $this->copyLineItemValue($line, $item, 'quantity', 'qty', 'qty');
            $this->copyLineItemValue($line, $item, 'unitprice', 'up', 'up');
            $this->copyLineItemValue($line, $item, 'discount', 'discount');
            $this->copyLineItemValue($line, $item, 'taxable', 'taxable');

            if ($line === []) {
                throw new \InvalidArgumentException("lineitems[{$i}] must contain at least one supported field");
            }

            $normalized[] = $line;
        }

        $params['lineitems'] = base64_encode(serialize($normalized));
    }

    private function copyLineItemValue(array &$target, array $source, string $publicKey, string $whmcsKey, ?string $alternateKey = null): void
    {
        if (array_key_exists($publicKey, $source)) {
            $target[$whmcsKey] = $source[$publicKey];
            return;
        }

        if ($alternateKey !== null && array_key_exists($alternateKey, $source)) {
            $target[$whmcsKey] = $source[$alternateKey];
        }
    }

    private function extractQuote(array $response, int $quoteid): ?array
    {
        $quotes = $response['quotes']['quote'] ?? $response['quote'] ?? [];
        if (!is_array($quotes) || $quotes === []) {
            return null;
        }

        if (!array_is_list($quotes)) {
            $quotes = [$quotes];
        }

        foreach ($quotes as $quote) {
            if (!is_array($quote)) {
                continue;
            }

            $id = $this->intFromQuote($quote, ['id', 'quoteid']);
            if ($id === $quoteid || $id === 0) {
                return $quote;
            }
        }

        return null;
    }

    private function extractLineItems(array $quote, bool $includeIds): array
    {
        $items = $quote['lineitems']['lineitem'] ?? $quote['lineitems'] ?? $quote['lineitem'] ?? [];
        if (!is_array($items) || $items === []) {
            return [];
        }

        if (!array_is_list($items)) {
            $items = [$items];
        }

        $lineitems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $line = [];
            if ($includeIds && isset($item['id']) && $item['id'] !== '') {
                $line['id'] = $item['id'];
            }
            if (isset($item['description'])) {
                $line['description'] = $item['description'];
            } elseif (isset($item['desc'])) {
                $line['description'] = $item['desc'];
            }
            if (isset($item['quantity'])) {
                $line['quantity'] = $item['quantity'];
            } elseif (isset($item['qty'])) {
                $line['quantity'] = $item['qty'];
            }
            if (isset($item['unitprice'])) {
                $line['unitprice'] = $item['unitprice'];
            } elseif (isset($item['up'])) {
                $line['unitprice'] = $item['up'];
            }
            if (isset($item['discount'])) {
                $line['discount'] = $item['discount'];
            }
            if (isset($item['taxable'])) {
                $line['taxable'] = $item['taxable'];
            }

            if ($line !== []) {
                $lineitems[] = $line;
            }
        }

        return $lineitems;
    }

    private function intFromQuote(array $quote, array $keys): int
    {
        foreach ($keys as $key) {
            if (isset($quote[$key]) && is_numeric($quote[$key])) {
                return (int)$quote[$key];
            }
        }

        return 0;
    }
}
