<?php
// tests/Whmcs/ToolJsonTest.php
namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\ToolJson;
use PHPUnit\Framework\TestCase;

class ToolJsonTest extends TestCase
{
    public function test_encodes_normal_payload_with_readable_unicode_and_slashes(): void
    {
        $json = ToolJson::encode(['nome' => 'José', 'url' => 'https://nt.example/a']);

        $this->assertStringContainsString('José', $json);
        $this->assertStringContainsString('https://nt.example/a', $json);
        $this->assertSame(['nome' => 'José', 'url' => 'https://nt.example/a'], json_decode($json, true));
    }

    public function test_compact_encoding_preserves_data_without_presentation_whitespace(): void
    {
        $data = ['nome' => 'José', 'nested' => ['value' => 7]];

        $compact = ToolJson::encodeCompact($data);

        $this->assertSame($data, json_decode($compact, true));
        $this->assertStringNotContainsString("\n", $compact);
        $this->assertLessThan(strlen(ToolJson::encode($data)), strlen($compact));
    }

    /**
     * Dado legado gravado em latin1 fazia `json_encode` devolver `false`, que
     * batia no tipo de retorno `: string` da tool, virava `TypeError` e chegava
     * ao cliente como `-32603` genérico. Agora o byte inválido é substituído e
     * o resto do payload sobrevive.
     */
    public function test_invalid_utf8_is_substituted_instead_of_failing_the_whole_response(): void
    {
        $json = ToolJson::encode(['nome' => "Jos\xE9 legado", 'id' => 7]);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame(7, $decoded['id']);
        $this->assertStringContainsString('legado', $decoded['nome']);
    }

    public function test_unencodable_payload_returns_a_structured_error_not_a_type_error(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;

        $json = ToolJson::encode(['payload' => $recursive]);
        $decoded = json_decode($json, true);

        $this->assertSame('error', $decoded['result']);
        $this->assertSame('response_encoding_failed', $decoded['error_code']);
        $this->assertSame('downstream', $decoded['error_category']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $decoded['correlation_id']);
    }
}
