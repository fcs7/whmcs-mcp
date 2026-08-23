<?php
// src/Whmcs/ErrorMessages.php
namespace NtMcp\Whmcs;

/**
 * Frase humana ESTÁVEL por código de erro do nosso enum (D6).
 *
 * Antes, todo erro — inclusive os que o `ErrorClassifier` já sabia nomear —
 * saía com a mesma frase: "The WHMCS API call 'X' did not complete
 * successfully". O código estruturado (`invoice_not_found`) estava certo, mas o
 * texto que o operador lê primeiro não dizia nada, e o agente MCP repetia a
 * frase inútil de volta para o usuário.
 *
 * REGRA que mantém isto seguro: cada frase é escrita AQUI, indexada pelo código
 * do enum. Nada vem da mensagem do WHMCS — nem por concatenação, nem por
 * fallback. `ErrorClassifier` continua sendo o único ponto que olha o texto
 * downstream, e ele só devolve o enum.
 *
 * O `correlation_id` continua no payload como campo próprio; ele também aparece
 * na frase porque é o que liga o que o agente mostrou ao que o operador acha no
 * Activity Log.
 */
final class ErrorMessages
{
    /**
     * Uma entrada por código emitido por `ErrorClassifier::PATTERNS`, mais os
     * códigos que nascem fora dele (`downstream_*`, `response_*`).
     *
     * @var array<string, string>
     */
    private const MESSAGES = [
        // NOT_FOUND — o id não existe. Ação: conferir o id ou listar antes.
        'client_not_found'     => 'No client exists with the given id in WHMCS.',
        'invoice_not_found'    => 'No invoice exists with the given id in WHMCS.',
        'quote_not_found'      => 'No quote exists with the given id in WHMCS.',
        'ticket_not_found'     => 'No ticket exists with the given id in WHMCS.',
        'order_not_found'      => 'No order exists with the given id in WHMCS.',
        'project_not_found'    => 'No project exists with the given id in WHMCS.',
        'todo_not_found'       => 'No to-do item exists with the given id in WHMCS.',
        'department_not_found' => 'No support department exists with the given id in WHMCS.',

        // CONFLICT — o registro já existe ou já está no estado pedido.
        'client_email_exists'    => 'Another WHMCS client already uses this e-mail address.',
        'quote_already_accepted' => 'This quote has already been accepted; it cannot be accepted again.',

        // VALIDATION — o input precisa ser corrigido antes de repetir.
        'client_email_invalid'          => 'The e-mail address was rejected by WHMCS as invalid.',
        'client_field_required'         => 'A required client field was missing or empty.',
        'invoice_paymentmethod_invalid' => 'The payment method is not a gateway configured in WHMCS.',
        'invoice_date_invalid'          => 'The date was rejected by WHMCS as malformed.',

        // DENIED — o admin vinculado ao token não tem permissão.
        'access_denied' => 'The WHMCS admin bound to this token is not allowed to perform this action.',

        // DOWNSTREAM — falha do lado do WHMCS; nada a corrigir no input.
        'downstream_error'     => 'The WHMCS API reported a failure that this connector could not classify.',
        'downstream_malformed' => 'The WHMCS API returned a response this connector could not interpret '
            . '(no recognisable success or error outcome). The call was treated as failed.',
        'response_encoding_failed' => 'The WHMCS response could not be encoded as JSON and was discarded '
            . 'to avoid returning corrupted data.',
    ];

    /** Frase genérica — usada quando o código não tem entrada própria. */
    private const FALLBACK = "The WHMCS API call '%s' did not complete successfully.";

    /**
     * Monta a mensagem pública. `$command` e `$correlationId` são valores
     * NOSSOS (nome do comando do allowlist e id gerado por `Diagnostics`),
     * nunca texto vindo do WHMCS.
     */
    public static function build(string $code, string $command, string $correlationId): string
    {
        $phrase = self::MESSAGES[$code] ?? sprintf(self::FALLBACK, $command);

        return $phrase
            . sprintf(" (WHMCS API '%s'; operator log correlation id %s)", $command, $correlationId);
    }

    /** true quando o código tem frase própria — usado só em teste de cobertura. */
    public static function has(string $code): bool
    {
        return array_key_exists($code, self::MESSAGES);
    }

    /** @return array<int, string> códigos com frase própria */
    public static function codes(): array
    {
        return array_keys(self::MESSAGES);
    }
}
