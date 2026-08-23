<?php
// src/Whmcs/ToolJson.php
namespace NtMcp\Whmcs;

/**
 * Fronteira ÚNICA de serialização das respostas de tool.
 *
 * Toda tool declara `: string` e devolvia `json_encode(..., JSON_PRETTY_PRINT)`
 * direto. `json_encode()` devolve `false` — não lança — quando encontra UTF-8
 * inválido (comum em dado legado gravado em latin1), recursão ou profundidade
 * excedida. Esse `false` batia no tipo de retorno declarado, virava `TypeError`,
 * e o SDK o convertia num `-32603 "Error while executing tool"` sem nenhuma
 * informação: indistinguível de "o WHMCS caiu".
 *
 * Duas defesas, nesta ordem:
 *
 *  1. `JSON_INVALID_UTF8_SUBSTITUTE` — byte inválido vira U+FFFD em vez de
 *     abortar a serialização inteira. O campo fica marcado, o resto do payload
 *     chega.
 *  2. Se AINDA assim falhar (recursão, profundidade), devolve um erro
 *     ESTRUTURADO com código do nosso enum e correlação, em vez de deixar o
 *     `TypeError` subir. O chamador consegue distinguir e o operador tem onde
 *     procurar.
 *
 * `JSON_UNESCAPED_UNICODE` e `JSON_UNESCAPED_SLASHES` só reduzem ruído: acento
 * e URL saem legíveis em vez de `\u00e7` e `htt\/\/`.
 */
final class ToolJson
{
    private const BASE_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE;

    private const PRETTY_FLAGS = self::BASE_FLAGS | JSON_PRETTY_PRINT;

    /** Última barreira: payload mínimo garantidamente serializável. */
    private const HARD_FALLBACK =
        '{"result":"error","error_code":"response_encoding_failed","error_category":"downstream"}';

    public static function encode(mixed $data): string
    {
        return self::encodeWithFlags($data, self::PRETTY_FLAGS);
    }

    /**
     * Variante compacta para respostas com orçamento explícito de bytes.
     * O conteúdo continua sendo o mesmo JSON; só o whitespace de apresentação
     * é removido antes de o SDK escapá-lo dentro de `content[0].text`.
     */
    public static function encodeCompact(mixed $data): string
    {
        return self::encodeWithFlags($data, self::BASE_FLAGS);
    }

    private static function encodeWithFlags(mixed $data, int $flags): string
    {
        $json = json_encode($data, $flags);
        if (is_string($json)) {
            return $json;
        }

        // `Diagnostics::event()` tem shape FECHADO de contexto e detalhe: nada
        // do payload atravessa. O motivo do PHP (`json_last_error_msg()`) não é
        // anexado justamente por não caber nesse shape — o contexto já é
        // suficiente para o operador achar a linha.
        $correlationId = Diagnostics::event(
            Diagnostics::CATEGORY_RUNTIME,
            'response_encoding_failed'
        );

        $fallback = json_encode([
            'result'         => 'error',
            'error_code'     => 'response_encoding_failed',
            'error_category' => ErrorClassifier::DOWNSTREAM,
            'message'        => ErrorMessages::build('response_encoding_failed', 'response', $correlationId),
            'correlation_id' => $correlationId,
        ], JSON_UNESCAPED_SLASHES);

        return is_string($fallback) ? $fallback : self::HARD_FALLBACK;
    }
}
