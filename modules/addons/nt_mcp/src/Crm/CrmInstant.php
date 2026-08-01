<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Normalizador do INSTANTE de um follow-up.
 *
 * `crm_followups.date` é um timestamp, não uma data civil. Isso muda a regra em
 * relação a `DateNormalizer`: lá o objetivo é preservar a data COMO ESCRITA
 * (`2026-08-10T23:30:00-03:00` → `2026-08-10`, sem deslocar o dia); aqui o
 * objetivo é o oposto — fixar o ponto no tempo. O mesmo valor vira
 * `2026-08-11 02:30:00` em UTC, porque é esse o instante que o chamador pediu.
 *
 * Por isso as duas classes coexistem em vez de uma chamar a outra: têm
 * contratos de saída diferentes. O que é compartilhado — e o que os testes
 * mantêm em paridade — é a GRAMÁTICA e a POLÍTICA DE OFFSET do D9:
 *
 *   YYYY-MM-DDTHH:MM:SS[.fração](Z|±HH:MM)
 *
 * com separador `T` obrigatório, segundos obrigatórios, timezone obrigatório e
 * offset civil limitado a ±14:00 (minuto zero no extremo).
 *
 * Recusas explícitas, todas exercitadas em teste:
 *  - qualquer whitespace externo (não há `trim()`: um valor com espaço é inválido,
 *    não é "quase válido");
 *  - data simples `Y-m-d` — sem hora e sem zona não existe instante;
 *  - hora/minuto/segundo impossíveis, inclusive segundo bissexto `:60`;
 *  - data inexistente (`2026-02-31`), que o PHP silenciosamente rolaria para março;
 *  - offset fora da política (`+15:00`, `+14:30`, `-99:99`).
 *
 * A fração de segundo é TRUNCADA, não arredondada: `DATETIME` do MySQL na
 * coluna alvo não tem precisão fracionária, e arredondar para cima criaria um
 * instante que o chamador não escreveu.
 */
final class CrmInstant
{
    /** Formato aceito pela coluna `date` do mgCRM2. */
    public const MYSQL_FORMAT = 'Y-m-d H:i:s';

    private const GRAMMAR = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/';

    /**
     * @param string $field nome do campo, sempre literal nosso
     * @throws CrmException com código `validation`
     */
    public static function toUtcMySql(string $value, string $field): string
    {
        $normalized = self::tryToUtcMySql($value);

        if ($normalized === null) {
            // A mensagem não traz exemplo de data: um literal como
            // `2026-08-10T14:30:00Z` colidiria com o valor recusado e faria
            // parecer que o input voltou no erro.
            throw CrmException::validation(
                $field,
                'expected an ISO date-time with an explicit timezone offset'
            );
        }

        return $normalized;
    }

    /** Como `toUtcMySql()`, mas devolve null em vez de lançar. */
    public static function tryToUtcMySql(string $value): ?string
    {
        if (preg_match(self::GRAMMAR, $value, $m) !== 1) {
            return null;
        }

        [, $year, $month, $day, $hour, $minute, $second] = $m;
        $offset = $m[8];

        if ((int) $hour > 23 || (int) $minute > 59 || (int) $second > 59) {
            return null;
        }

        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        if (!self::offsetWithinPolicy($offset)) {
            return null;
        }

        // A fração já foi validada pela gramática e é descartada aqui: o
        // instante é construído sem ela, o que trunca por construção.
        $civil = sprintf('%s-%s-%sT%s:%s:%s', $year, $month, $day, $hour, $minute, $second);

        try {
            $parsed = new \DateTimeImmutable($civil . $offset);
        } catch (\Throwable) {
            return null;
        }

        return $parsed->setTimezone(new \DateTimeZone('UTC'))->format(self::MYSQL_FORMAT);
    }

    /**
     * Mesma política do perfil público (D9): até ±14:00, e no extremo o minuto
     * precisa ser zero. `+15:00` e `+14:30` não existem como offset civil.
     */
    private static function offsetWithinPolicy(string $offset): bool
    {
        if ($offset === 'Z') {
            return true;
        }

        $hours = (int) substr($offset, 1, 2);
        $minutes = (int) substr($offset, 4, 2);

        if ($hours > 14 || $minutes > 59) {
            return false;
        }

        return !($hours === 14 && $minutes !== 0);
    }
}
