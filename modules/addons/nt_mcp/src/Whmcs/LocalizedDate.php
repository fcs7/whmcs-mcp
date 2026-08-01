<?php
// src/Whmcs/LocalizedDate.php
namespace NtMcp\Whmcs;

/**
 * Converte uma data `Y-m-d` para o formato LOCALIZADO efetivo desta instalação
 * do WHMCS.
 *
 * Por que isto existe
 * -------------------
 * Nem toda API do WHMCS aceita `Y-m-d`. A documentação oficial divide as rotas
 * em duas famílias, e a diferença é real — não é detalhe cosmético:
 *
 *  - `GetQuotes`, `UpdateInvoice`, `CreateProject`, `UpdateProject`,
 *    `AddProjectTask`, `UpdateProjectTask` → "Format: Y-m-d";
 *  - `CreateQuote`/`UpdateQuote` (`datecreated`, `validuntil`) e
 *    `GetActivityLog` (`date`) → "in localised format (eg DD/MM/YYYY)".
 *
 * Enviar `Y-m-d` para a segunda família faz o WHMCS interpretar errado ou
 * recusar, conforme o formato configurado na instalação.
 *
 * Mecanismo
 * ---------
 * `fromMySQLDate($date, $includeTime, $applyClientDateFormat)` — helper global
 * documentado pela WHMCS (Developer Docs > Advanced > Date Functions) que
 * formata um valor MySQL segundo a configuração do sistema. Nada de formato
 * hardcoded: quem decide é a config de Localisation da instalação.
 *
 * O terceiro parâmetro é `false` de propósito: as chamadas do addon são LocalAPI
 * executadas sob um admin, portanto o formato correto é o *Date Format* da área
 * administrativa, não o *Client Date Format*.
 *
 * FAIL-CLOSED
 * -----------
 * Uma data mal convertida numa cotação é erro semântico silencioso — pior que
 * um erro explícito. Por isso qualquer dúvida aborta ANTES da LocalAPI:
 * helper ausente, exceção, retorno vazio/não-string, ou retorno que não
 * sobreviva ao round-trip.
 *
 * A verificação é SEMPRE `toMySQLDate()` — o inverso documentado. Não existe
 * mais fallback heurístico. A tentativa anterior procurava ano, mês e dia como
 * inteiros em qualquer ordem no resultado, o que aceita `08/10/2026` como
 * conversão de `2026-08-10`: mesmos dígitos, data civil trocada (8 de outubro
 * em vez de 10 de agosto). Presença de dígitos não é validação. Sem o inverso
 * disponível, a operação falha.
 */
class LocalizedDate
{
    /** @var callable|null fn(string $ymd): string — substitui fromMySQLDate() */
    private $formatter = null;

    /** @var callable|null fn(string $localized): string — substitui toMySQLDate() */
    private $parser = null;

    /** true depois de setParser(), mesmo com null (= "sem verificador inverso"). */
    private bool $parserConfigured = false;

    /** Injeta o formatador (testes / instalações sem o helper global). */
    public function setFormatter(callable $fn): void
    {
        $this->formatter = $fn;
    }

    /**
     * Injeta o verificador inverso. Passar `null` declara explicitamente que não
     * há inverso disponível — a verificação cai no exame por dígitos, que é o
     * comportamento numa instalação sem `toMySQLDate()`.
     */
    public function setParser(?callable $fn): void
    {
        $this->parser = $fn;
        $this->parserConfigured = true;
    }

    /**
     * `Y-m-d` → data localizada do WHMCS.
     *
     * @param string $ymd   data já normalizada em Y-m-d
     * @param string $field nome do campo, para a mensagem de erro
     * @throws \RuntimeException se a conversão não puder ser feita e verificada
     */
    public function fromWhmcsDate(string $ymd, string $field): string
    {
        if (\DateTimeImmutable::createFromFormat('!Y-m-d', $ymd) === false) {
            throw new \RuntimeException(
                "LocalizedDate: refusing to localise a malformed date for '{$field}'."
            );
        }

        $localized = $this->format($ymd, $field);

        if (!is_string($localized) || trim($localized) === '') {
            throw new \RuntimeException(
                "LocalizedDate: WHMCS returned an empty localised date for '{$field}'; "
                . 'refusing to send an ambiguous value.'
            );
        }

        $localized = trim($localized);

        if (!$this->representsSameDate($ymd, $localized)) {
            throw new \RuntimeException(
                "LocalizedDate: could not prove that the localised value produced for '{$field}' "
                . 'represents the same calendar date (toMySQLDate() unavailable or round-trip '
                . 'mismatch); refusing to send it.'
            );
        }

        return $localized;
    }

    private function format(string $ymd, string $field): mixed
    {
        if ($this->formatter !== null) {
            try {
                return ($this->formatter)($ymd);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "LocalizedDate: localisation of '{$field}' failed.",
                    0,
                    $e
                );
            }
        }

        if (!function_exists('fromMySQLDate')) {
            throw new \RuntimeException(
                "LocalizedDate: WHMCS helper fromMySQLDate() is unavailable; cannot produce the "
                . "localised date required for '{$field}'."
            );
        }

        try {
            return fromMySQLDate($ymd, false, false);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "LocalizedDate: localisation of '{$field}' failed.",
                0,
                $e
            );
        }
    }

    /**
     * O valor localizado precisa representar exatamente a mesma data civil,
     * provado pelo inverso documentado. Sem inverso não há prova — e sem prova
     * a operação falha.
     */
    private function representsSameDate(string $ymd, string $localized): bool
    {
        return $this->toMySQL($localized) === $ymd;
    }

    /**
     * Aplica `toMySQLDate()` e devolve a parte `Y-m-d`, ou null se o inverso
     * não estiver disponível ou não produzir uma data utilizável.
     */
    private function toMySQL(string $localized): ?string
    {
        $parser = $this->parser;
        if (!$this->parserConfigured && function_exists('toMySQLDate')) {
            $parser = static fn(string $value): string => (string) toMySQLDate($value);
        }

        if ($parser === null) {
            return null;
        }

        try {
            $roundTrip = $parser($localized);
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($roundTrip)) {
            return null;
        }

        $date = substr(trim($roundTrip), 0, 10);

        // O inverso pode devolver lixo silenciosamente; exigimos data real.
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return ($parsed !== false && $parsed->format('Y-m-d') === $date) ? $date : null;
    }

    /**
     * Aceita as TRÊS famílias de entrada que o contrato público admite para um
     * campo de data localizada (`validuntil` em Create/UpdateQuote):
     *
     *   1. `Y-m-d`               — forma canônica;
     *   2. ISO-8601 date-time    — o que o schema da SDK publica em campos
     *                              cujo nome contém "date";
     *   3. já LOCALIZADO         — o formato que a API oficial documenta e que
     *                              um cliente seguindo a doc da WHMCS envia.
     *
     * A família 3 é validada estritamente: `toMySQLDate()` para obter a data
     * civil, validação de calendário, e então `fromMySQLDate()` de volta com
     * igualdade EXATA contra o que o chamador mandou. Assim uma data escrita no
     * formato de OUTRA configuração (`08/10/2026` numa instalação DD/MM/YYYY)
     * não passa disfarçada de válida — ela volta diferente e é recusada.
     *
     * @throws \InvalidArgumentException entrada que não é data em nenhuma família
     * @throws \RuntimeException         localização indisponível/não verificável
     */
    public function fromFlexibleInput(string $value, string $field): string
    {
        $input = trim($value);
        if ($input === '') {
            throw new \InvalidArgumentException("{$field} must not be empty.");
        }

        // Famílias 1 e 2: reconhecíveis pela própria sintaxe.
        $canonical = DateNormalizer::tryNormalize($input);
        if ($canonical !== null) {
            return $this->fromWhmcsDate($canonical, $field);
        }

        // Família 3: assume-se localizado. Só é aceito se o round-trip fechar.
        $ymd = $this->toMySQL($input);
        if ($ymd === null) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be a date as YYYY-MM-DD, an ISO-8601 date-time, or a date in the '
                . 'WHMCS administrative date format; got "%s"',
                $field,
                $input
            ));
        }

        $localized = $this->fromWhmcsDate($ymd, $field);

        if ($localized !== $input) {
            throw new \InvalidArgumentException(sprintf(
                '%s "%s" is not written in this installation\'s WHMCS date format; '
                . 'use YYYY-MM-DD to avoid ambiguity.',
                $field,
                $input
            ));
        }

        return $localized;
    }
}
