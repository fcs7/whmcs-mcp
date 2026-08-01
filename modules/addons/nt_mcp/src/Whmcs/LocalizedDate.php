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
 * fallback heurístico: sem o inverso disponível, a operação FALHA, mesmo que a
 * conversão esteja correta. (A tentativa descartada procurava ano, mês e dia
 * como inteiros em qualquer ordem, o que aceitava `08/10/2026` como conversão
 * de `2026-08-10` — mesmos dígitos, data civil trocada.)
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
     * Entrada pública de um campo de data que o WHMCS quer LOCALIZADO (D9).
     *
     * Aceita apenas `Y-m-d` e ISO-8601 date-time. A família "já localizado" foi
     * REMOVIDA da superfície pública, e a razão é específica: o round-trip
     * prova que a string é bem-formada para a configuração instalada, não que
     * ela signifique o que o chamador quis. Numa instalação MM/DD,
     * `validuntil=10/08/2026` fecha o round-trip e vira 8 de outubro; se a
     * intenção era 10 de agosto, a cotação vence dois meses depois e nenhuma
     * camada emite erro, porque a data é válida nas duas leituras. Recusar só
     * o ambíguo eliminaria todo dia de 1 a 12 — faixa grande demais de datas
     * legítimas. Então a entrada passa a ser sempre não ambígua.
     *
     * A conversão para localizado continua acontecendo aqui dentro, porque é o
     * que `CreateQuote`/`UpdateQuote`/`GetActivityLog` exigem.
     *
     * @throws \InvalidArgumentException entrada ambígua ou que não é data
     * @throws \RuntimeException         localização indisponível/não verificável
     */
    public function fromPublicInput(string $value, string $field): string
    {
        $input = trim($value);
        if ($input === '') {
            throw new \InvalidArgumentException("{$field} must not be empty.");
        }

        $canonical = DateNormalizer::tryNormalize($input);
        if ($canonical === null) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be an unambiguous date: YYYY-MM-DD or an ISO-8601 date-time '
                . '(e.g. "2026-08-10" or "2026-08-10T00:00:00Z"). Localised formats such as '
                . 'DD/MM/YYYY are not accepted because they cannot be told apart from MM/DD/YYYY. '
                . 'Got "%s"',
                $field,
                $input
            ));
        }

        return $this->fromWhmcsDate($canonical, $field);
    }
}
