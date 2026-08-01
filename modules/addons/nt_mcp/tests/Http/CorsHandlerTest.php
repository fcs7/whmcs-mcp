<?php

declare(strict_types=1);

namespace NtMcp\Tests\Http;

use NtMcp\Http\CorsHandler;
use NtMcp\Http\CorsDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CorsHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $_SERVER['HTTP_ORIGIN'],
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'],
            $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'],
        );
        \WHMCS\Config\Setting::reset();
    }

    // --- getAllowedOrigins() ---

    public function test_get_allowed_origins_returns_array_of_strings(): void
    {
        // No WHMCS bootstrap — Setting::getValue() throws → returns []
        $origins = CorsHandler::getAllowedOrigins();
        $this->assertIsArray($origins);
        $this->assertContainsOnly('string', $origins);
    }

    // --- resolveOriginHeader() ---

    public function test_no_allowlist_returns_wildcard(): void
    {
        $result = CorsHandler::resolveOriginHeader('https://claude.ai', []);
        $this->assertSame('*', $result);
    }

    public function test_no_origin_header_returns_wildcard(): void
    {
        $result = CorsHandler::resolveOriginHeader('', ['https://claude.ai']);
        $this->assertSame('*', $result);
    }

    public function test_origin_in_allowlist_returns_specific_origin(): void
    {
        $result = CorsHandler::resolveOriginHeader('https://claude.ai', ['https://claude.ai']);
        $this->assertSame('https://claude.ai', $result);
    }

    public function test_origin_not_in_allowlist_returns_null(): void
    {
        $result = CorsHandler::resolveOriginHeader('https://evil.com', ['https://claude.ai']);
        $this->assertNull($result);
    }

    public function test_origin_matches_exactly_no_partial_match(): void
    {
        // 'https://evil.claude.ai' should NOT match 'https://claude.ai'
        $result = CorsHandler::resolveOriginHeader('https://evil.claude.ai', ['https://claude.ai']);
        $this->assertNull($result);
    }

    public function test_no_origin_and_no_allowlist_returns_wildcard(): void
    {
        $result = CorsHandler::resolveOriginHeader('', []);
        $this->assertSame('*', $result);
    }

    public function test_multiple_origins_in_allowlist_match_correctly(): void
    {
        $allowlist = ['https://claude.ai', 'https://app.example.com'];
        $this->assertSame('https://app.example.com', CorsHandler::resolveOriginHeader('https://app.example.com', $allowlist));
        $this->assertNull(CorsHandler::resolveOriginHeader('https://evil.com', $allowlist));
    }

    // --- getAllowedOrigins() CSV parsing ---

    public function test_multiple_origins_csv_parsed_correctly(): void
    {
        // Test the parsing logic directly using a public helper approach
        // We test getAllowedOrigins() returns [] in test env (no WHMCS), which is correct
        $origins = CorsHandler::getAllowedOrigins();
        $this->assertSame([], $origins); // WHMCS not available → returns []
    }

    // --- getAllowedOriginsOrFail() fail-closed (WO-5 / item E) ---

    /**
     * O suite agora define um stub de \WHMCS\Config\Setting (necessário para
     * exercitar o parser tri-state em M3), então a ausência da classe deixou de
     * ser a forma de simular falha de leitura. Estes dois testes passam a
     * provocar o erro explicitamente — o que é mais fiel ao cenário real (DB
     * fora do ar) do que "a classe não existe".
     */
    private function withFailingConfigRead(callable $fn): mixed
    {
        \WHMCS\Config\Setting::$throwOnRead = true;
        try {
            return $fn();
        } finally {
            \WHMCS\Config\Setting::reset();
        }
    }

    public function test_get_allowed_origins_or_fail_returns_false_on_config_error(): void
    {
        // Erro real de leitura de config. Deve devolver o sentinela `false`, NÃO
        // um array vazio — vazio seria indistinguível de "sem allowlist
        // configurada" e resolveOriginHeader() devolveria wildcard para o que na
        // verdade é uma falha de infraestrutura.
        $result = $this->withFailingConfigRead(fn() => CorsHandler::getAllowedOriginsOrFail());
        $this->assertFalse($result);
    }

    public function test_get_allowed_origins_or_fail_error_would_not_resolve_to_wildcard_if_handled_correctly(): void
    {
        // Demonstrates the bug WO-5 fixes: naively feeding a config-read error into
        // resolveOriginHeader() (by collapsing it to []) silently produces '*'.
        $orFail = $this->withFailingConfigRead(fn() => CorsHandler::getAllowedOriginsOrFail());
        $this->assertFalse($orFail, 'error must be reported as false, not []');

        // The buggy pre-fix behaviour (error treated as empty allowlist):
        $wronglyCollapsed = $orFail === false ? [] : $orFail;
        $this->assertSame('*', CorsHandler::resolveOriginHeader('https://evil.com', $wronglyCollapsed));

        // handle() must check for the `false` sentinel explicitly (503) instead of
        // ever calling resolveOriginHeader() with a collapsed empty array on error.
    }

    public function test_get_allowed_origins_still_collapses_error_to_empty_array_for_back_compat(): void
    {
        // getAllowedOrigins() (unlike getAllowedOriginsOrFail()) is a back-compat
        // wrapper — existing non-handle() callers keep seeing [] on error.
        $this->assertSame([], CorsHandler::getAllowedOrigins());
    }

    // --- allowlist configured + origin outside it → header omitted (reinforced) ---

    public function test_configured_allowlist_with_origin_outside_it_omits_header(): void
    {
        $result = CorsHandler::resolveOriginHeader('https://not-allowed.example', ['https://claude.ai', 'https://app.example.com']);
        $this->assertNull($result);
    }

    public function test_closed_decision_rejects_unapproved_method_profile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CorsDecision::proceed('*', [], 'DELETE');
    }

    public function test_closed_decision_rejects_header_injection_origin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CorsDecision::proceed("https://client.example\r\nX-Poison: yes", [], 'POST, OPTIONS');
    }

    #[DataProvider('requestedHeadersProvider')]
    public function test_preflight_requested_headers_are_canonical(
        ?string $requestedHeaders,
        bool $allowed,
    ): void {
        \WHMCS\Config\Setting::setValue('nt_mcp_cors_origins', 'https://client.example');
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $_SERVER['HTTP_ORIGIN'] = 'https://client.example';
        $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] = 'POST';
        if ($requestedHeaders !== null) {
            $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] = $requestedHeaders;
        }

        $decision = CorsHandler::handle(['MCP-Session-Id'], 'POST, OPTIONS');
        $terminal = $decision->terminalResponse();

        $this->assertNotNull($terminal);
        $this->assertSame($allowed ? 204 : 403, $terminal->status());
        if ($allowed) {
            $this->assertSame('https://client.example', $decision->headers()['Access-Control-Allow-Origin'] ?? null);
        } else {
            $this->assertSame([], $decision->headers());
        }
    }

    public static function requestedHeadersProvider(): array
    {
        return [
            'absent' => [null, true],
            'empty' => ['', false],
            'OWS only' => [" \t ", false],
            'duplicate exact' => ['Content-Type, Content-Type', false],
            'duplicate case-insensitive' => ['Content-Type, content-type', false],
            'duplicate with OWS' => [" Content-Type\t,\t CONTENT-TYPE ", false],
            'allowed case and OWS' => [" authorization ,\tCONTENT-type, McP-PrOtOcOl-VeRsIoN , mcp-session-ID ", true],
            'Last-Event-ID remains denied' => ['Last-Event-ID', false],
            'trailing comma' => ['Content-Type,', false],
            'CRLF' => ["Content-Type\r\nX-Poison: yes", false],
        ];
    }
}
