<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use NtMcp\Tests\Support\ActivityLogSpy;
use NtMcp\Tests\Support\ErrorLogSpy;
use NtMcp\Whmcs\AuditMetadata;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * D7 — a barreira do Activity Log precisa ser fail-closed por CONTRATO.
 *
 * A revisão provou que a versão anterior não era: `auditLog('...',
 * ['raw' => 'tok_abcdef0123456789'])` gravava o token, porque a sanitização
 * preservava qualquer string que casasse `[A-Za-z0-9_]{1,64}` — exatamente a
 * sintaxe de um token. O segredo também podia chegar como NOME de chave.
 */
class AuditBarrierTest extends TestCase
{
    protected function setUp(): void
    {
        ActivityLogSpy::start();
        ErrorLogSpy::start();
    }

    protected function tearDown(): void
    {
        ErrorLogSpy::stop();
        ActivityLogSpy::stop();
    }

    /** O probe exato da revisão agora nem compila: o tipo o impede. */
    public function test_arbitrary_array_is_rejected_by_type(): void
    {
        try {
            /** @phpstan-ignore-next-line — o TypeError é o próprio contrato sob teste */
            LocalApiClient::auditLog('MCP REVIEW', ['raw' => 'tok_abcdef0123456789']);
            $this->fail('array arbitrário deveria ser recusado pelo tipo');
        } catch (\TypeError) {
            $this->assertSame([], ActivityLogSpy::entries());
            $this->assertSame('', ErrorLogSpy::contents());
        }
    }

    /**
     * Mesmo entrando pelo construtor legítimo, segredo em VALOR de campo livre
     * nunca aparece — só o nome do campo, que está na allowlist.
     */
    public function test_secret_in_free_text_value_never_reaches_the_log(): void
    {
        LocalApiClient::auditLog('MCP TEST', AuditMetadata::forParams([
            'proposal' => 'tok_abcdef0123456789',
            'notes' => '123.456.789-00',
            'clientid' => 42,
        ]));

        $entry = ActivityLogSpy::entries()[0] ?? '';

        $this->assertStringContainsString('proposal', $entry, 'o NOME do campo é metadado');
        $this->assertStringContainsString('42', $entry, 'IDs conhecidos são metadado');
        $this->assertStringNotContainsString('tok_abcdef0123456789', $entry);
        $this->assertStringNotContainsString('123.456.789-00', $entry);
    }

    /** Segredo como NOME de chave vira contagem, nunca nome. */
    public function test_secret_in_field_name_never_reaches_the_log(): void
    {
        LocalApiClient::auditLog('MCP TEST', AuditMetadata::forParams([
            'tok_abcdef0123456789' => 'x',
            'sk_live_51H8yQ2eZvKYlo2C' => 1,
            'clientid' => 7,
        ]));

        $entry = ActivityLogSpy::entries()[0] ?? '';

        $this->assertStringContainsString('unknown_fields', $entry);
        $this->assertStringNotContainsString('tok_abcdef0123456789', $entry);
        $this->assertStringNotContainsString('sk_live_51H8yQ2eZvKYlo2C', $entry);
    }

    /**
     * `\z` e não `$`: `"42\n"` não pode virar o ID 42. O sufixo não vazava, mas
     * a afirmação "todos os padrões usam fim absoluto" era falsa.
     */
    #[DataProvider('newlineTokenProvider')]
    public function test_ids_with_trailing_newline_are_rejected(string $value): void
    {
        LocalApiClient::auditLog('MCP TEST', AuditMetadata::forParams(['clientid' => $value]));

        $entry = ActivityLogSpy::entries()[0] ?? '';

        $this->assertStringNotContainsString('"ids"', $entry, "'{$value}' não pode virar ID");
    }

    public static function newlineTokenProvider(): array
    {
        return [
            'newline final'   => ["42\n"],
            'CRLF final'      => ["42\r\n"],
            'newline no meio' => ["4\n2"],
            'espaço final'    => ['42 '],
        ];
    }

    public function test_valid_integer_id_is_still_recorded(): void
    {
        LocalApiClient::auditLog('MCP TEST', AuditMetadata::forParams(['clientid' => '42']));

        $this->assertStringContainsString('42', ActivityLogSpy::entries()[0] ?? '');
    }

    /** Flags só como boolean; string arbitrária em campo de flag some. */
    public function test_flags_only_accept_booleans(): void
    {
        LocalApiClient::auditLog('MCP TEST', AuditMetadata::forParams([
            'noemail' => 'tok_secret_value',
            'markdown' => true,
        ]));

        $entry = ActivityLogSpy::entries()[0] ?? '';

        $this->assertStringNotContainsString('tok_secret_value', $entry);
        $this->assertStringContainsString('markdown', $entry);
    }

    /** Metadata vazia não quebra e não inventa conteúdo. */
    public function test_none_metadata_logs_an_empty_shape(): void
    {
        LocalApiClient::auditLog('MCP TEST', AuditMetadata::none());

        $this->assertStringContainsString('meta: {}', ActivityLogSpy::entries()[0] ?? '');
    }

    /** Containers estruturais só aceitam shapes internos válidos. */
    public function test_table_containers_only_carry_metadata(): void
    {
        LocalApiClient::auditLog('MCP TEST', AuditMetadata::forTable(
            ['id' => 5],
            ['note' => 'tok_abcdef0123456789'],
            ['limit' => 100, 'offset' => 0]
        ));

        $entry = ActivityLogSpy::entries()[0] ?? '';

        $this->assertStringContainsString('where', $entry);
        $this->assertStringContainsString('note', $entry, 'nome do campo é metadado');
        $this->assertStringContainsString('100', $entry, 'limit é inteiro estrutural');
        $this->assertStringNotContainsString('tok_abcdef0123456789', $entry);
    }
}
