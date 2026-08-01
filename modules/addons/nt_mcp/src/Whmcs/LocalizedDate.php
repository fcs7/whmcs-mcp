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
 * represente a mesma data civil. A verificação usa `toMySQLDate()` (o inverso
 * documentado) quando disponível; sem ele, exige que ano, mês e dia apareçam
 * no resultado.
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
                "LocalizedDate: the localised value produced for '{$field}' does not represent "
                . 'the same calendar date; refusing to send it.'
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
     * O valor localizado precisa representar exatamente a mesma data civil.
     * Preferimos o inverso documentado (`toMySQLDate`); sem ele, exigimos que
     * ano, mês e dia apareçam como números no resultado — o que reprova
     * formatos de ano com 2 dígitos e qualquer saída degenerada.
     */
    private function representsSameDate(string $ymd, string $localized): bool
    {
        $parser = $this->parser;
        if (!$this->parserConfigured && function_exists('toMySQLDate')) {
            $parser = static fn(string $value): string => (string) toMySQLDate($value);
        }

        if ($parser !== null) {
            try {
                $roundTrip = $parser($localized);
            } catch (\Throwable) {
                return false;
            }

            return is_string($roundTrip) && substr(trim($roundTrip), 0, 10) === $ymd;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $ymd));

        preg_match_all('/\d+/', $localized, $matches);
        $numbers = array_map('intval', $matches[0] ?? []);

        return in_array($year, $numbers, true)
            && in_array($month, $numbers, true)
            && in_array($day, $numbers, true);
    }
}
