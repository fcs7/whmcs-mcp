<?php

declare(strict_types=1);

namespace NtMcp\Translation;

use NtMcp\Whmcs\Diagnostics;

/**
 * Falha de domínio de tradução, no mesmo espírito de `NtMcp\Crm\CrmException`:
 * mensagem literal nossa, causa nunca encadeada, e só um punhado fechado de
 * desfechos possíveis.
 */
final class TranslationException extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct($message);
    }

    /** Tabela mínima da capacidade não existe na instalação. */
    public static function unavailable(string $capability): self
    {
        return new self(
            sprintf(
                'Translation capability "%s" is unavailable: the required table is not installed.',
                Diagnostics::safeToken($capability)
            ),
            'translation_unavailable'
        );
    }

    /** Tabela existe, coluna exigida não. */
    public static function schemaMismatch(string $capability): self
    {
        return new self(
            sprintf(
                'Translation capability "%s" does not match the expected contract: a required column is missing.',
                Diagnostics::safeToken($capability)
            ),
            'translation_schema_mismatch'
        );
    }

    /** Falha inesperada de metadata (driver fora do ar). */
    public static function downstream(string $correlationId): self
    {
        return new self(
            sprintf(
                'The translation operation did not complete. Details were recorded in the operator log '
                . 'under correlation id %s.',
                Diagnostics::safeToken($correlationId)
            ),
            'downstream',
            $correlationId
        );
    }

    /** @return array{result:string, error_code:string, message:string, correlation_id?:string} */
    public function toPublicArray(): array
    {
        $payload = [
            'result' => 'error',
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
        ];

        if ($this->correlationId !== null && $this->correlationId !== '') {
            $payload['correlation_id'] = $this->correlationId;
        }

        return $payload;
    }
}
