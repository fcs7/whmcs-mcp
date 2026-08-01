<?php

declare(strict_types=1);

namespace NtMcp\Tests\Crm;

use NtMcp\Crm\CrmCapability;
use NtMcp\Crm\CrmCatalog;
use NtMcp\Crm\CrmErrorCode;
use NtMcp\Crm\CrmException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** O contrato de falha: sete códigos, mensagens nossas, nada da causa. */
class CrmExceptionTest extends TestCase
{
    /** Os valores públicos são os da decisão de requisitos, exatamente. */
    public function test_the_public_code_set_is_the_agreed_one(): void
    {
        $this->assertSame(
            [
                'crm_unavailable',
                'crm_schema_mismatch',
                'crm_resource_not_found',
                'crm_catalog_invalid',
                'validation',
                'denied',
                'downstream',
            ],
            array_map(static fn(CrmErrorCode $c): string => $c->value, CrmErrorCode::cases())
        );
    }

    public function test_every_factory_produces_its_own_code(): void
    {
        $this->assertSame(
            CrmErrorCode::Unavailable,
            CrmException::unavailable(CrmCapability::ResourceCore)->errorCode
        );
        $this->assertSame(
            CrmErrorCode::SchemaMismatch,
            CrmException::schemaMismatch(CrmCapability::Notes)->errorCode
        );
        $this->assertSame(CrmErrorCode::ResourceNotFound, CrmException::resourceNotFound()->errorCode);
        $this->assertSame(
            CrmErrorCode::CatalogInvalid,
            CrmException::catalogInvalid(CrmCatalog::FollowupStatus)->errorCode
        );
        $this->assertSame(CrmErrorCode::Validation, CrmException::validation('date', 'x')->errorCode);
        $this->assertSame(CrmErrorCode::Denied, CrmException::denied()->errorCode);
        $this->assertSame(CrmErrorCode::Downstream, CrmException::downstream('deadbeef')->errorCode);
    }

    /**
     * D12: `denied` é UMA mensagem para gate e identidade. O chamador não pode
     * inferir qual condição interna negou, e o texto precisa dizer que retry
     * não resolve — foi a confusão que motivou a decisão.
     */
    public function test_denied_does_not_reveal_which_condition_refused(): void
    {
        $fromGate = CrmException::denied('deadbeef');
        $fromIdentity = CrmException::denied('c0ffee01');

        $this->assertSame($fromGate->getMessage(), $fromIdentity->getMessage());

        foreach (['gate', 'admin', 'username', 'readonly', 'disabled', 'tbladmins'] as $leak) {
            $this->assertStringNotContainsString($leak, $fromGate->getMessage());
        }

        $this->assertStringContainsString('retry', $fromGate->getMessage());
        $this->assertSame('deadbeef', $fromGate->toPublicArray()['correlation_id']);
    }

    /**
     * A causa NUNCA é encadeada: qualquer handler que estringifique a exceção
     * reintroduziria o texto downstream pela cadeia anterior.
     */
    public function test_the_cause_is_never_chained(): void
    {
        $exception = CrmException::downstream('deadbeef', 'abc123', 'PDOException');

        $this->assertNull($exception->getPrevious());
        $this->assertSame('abc123', $exception->causeFingerprint);
        $this->assertSame('PDOException', $exception->causeClass);
        $this->assertStringNotContainsString('PDOException', (string) $exception);
    }

    /**
     * Mesmo que um call-site futuro passe texto envenenado como explicação, a
     * higienização de frase impede SQL, path, pontuação e segredo estruturado
     * de atravessar.
     */
    #[DataProvider('poisonProvider')]
    public function test_validation_messages_cannot_carry_poison(string $poison, array $forbidden): void
    {
        $message = CrmException::validation('date', $poison)->getMessage();

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $message);
        }
    }

    /** @return array<string, array{0:string,1:array<int,string>}> */
    public static function poisonProvider(): array
    {
        return [
            'sql' => [
                'SELECT * FROM tblclients WHERE id=1',
                ['*', '=', 'SELECT * FROM', '1'],
            ],
            'path' => [
                '/var/www/httpdocs/configuration.php',
                ['/var/www', '/', '.php'],
            ],
            'cpf' => [
                'documento 123.456.789-00 rejeitado',
                ['123.456.789-00', '123'],
            ],
            'cnpj' => [
                'documento 12.345.678/0001-95 rejeitado',
                ['12.345.678/0001-95', '/'],
            ],
            'pan' => [
                'cartao 4111111111111111 recusado',
                ['4111111111111111', '4111'],
            ],
            'dsn' => [
                'mysql://root:hunter2@db:3306',
                ['mysql://', 'hunter2', '@', '3306'],
            ],
        ];
    }

    /** Frase inteiramente descartada não deixa a mensagem sem sentido. */
    public function test_fully_stripped_expectation_falls_back_to_a_literal(): void
    {
        $this->assertStringContainsString(
            'invalid value',
            CrmException::validation('date', '::::')->getMessage()
        );
    }

    public function test_public_array_only_exposes_the_closed_contract(): void
    {
        $withoutCorrelation = CrmException::resourceNotFound()->toPublicArray();

        $this->assertSame(['result', 'error_code', 'message'], array_keys($withoutCorrelation));
        $this->assertSame('crm_resource_not_found', $withoutCorrelation['error_code']);

        $withCorrelation = CrmException::downstream('deadbeef')->toPublicArray();

        $this->assertSame('deadbeef', $withCorrelation['correlation_id']);
        $this->assertArrayNotHasKey('cause_class', $withCorrelation);
        $this->assertArrayNotHasKey('fingerprint', $withCorrelation);
    }

    /** Nenhum nome físico de tabela ou coluna aparece numa mensagem pública. */
    public function test_messages_never_expose_physical_schema_names(): void
    {
        $messages = [
            CrmException::unavailable(CrmCapability::ResourceCore)->getMessage(),
            CrmException::schemaMismatch(CrmCapability::CustomFields)->getMessage(),
            CrmException::resourceNotFound()->getMessage(),
            CrmException::catalogInvalid(CrmCatalog::ResourceType)->getMessage(),
        ];

        foreach ($messages as $message) {
            foreach (['crm_resources', 'crm_notes', 'crm_fields', 'tbladmins', 'deleted_at'] as $name) {
                $this->assertStringNotContainsString($name, $message);
            }
        }
    }
}
