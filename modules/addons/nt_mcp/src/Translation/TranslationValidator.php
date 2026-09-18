<?php

declare(strict_types=1);

namespace NtMcp\Translation;

/**
 * Validação de conteúdo da tradução (Fase 1: e-mail).
 *
 * Não lança: devolve uma lista de erros ESTRUTURADOS (código + detalhe), sem
 * carregar o conteúdo inteiro do subject/message — só a contagem de tokens
 * divergentes, para não vazar corpo de e-mail em log/resposta de diagnóstico.
 */
final class TranslationValidator
{
    private const SMARTY_PATTERN = '/\{[^{}]+\}/';

    private const HTML_TAG_PATTERN = '/<\/?([a-zA-Z][a-zA-Z0-9]*)\b/';

    private const MAX_SUBJECT_LENGTH = 255;

    /**
     * @return array<int, array{code:string, detail:string}>
     */
    public function validate(string $sourceSubject, string $sourceMessage, string $subject, string $message): array
    {
        $errors = [];

        if (trim($subject) === '') {
            $errors[] = ['code' => 'empty_subject', 'detail' => 'subject vazio apos trim.'];
        } elseif (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            $errors[] = [
                'code' => 'subject_too_long',
                'detail' => sprintf('subject com %d caracteres excede o limite de %d.', mb_strlen($subject), self::MAX_SUBJECT_LENGTH),
            ];
        }

        if (trim($message) === '') {
            $errors[] = ['code' => 'empty_message', 'detail' => 'message vazio apos trim.'];
        }

        if ($errors !== []) {
            // Conteúdo vazio ou fora do tamanho torna a comparação de tokens
            // sem sentido — evita reportar smarty/html mismatch em cima de algo
            // que já vai ser recusado por outro motivo.
            return $errors;
        }

        $smartyDiff = self::multisetDiff(
            self::tokens($sourceSubject . "\n" . $sourceMessage, self::SMARTY_PATTERN),
            self::tokens($subject . "\n" . $message, self::SMARTY_PATTERN)
        );
        if ($smartyDiff !== null) {
            $errors[] = ['code' => 'smarty_token_mismatch', 'detail' => $smartyDiff];
        }

        $htmlDiff = self::multisetDiff(self::tagNames($sourceMessage), self::tagNames($message));
        if ($htmlDiff !== null) {
            $errors[] = ['code' => 'html_tag_mismatch', 'detail' => $htmlDiff];
        }

        return $errors;
    }

    /** @return array<int, string> */
    private static function tokens(string $text, string $pattern): array
    {
        preg_match_all($pattern, $text, $matches);

        return $matches[0];
    }

    /** @return array<int, string> */
    private static function tagNames(string $html): array
    {
        preg_match_all(self::HTML_TAG_PATTERN, $html, $matches);

        return array_map('strtolower', $matches[1]);
    }

    /**
     * Comparação de MULTISET (conta de cada token importa, ordem não).
     * Devolve um resumo curto (contagem por token), nunca o texto original.
     *
     * @param array<int, string> $expected
     * @param array<int, string> $actual
     */
    private static function multisetDiff(array $expected, array $actual): ?string
    {
        $expectedCounts = array_count_values($expected);
        $actualCounts = array_count_values($actual);

        ksort($expectedCounts);
        ksort($actualCounts);

        if ($expectedCounts === $actualCounts) {
            return null;
        }

        return sprintf(
            'esperado %s, obtido %s',
            json_encode($expectedCounts, JSON_UNESCAPED_UNICODE) ?: '{}',
            json_encode($actualCounts, JSON_UNESCAPED_UNICODE) ?: '{}'
        );
    }
}
