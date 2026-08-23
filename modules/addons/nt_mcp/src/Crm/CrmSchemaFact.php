<?php

declare(strict_types=1);

namespace NtMcp\Crm;

/**
 * Resposta TRI-STATE de uma pergunta de metadata.
 *
 * O desenho anterior devolvia `bool` e convertia falha do driver em `false`.
 * Isso produzia um diagnóstico factualmente errado: uma indisponibilidade
 * transitória do banco era publicada como "o addon não está instalado" (ou
 * "a coluna não existe"), e a conclusão falsa ficava memorizada pela request
 * inteira. Caller e operador eram levados à correção errada.
 *
 * Agora existem três respostas, e só a ausência REAL vira
 * `crm_unavailable`/`crm_schema_mismatch`. Erro vira `downstream`, carregando a
 * correlação que o probe já registrou — e nunca é memorizado.
 */
final class CrmSchemaFact
{
    private const PRESENT = 'present';
    private const ABSENT = 'absent';
    private const UNKNOWN = 'unknown';

    private function __construct(
        private readonly string $state,
        public readonly ?string $correlationId = null,
    ) {
    }

    public static function present(): self
    {
        return new self(self::PRESENT);
    }

    public static function absent(): self
    {
        return new self(self::ABSENT);
    }

    /** O probe já registrou o incidente; aqui viaja apenas a correlação. */
    public static function unknown(string $correlationId): self
    {
        return new self(self::UNKNOWN, $correlationId);
    }

    public function isPresent(): bool
    {
        return $this->state === self::PRESENT;
    }

    public function isAbsent(): bool
    {
        return $this->state === self::ABSENT;
    }

    public function isUnknown(): bool
    {
        return $this->state === self::UNKNOWN;
    }
}
