<?php
// src/Whmcs/TextRedactor.php
namespace NtMcp\Whmcs;

/**
 * Redação de TEXTO LIVRE vindo de fora (mensagens de erro do WHMCS, de hooks,
 * de módulos de terceiros e de `Throwable::getMessage()`).
 *
 * `LocalApiClient::redactParams()` protege parâmetros ESTRUTURADOS. Não protege
 * uma string que já veio com o segredo interpolado dentro. Foi exatamente esse o
 * furo reproduzido pela revisão: `AddClient` recebeu `password2=hunter2` e
 * `cardnum=...`, o WHMCS devolveu `message: "Rejected password hunter2 card
 * 4111111111111111 ..."`, e essa string foi para o Activity Log em claro,
 * porque a redação de parâmetros não alcança o texto de resposta.
 *
 * A política do T1 é não mandar texto downstream arbitrário para nenhum sink —
 * Activity Log ou resposta MCP. Quando algum diagnóstico precisa existir (só no
 * `error_log`, canal protegido), ele passa por aqui antes.
 */
final class TextRedactor
{
    /**
     * Comprimento mínimo para um valor de parâmetro virar alvo de busca. Valores
     * muito curtos ('1', 'BR') apareceriam em qualquer frase e transformariam a
     * mensagem inteira em [REDACTED], destruindo o diagnóstico sem ganho real.
     */
    private const MIN_VALUE_LENGTH = 4;

    public const MASK = '[REDACTED]';

    /**
     * Remove de `$text` qualquer valor sensível presente em `$params` e mascara
     * sequências que parecem cartão.
     *
     * @param array<string, mixed> $params parâmetros da chamada que gerou o texto
     */
    public static function scrub(string $text, array $params = []): string
    {
        if ($text === '') {
            return $text;
        }

        foreach (self::sensitiveValues($params) as $secret) {
            $text = str_ireplace($secret, self::MASK, $text);
        }

        // Defesa adicional: sequências longas de dígitos (com ou sem separador)
        // são tratadas como PAN de cartão mesmo que não estejam nos parâmetros.
        $text = preg_replace('/\b(?:\d[ -]?){13,19}\b/', self::MASK, $text) ?? $text;

        return $text;
    }

    /**
     * Valores dos parâmetros classificados como sensíveis, achatados
     * recursivamente e ordenados do maior para o menor — substituir primeiro os
     * mais longos evita que um valor curto quebre um mais longo pela metade.
     *
     * @param array<string, mixed> $params
     * @return array<string>
     */
    private static function sensitiveValues(array $params, int $depth = 0): array
    {
        $redacted = (new \ReflectionClass(LocalApiClient::class))
            ->getReflectionConstant('REDACTED_PARAMS')->getValue();

        $values = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                if ($depth < 5) {
                    $values = array_merge($values, self::sensitiveValues($value, $depth + 1));
                }
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            if (!in_array(strtolower((string) $key), $redacted, true)) {
                continue;
            }

            $string = (string) $value;
            if (strlen($string) >= self::MIN_VALUE_LENGTH) {
                $values[] = $string;
            }
        }

        $values = array_values(array_unique($values));
        usort($values, static fn(string $a, string $b) => strlen($b) <=> strlen($a));

        return $values;
    }
}
