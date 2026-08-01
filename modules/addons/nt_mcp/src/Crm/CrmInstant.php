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
 * A fração de segundo é TRUNCADA, não arredondada: a coluna alvo não tem
 * precisão fracionária, e arredondar para cima criaria um instante que o
 * chamador não escreveu.
 *
 * Intervalo representável
 * -----------------------
 * Validar a gramática não basta: `9999-12-31T23:59:59-14:00` é gramaticalmente
 * perfeito e vira `10000-01-01 13:59:59` em UTC, que não cabe em coluna nenhuma
 * do MySQL; `0001-01-01T00:00:00+14:00` cai abaixo do piso. Sem esta barreira,
 * o valor passava como validado e só falhava (ou era coercido em silêncio,
 * conforme o SQL mode) DENTRO do banco — virando `downstream` depois do efeito,
 * em vez de `validation` antes dele.
 *
 * O intervalo adotado é o do `TIMESTAMP` documentado pelo MySQL —
 * `1970-01-01 00:00:01` a `2038-01-19 03:14:07` UTC — porque a evidência do DDL
 * descreve `crm_followups.date` como timestamp e essa é a faixa CONSERVADORA:
 * cabe também em `DATETIME`, então acerta nos dois cenários. T6 confirma o tipo
 * físico; se for `DATETIME`, a faixa pode ser ampliada com nova aprovação.
 *
 * A checagem é feita sobre o INSTANTE, não sobre a data civil: um valor com
 * offset pode estar dentro da faixa na parede do chamador e fora dela em UTC —
 * é exatamente esse cruzamento de borda que os testes cobrem.
 */
final class CrmInstant
{
    /** Formato aceito pela coluna `date` do mgCRM2. */
    public const MYSQL_FORMAT = 'Y-m-d H:i:s';

    /** `1970-01-01 00:00:01` UTC — piso do `TIMESTAMP` do MySQL. */
    public const MIN_EPOCH = 1;

    /** `2038-01-19 03:14:07` UTC — teto do `TIMESTAMP` do MySQL. */
    public const MAX_EPOCH = 2147483647;

    private const GRAMMAR = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/';

    /**
     * @param string $field nome do campo, sempre literal nosso
     * @throws CrmException com código `validation`
     */
    public static function toUtcMySql(string $value, string $field): string
    {
        $instant = self::parse($value);

        if ($instant === null) {
            // A mensagem não traz exemplo de data: um literal como
            // `2026-08-10T14:30:00Z` colidiria com o valor recusado e faria
            // parecer que o input voltou no erro.
            throw CrmException::validation(
                $field,
                'expected an ISO date-time with an explicit timezone offset'
            );
        }

        if (!self::withinStorableRange($instant)) {
            // Motivo diferente, mensagem diferente: o valor está bem formado,
            // o que falta é caber na coluna. Confundir os dois faria o chamador
            // reescrever uma data que já estava correta.
            throw CrmException::validation(
                $field,
                'the instant is outside the range this CRM can store'
            );
        }

        return $instant->format(self::MYSQL_FORMAT);
    }

    /** Como `toUtcMySql()`, mas devolve null em vez de lançar. */
    public static function tryToUtcMySql(string $value): ?string
    {
        $instant = self::parse($value);

        if ($instant === null || !self::withinStorableRange($instant)) {
            return null;
        }

        return $instant->format(self::MYSQL_FORMAT);
    }

    /** Gramática + política de offset; devolve o instante já em UTC. */
    private static function parse(string $value): ?\DateTimeImmutable
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

        return $parsed->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * A faixa é conferida sobre o INSTANTE em UTC, não sobre a data civil: com
     * offset, um valor pode estar dentro da faixa na parede do chamador e fora
     * dela depois da conversão — e vice-versa.
     */
    private static function withinStorableRange(\DateTimeImmutable $utc): bool
    {
        $epoch = $utc->getTimestamp();

        return $epoch >= self::MIN_EPOCH && $epoch <= self::MAX_EPOCH;
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
