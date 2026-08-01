<?php

declare(strict_types=1);

namespace NtMcp\Crm;

use NtMcp\Whmcs\Diagnostics;

/**
 * Falha de domínio do CRM já convertida em contrato PÚBLICO e estável.
 *
 * Segue o mesmo desenho de `DownstreamFailureException`: a mensagem é escrita
 * por nós, a causa NÃO é encadeada em `getPrevious()` (qualquer handler que
 * estringifique a exceção reintroduziria o texto do driver) e o que era útil da
 * causa — classe e fingerprint — viaja como dado estruturado já higienizado.
 *
 * Só existem seis desfechos possíveis (`CrmErrorCode`), e cada construtor
 * estático produz uma mensagem LITERAL. Nenhum ponto desta classe interpola
 * valor vindo do chamador, do banco ou do mgCRM2; os únicos tokens variáveis
 * são nomes de capacidade/catálogo/campo do nosso próprio vocabulário, e ainda
 * assim passam por `Diagnostics::safeToken()`.
 */
final class CrmException extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly CrmErrorCode $errorCode,
        public readonly ?string $correlationId = null,
        public readonly ?string $causeFingerprint = null,
        public readonly string $causeClass = '',
    ) {
        parent::__construct($message);
    }

    /** Tabela mínima de uma capacidade não existe na instalação. */
    public static function unavailable(CrmCapability $capability): self
    {
        return new self(
            sprintf(
                'CRM capability "%s" is unavailable: the mgCRM2 tables it requires are not installed.',
                Diagnostics::safeToken($capability->value)
            ),
            CrmErrorCode::Unavailable
        );
    }

    /** Tabela existe, coluna exigida não. */
    public static function schemaMismatch(CrmCapability $capability): self
    {
        return new self(
            sprintf(
                'CRM capability "%s" does not match the expected mgCRM2 contract: a required column is missing.',
                Diagnostics::safeToken($capability->value)
            ),
            CrmErrorCode::SchemaMismatch
        );
    }

    public static function resourceNotFound(): self
    {
        return new self(
            'The requested CRM resource does not exist or has been deleted.',
            CrmErrorCode::ResourceNotFound
        );
    }

    public static function catalogInvalid(CrmCatalog $catalog): self
    {
        return new self(
            sprintf(
                'The supplied "%s" id does not exist, is inactive or has been deleted.',
                Diagnostics::safeToken($catalog->value)
            ),
            CrmErrorCode::CatalogInvalid
        );
    }

    /** `$field` e `$expectation` são sempre literais nossos. */
    public static function validation(string $field, string $expectation): self
    {
        return new self(
            sprintf(
                'CRM input "%s" is invalid: %s.',
                Diagnostics::safeToken($field),
                self::safePhrase($expectation)
            ),
            CrmErrorCode::Validation
        );
    }

    /**
     * Recusa determinística (D12): gate WRITE fechado/inválido ou identidade
     * OAuth que não resolve para exatamente um admin ativo.
     *
     * A mensagem é UMA SÓ para as duas famílias, de propósito: o chamador não
     * pode inferir se foi a configuração do gate ou a identidade que negou —
     * isso é informação de configuração interna. O operador distingue pelo
     * contexto fechado do diagnóstico, ligado por esta correlação.
     */
    public static function denied(?string $correlationId = null): self
    {
        return new self(
            'The CRM operation was denied by the server and will not succeed on retry. '
            . 'The operator log holds the reason under the correlation id.',
            CrmErrorCode::Denied,
            $correlationId
        );
    }

    /**
     * Falha inesperada após as validações. A mensagem carrega apenas a
     * correlação; classe e fingerprint da causa ficam em campos estruturados.
     */
    public static function downstream(
        string $correlationId,
        ?string $causeFingerprint = null,
        string $causeClass = '',
    ): self {
        return new self(
            sprintf(
                'The CRM operation did not complete. Details were recorded in the operator log '
                . 'under correlation id %s.',
                Diagnostics::safeToken($correlationId)
            ),
            CrmErrorCode::Downstream,
            $correlationId,
            $causeFingerprint,
            $causeClass
        );
    }

    /**
     * Higienização de FRASE, para os textos explicativos que são literais
     * nossos. `Diagnostics::safeToken()` serve a tokens e remove espaços — usá-lo
     * numa frase produzia uma mensagem grudada e ilegível.
     *
     * O alfabeto é letras, espaço e hífen. DÍGITO E PONTO FICAM DE FORA de
     * propósito: com eles, um call-site futuro que passasse texto de terceiro
     * conseguiria atravessar um CPF, um CNPJ ou um PAN quase intacto — foi
     * exatamente isso que o teste adversarial reproduziu. Nenhuma explicação
     * nossa precisa de número, e o preço de escrever "date-time" em vez de
     * "8601" é nenhum.
     */
    private static function safePhrase(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z \-]/', '', $value) ?? '';
        $safe = trim(preg_replace('/\s+/', ' ', $safe) ?? '');

        return $safe === '' ? 'invalid value' : substr($safe, 0, 120);
    }

    /**
     * Forma pública mínima. A tool decide se e como serializa; aqui garantimos
     * que só o código fechado e a correlação atravessam.
     *
     * @return array{result:string, error_code:string, message:string, correlation_id?:string}
     */
    public function toPublicArray(): array
    {
        $payload = [
            'result' => 'error',
            'error_code' => $this->errorCode->value,
            'message' => $this->getMessage(),
        ];

        if ($this->correlationId !== null && $this->correlationId !== '') {
            $payload['correlation_id'] = $this->correlationId;
        }

        return $payload;
    }
}
