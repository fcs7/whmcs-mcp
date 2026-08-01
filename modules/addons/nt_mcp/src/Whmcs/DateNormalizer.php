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
 * as formas e NORMALIZAR para `Y-m-d`. Assim o valor date-time (o único que o
 * schema publicado admite) funciona ponta a ponta, e o `Y-m-d` continua válido
 * para chamadas diretas e para o dia em que o schema for corrigido no T2.
 *
 * `Y-m-d` NÃO é o formato de todas as rotas
 * -----------------------------------------
 * Esta classe produz a forma CANÔNICA intermediária. Só as rotas cuja API
 * documenta "Format: Y-m-d" recebem esta saída diretamente — `GetQuotes`,
 * `UpdateInvoice` e as quatro de Project Manager. As rotas documentadas como
 * "localised format" (`CreateQuote`/`UpdateQuote` e `GetActivityLog`) passam
 * ainda por `LocalizedDate`, que aplica a configuração da instalação.
 *
 * Timezone: preservamos a DATA CIVIL escrita pelo chamador
 * -------------------------------------------------------
 * De `2026-08-10T23:30:00-03:00` extraímos `2026-08-10` — a data como escrita,
 * sem converter para UTC nem para o fuso do servidor. Todos os campos aqui são
 * datas simples (sem hora) tanto na API quanto na coluna do banco; deslocar o
 * dia por causa de um offset produziria uma data que o usuário não pediu, e o
 * resultado dependeria do fuso do processo. A regra é determinística e
 * independente de ambiente.
 */
final class DateNormalizer
{
    /** Formato que a API do WHMCS espera nos campos de data. */
    public const WHMCS_FORMAT = 'Y-m-d';

    /**
     * Normaliza um input de data para `Y-m-d`.
     *
     * Aceita `YYYY-MM-DD` e o perfil público estrito
     * `YYYY-MM-DDTHH:MM:SS[.fração](Z|±HH:MM)`. O separador é sempre `T`,
     * segundos e zona são obrigatórios, e o perfil deste addon limita o
     * offset civil a ±14:00 (com minuto zero no extremo).
     * Rejeita qualquer outra coisa, incluindo
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
                '%s must be a real date as YYYY-MM-DD or '
                . 'YYYY-MM-DDTHH:MM:SS[.fraction](Z|±HH:MM) '
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

        // 2) Perfil RFC 3339 estrito adotado pela superfície pública.
        //
        // O TIMESTAMP INTEIRO é validado, não só a parte civil. A regex sozinha
        // deixava passar `2026-08-10T99:99:99+99:99`, que atravessava o adapter
        // e virava uma data válida downstream — hora, minuto, segundo e offset
        // impossíveis simplesmente ignorados.
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/', $value, $m) !== 1) {
            return null;
        }

        [$datePart, $hour, $minute] = [$m[1], (int) $m[2], (int) $m[3]];
        $second = (int) $m[4];

        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $offset = $m[6];
        if ($offset !== 'Z') {
            $offsetHour = (int) substr($offset, 1, 2);
            $offsetMinute = (int) substr($offset, 4, 2);
            if ($offsetHour > 14 || $offsetMinute > 59 || ($offsetHour === 14 && $offsetMinute !== 0)) {
                return null;
            }
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $datePart);

        return ($parsed !== false && $parsed->format('Y-m-d') === $datePart) ? $datePart : null;
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
