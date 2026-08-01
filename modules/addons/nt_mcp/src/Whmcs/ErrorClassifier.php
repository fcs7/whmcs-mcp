<?php
// src/Whmcs/ErrorClassifier.php
namespace NtMcp\Whmcs;

/**
 * Traduz a resposta de erro do WHMCS num código ESTÁVEL nosso (D6).
 *
 * O contrato genérico anterior fechou o vazamento mas tornou todo erro
 * indistinguível: "e-mail já existe" e "cliente não encontrado" viravam o mesmo
 * objeto, e o chamador MCP não conseguia decidir entre corrigir o input, buscar
 * o registro existente ou desistir.
 *
 * Regras que tornam isto seguro — todas necessárias:
 *
 *  1. O casamento é por padrão **exato e ancorado** (`^...$`, sobre a mensagem
 *     normalizada). Substring frouxa classificaria errado uma mensagem cuja
 *     parte variável contivesse a frase-alvo.
 *  2. A redução ao enum é IMEDIATA: nada além do enum sai desta classe. O texto
 *     do WHMCS é inspecionado só em memória e nunca é copiado para retorno,
 *     exceção, log, contexto ou fallback.
 *  3. Desconhecido cai em `downstream`. Nunca em algo derivado do texto.
 *
 * A tabela é por comando: a mesma frase pode significar coisas diferentes em
 * comandos diferentes, e o mapeamento por comando é o que a decisão exige.
 */
final class ErrorClassifier
{
    // Enum FECHADO de categorias.
    public const NOT_FOUND = 'not_found';
    public const CONFLICT = 'conflict';
    public const VALIDATION = 'validation';
    public const DENIED = 'denied';
    public const DOWNSTREAM = 'downstream';

    private const CATEGORIES = [
        self::NOT_FOUND,
        self::CONFLICT,
        self::VALIDATION,
        self::DENIED,
        self::DOWNSTREAM,
    ];

    /**
     * Padrões ancorados por comando. Chave `*` vale para qualquer comando e é
     * consultada depois dos padrões específicos.
     *
     * Cada entrada é [regex ancorada, código, categoria]. O código é um token
     * nosso, estável, que o cliente MCP pode ramificar.
     *
     * @var array<string, array<int, array{0:string,1:string,2:string}>>
     */
    private const PATTERNS = [
        'AddClient' => [
            ['/^email address already exists\z/', 'client_email_exists', self::CONFLICT],
            ['/^a client already exists with that email address\z/', 'client_email_exists', self::CONFLICT],
            ['/^invalid email address\z/', 'client_email_invalid', self::VALIDATION],
            ['/^first name is required\z/', 'client_field_required', self::VALIDATION],
            ['/^last name is required\z/', 'client_field_required', self::VALIDATION],
            ['/^password is required\z/', 'client_field_required', self::VALIDATION],
        ],
        'UpdateClient' => [
            ['/^client not found\z/', 'client_not_found', self::NOT_FOUND],
            ['/^email address already exists\z/', 'client_email_exists', self::CONFLICT],
            ['/^invalid email address\z/', 'client_email_invalid', self::VALIDATION],
        ],
        'GetClientsDetails' => [
            ['/^client not found\z/', 'client_not_found', self::NOT_FOUND],
        ],
        'GetClientsProducts' => [
            ['/^client not found\z/', 'client_not_found', self::NOT_FOUND],
        ],
        'GetInvoice' => [
            ['/^invoice id not found\z/', 'invoice_not_found', self::NOT_FOUND],
            ['/^invoice not found\z/', 'invoice_not_found', self::NOT_FOUND],
        ],
        'UpdateInvoice' => [
            ['/^invoice id not found\z/', 'invoice_not_found', self::NOT_FOUND],
            ['/^invalid payment method\z/', 'invoice_paymentmethod_invalid', self::VALIDATION],
            ['/^invalid date format\z/', 'invoice_date_invalid', self::VALIDATION],
        ],
        'GetQuotes' => [
            ['/^quote id not found\z/', 'quote_not_found', self::NOT_FOUND],
        ],
        'UpdateQuote' => [
            ['/^quote id not found\z/', 'quote_not_found', self::NOT_FOUND],
        ],
        'AcceptQuote' => [
            ['/^quote id not found\z/', 'quote_not_found', self::NOT_FOUND],
            ['/^quote already accepted\z/', 'quote_already_accepted', self::CONFLICT],
        ],
        'DeleteQuote' => [
            ['/^quote id not found\z/', 'quote_not_found', self::NOT_FOUND],
        ],
        'GetTicket' => [
            ['/^ticket id not found\z/', 'ticket_not_found', self::NOT_FOUND],
            ['/^ticket not found\z/', 'ticket_not_found', self::NOT_FOUND],
        ],
        'UpdateTicket' => [
            ['/^ticket id not found\z/', 'ticket_not_found', self::NOT_FOUND],
        ],
        'AddTicketReply' => [
            ['/^ticket id not found\z/', 'ticket_not_found', self::NOT_FOUND],
        ],
        'OpenTicket' => [
            ['/^department id not found\z/', 'department_not_found', self::NOT_FOUND],
            ['/^invalid department id\z/', 'department_not_found', self::NOT_FOUND],
        ],
        'GetOrders' => [
            ['/^order id not found\z/', 'order_not_found', self::NOT_FOUND],
        ],
        'CancelOrder' => [
            ['/^order id not found\z/', 'order_not_found', self::NOT_FOUND],
        ],
        'PendingOrder' => [
            ['/^order id not found\z/', 'order_not_found', self::NOT_FOUND],
        ],
        'GetProject' => [
            ['/^project not found\z/', 'project_not_found', self::NOT_FOUND],
        ],
        'UpdateProject' => [
            ['/^project not found\z/', 'project_not_found', self::NOT_FOUND],
        ],
        'UpdateToDoItem' => [
            ['/^todo item not found\z/', 'todo_not_found', self::NOT_FOUND],
        ],
        '*' => [
            ['/^access denied\z/', 'access_denied', self::DENIED],
            ['/^authentication failed\z/', 'access_denied', self::DENIED],
            ['/^invalid permissions\z/', 'access_denied', self::DENIED],
            ['/^you do not have permission to access this resource\z/', 'access_denied', self::DENIED],
        ],
    ];

    /**
     * Classifica sem devolver NADA derivado do texto.
     *
     * `$params` são os parâmetros da própria chamada. Servem a uma única
     * verificação, feita em memória: se a mensagem do WHMCS for IGUAL a uma
     * string que o chamador enviou, ela não é diagnóstico — é eco do input, e
     * classificá-la produziria um código falso. Um cliente que mandasse
     * `firstname="Email Address Already Exists"` recebia `conflict` de um
     * servidor que só devolveu de volta o que ele escreveu.
     *
     * Nada desses valores entra em retorno, log, contexto ou fingerprint.
     *
     * @param array<string, mixed> $params
     * @return array{code:string, category:string} sempre valores do enum
     */
    public static function classify(string $command, ?string $message, array $params = []): array
    {
        $normalized = self::normalize($message);

        if ($normalized === '' || self::echoesCallerInput($normalized, $params)) {
            return self::seal('downstream_error', self::DOWNSTREAM);
        }

        foreach ([self::PATTERNS[$command] ?? [], self::PATTERNS['*']] as $group) {
            foreach ($group as [$pattern, $code, $category]) {
                if (preg_match($pattern, $normalized) === 1) {
                    return self::seal($code, $category);
                }
            }
        }

        return self::seal('downstream_error', self::DOWNSTREAM);
    }

    /**
     * true quando a mensagem normalizada coincide com algum valor não confiável
     * enviado na chamada. Comparação só em memória; os valores morrem aqui.
     *
     * @param array<string, mixed> $params
     */
    private static function echoesCallerInput(string $normalized, array $params, int $depth = 0): bool
    {
        foreach ($params as $value) {
            if (is_array($value)) {
                if ($depth < 3 && self::echoesCallerInput($normalized, $value, $depth + 1)) {
                    return true;
                }
                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            if (self::normalize($value) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalização apenas para COMPARAÇÃO. O resultado morre neste escopo — não
     * é retornado, logado nem anexado a exceção.
     */
    private static function normalize(?string $message): string
    {
        if ($message === null) {
            return '';
        }

        $normalized = strtolower(trim($message));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        return rtrim($normalized, " \t\n\r.!");
    }

    /**
     * Última barreira: garante que só sai token do enum, mesmo que alguém
     * adicione uma entrada errada na tabela.
     *
     * @return array{code:string, category:string}
     */
    private static function seal(string $code, string $category): array
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = self::DOWNSTREAM;
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,63}\z/', $code) !== 1) {
            $code = 'downstream_error';
        }

        return ['code' => $code, 'category' => $category];
    }
}
