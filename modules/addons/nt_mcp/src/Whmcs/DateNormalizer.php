<?php
// src/Whmcs/DateNormalizer.php
namespace NtMcp\Whmcs;

/**
 * Ponte entre o schema que o SDK MCP v1 PUBLICA e o formato que a API do WHMCS
 * ACEITA.
 *
 * O `SchemaGenerator` da php-mcp/server v1.1 infere o formato pelo NOME do
 * parâmetro (`vendor/php-mcp/server/src/Support/SchemaGenerator.php`):
 *
 *     } elseif (stripos($name, 'date') !== false || ...) {
 *         $paramSchema['format'] = 'date-time';
 *
 * Ou seja: todo parâmetro string cujo nome contenha "date" (`duedate`,
 * `datecreated`, `date`) é publicado como `format: date-time` e VALIDADO pelo
 * opis/json-schema antes de a tool ser chamada. Mas a API do WHMCS documenta
 * esses campos como data simples — `UpdateInvoice`: "duedate | string | The due
 * date of the invoice. Format: YYYY-mm-dd".
 *
 * Sem esta ponte o campo fica inutilizável via MCP real: `2026-08-10` é
 * rejeitado pelo validator com JSON-RPC -32602, e `2026-08-10T00:00:00Z` passa
 * pelo validator mas é recusado pela tool.
 *
 * A SDK v1 não permite schema customizado em `#[McpTool]`, e reescrever o
 * registry é escopo do T2. A correção mínima e segura vive aqui: ACEITAR ambas
 * as formas e NORMALIZAR para o `Y-m-d` que o WHMCS espera. Assim o valor
 * date-time (o único que o schema publicado admite) funciona ponta a ponta, e o
 * `Y-m-d` continua válido para chamadas diretas e para o dia em que o schema for
 * corrigido no T2.
 */
final class DateNormalizer
{
    /** Formato que a API do WHMCS espera nos campos de data. */
    public const WHMCS_FORMAT = 'Y-m-d';

    /**
     * Normaliza um input de data para `Y-m-d`.
     *
     * Aceita `YYYY-MM-DD` e RFC3339/ISO-8601 date-time (`2026-08-10T00:00:00Z`,
     * com offset ou fração de segundo). Rejeita qualquer outra coisa, incluindo
     * datas sintaticamente corretas mas inexistentes (`2026-02-31`).
     *
     * @param string $field nome do campo, usado na mensagem de erro
     * @throws \InvalidArgumentException se o valor não for uma data válida
     */
    public static function toWhmcsDate(string $value, string $field): string
    {
        $normalized = self::tryNormalize($value);

        if ($normalized === null) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be a real date as YYYY-MM-DD or an ISO-8601 date-time '
                . '(e.g. "2026-08-10" or "2026-08-10T00:00:00Z"); got "%s"',
                $field,
                $value
            ));
        }

        return $normalized;
    }

    /** Como toWhmcsDate(), mas devolve null em vez de lançar. */
    public static function tryNormalize(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // 1) Data simples: exige que o valor sobreviva ao round-trip, o que
        //    elimina overflow silencioso do PHP (2026-02-31 -> 2026-03-03).
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date !== false && $date->format('Y-m-d') === $value) {
            return $value;
        }

        // 2) Date-time ISO-8601/RFC3339 — a forma que o schema publicado exige.
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[Tt ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?([Zz]|[+-]\d{2}:?\d{2})?$/', $value, $m) === 1) {
            $datePart = $m[1];
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $datePart);
            if ($parsed !== false && $parsed->format('Y-m-d') === $datePart) {
                return $datePart;
            }
        }

        return null;
    }

    /**
     * Normaliza um campo opcional: string vazia continua vazia (= "não enviado"),
     * qualquer outro valor precisa ser uma data válida.
     */
    public static function optional(string $value, string $field): string
    {
        if (trim($value) === '') {
            return '';
        }

        return self::toWhmcsDate($value, $field);
    }
}
