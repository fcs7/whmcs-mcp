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

    /** Caracteres fora do BMP (4 bytes UTF-8, ex.: emoji) — o banco do desenv guarda utf8 de 3 bytes. */
    private const FOUR_BYTE_CHAR_PATTERN = '/[\x{10000}-\x{10FFFF}]/u';

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

        $subjectValidUtf8 = mb_check_encoding($subject, 'UTF-8');
        if (!$subjectValidUtf8) {
            $errors[] = ['code' => 'invalid_utf8', 'detail' => 'subject contem bytes UTF-8 invalidos.'];
        } elseif (preg_match(self::FOUR_BYTE_CHAR_PATTERN, $subject) === 1) {
            $errors[] = ['code' => 'unsupported_4byte_char', 'detail' => 'subject contem caractere fora do BMP (ex.: emoji).'];
        }

        $messageValidUtf8 = mb_check_encoding($message, 'UTF-8');
        if (!$messageValidUtf8) {
            $errors[] = ['code' => 'invalid_utf8', 'detail' => 'message contem bytes UTF-8 invalidos.'];
        } elseif (preg_match(self::FOUR_BYTE_CHAR_PATTERN, $message) === 1) {
            $errors[] = ['code' => 'unsupported_4byte_char', 'detail' => 'message contem caractere fora do BMP (ex.: emoji).'];
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

    /**
     * Validação de UM campo dinâmico (Fase 2: produto/grupo, via
     * `tbldynamic_translations`). Mesma paridade de tags Smarty/HTML de
     * `validate()`, mas por um único texto (não subject+message), e o limite
     * de tamanho só se aplica a `input_type='text'` (nomes/headline/tagline —
     * mesmo teto de `subject`); `'textarea'` (description) não tem limite,
     * como `message`.
     *
     * @return array<int, array{code:string, detail:string}>
     */
    public function validateField(string $sourceText, string $text, string $inputType): array
    {
        $errors = [];

        if (trim($text) === '') {
            $errors[] = ['code' => 'empty_text', 'detail' => 'text vazio apos trim.'];
        } elseif ($inputType === 'text' && mb_strlen($text) > self::MAX_SUBJECT_LENGTH) {
            $errors[] = [
                'code' => 'text_too_long',
                'detail' => sprintf('text com %d caracteres excede o limite de %d.', mb_strlen($text), self::MAX_SUBJECT_LENGTH),
            ];
        }

        $validUtf8 = mb_check_encoding($text, 'UTF-8');
        if (!$validUtf8) {
            $errors[] = ['code' => 'invalid_utf8', 'detail' => 'text contem bytes UTF-8 invalidos.'];
        } elseif (preg_match(self::FOUR_BYTE_CHAR_PATTERN, $text) === 1) {
            $errors[] = ['code' => 'unsupported_4byte_char', 'detail' => 'text contem caractere fora do BMP (ex.: emoji).'];
        }

        if ($errors !== []) {
            return $errors;
        }

        $smartyDiff = self::multisetDiff(self::tokens($sourceText, self::SMARTY_PATTERN), self::tokens($text, self::SMARTY_PATTERN));
        if ($smartyDiff !== null) {
            $errors[] = ['code' => 'smarty_token_mismatch', 'detail' => $smartyDiff];
        }

        $htmlDiff = self::multisetDiff(self::tagNames($sourceText), self::tagNames($text));
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
