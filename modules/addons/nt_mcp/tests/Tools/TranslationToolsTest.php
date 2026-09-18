<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\TranslationTools;
use NtMcp\Translation\EmailTemplateRepository;
use NtMcp\Translation\TranslationBackup;
use NtMcp\Translation\TranslationGuard;
use NtMcp\Translation\TranslationSchemaGuard;
use NtMcp\Translation\TranslationStatusReader;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use NtMcp\Whmcs\AuthorizationException;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranslationToolsTest extends TestCase
{
    private const COLUMNS = [
        'id', 'type', 'name', 'subject', 'message', 'language', 'fromname', 'fromemail',
        'attachments', 'copyto', 'blind_copy_to', 'plaintext', 'disabled', 'custom',
    ];

    protected function setUp(): void
    {
        FakeCapsule::reset();
    }

    protected function tearDown(): void
    {
        FakeCapsule::reset();
    }

    private function common(array $overrides): array
    {
        return $overrides + [
            'fromname' => 'Suporte', 'fromemail' => 'suporte@ntweb.com.br', 'attachments' => '',
            'copyto' => '', 'blind_copy_to' => '', 'plaintext' => '0', 'disabled' => '0', 'custom' => '0',
        ];
    }

    private function seedRows(): void
    {
        FakeCapsule::withRows('tblemailtemplates', [
            $this->common(['id' => 4, 'type' => 'general', 'name' => 'Invoice', 'subject' => 'Fatura', 'message' => 'Sua fatura chegou', 'language' => '']),
        ]);
    }

    private function healthyGuard(): TranslationSchemaGuard
    {
        return new TranslationSchemaGuard(new FakeCrmSchemaProbe(['tblemailtemplates' => self::COLUMNS]));
    }

    private function tools(array $gates = ['write' => true, 'readonly' => false]): TranslationTools
    {
        return new TranslationTools(
            new EmailTemplateRepository($this->healthyGuard()),
            new TranslationGuard($gates),
            new TranslationBackup(sys_get_temp_dir() . '/nt_mcp_tools_test_' . uniqid('', true)),
            new TranslationStatusReader(static fn(): string => 'unknown', new FakeCrmSchemaProbe(['tblemailtemplates' => self::COLUMNS]))
        );
    }

    private function payload(string|CallToolResult $result): array
    {
        $json = $result instanceof CallToolResult ? $result->content[0]->text : $result;

        return json_decode($json, true);
    }

    // -----------------------------------------------------------
    // email_set
    // -----------------------------------------------------------

    #[Test]
    public function set_without_confirm_is_a_dry_run_and_skips_the_gate(): void
    {
        $this->seedRows();
        $tools = $this->tools(['write' => false, 'readonly' => true]);

        $payload = $this->payload($tools->emailSet(
            [['id' => 4, 'subject' => 'Your invoice', 'message' => 'Your invoice arrived', 'expected_hash' => 'absent']],
            false
        ));

        $this->assertSame('success', $payload['result']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function set_with_confirm_requires_the_write_gate(): void
    {
        $this->seedRows();
        $tools = $this->tools(['write' => false, 'readonly' => false]);

        $this->expectException(AuthorizationException::class);
        $tools->emailSet(
            [['id' => 4, 'subject' => 'Your invoice', 'message' => 'Your invoice arrived', 'expected_hash' => 'absent']],
            true
        );
    }

    #[Test]
    public function set_with_confirm_writes_the_whole_batch(): void
    {
        $this->seedRows();
        $tools = $this->tools(['write' => true, 'readonly' => false]);

        $payload = $this->payload($tools->emailSet(
            [['id' => 4, 'subject' => 'Your invoice', 'message' => 'Your invoice arrived', 'expected_hash' => 'absent']],
            true
        ));

        $this->assertSame('success', $payload['result']);
        $this->assertFalse($payload['dry_run']);
        $insertCount = count(array_filter(FakeCapsule::$mutations, static fn($m) => $m['verb'] === 'INSERT'));
        $this->assertSame(1, $insertCount);
    }

    #[Test]
    public function set_rejects_more_than_ten_items(): void
    {
        $this->seedRows();
        $tools = $this->tools();

        $items = array_fill(0, 11, ['id' => 4, 'subject' => 'x', 'message' => 'y', 'expected_hash' => 'absent']);
        $result = $tools->emailSet($items, false);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertTrue($result->isError);
        $payload = $this->payload($result);
        $this->assertSame('invalid_items', $payload['error_code']);
    }

    #[Test]
    public function set_reports_hash_conflict_as_tool_error(): void
    {
        $this->seedRows();
        $tools = $this->tools();

        $result = $tools->emailSet(
            [['id' => 4, 'subject' => 'x', 'message' => 'y', 'expected_hash' => 'wrong']],
            false
        );

        $this->assertInstanceOf(CallToolResult::class, $result);
        $payload = $this->payload($result);
        $this->assertSame('hash_conflict', $payload['error_code']);
    }

    // -----------------------------------------------------------
    // email_list / email_get / status
    // -----------------------------------------------------------

    #[Test]
    public function list_returns_only_missing_by_default(): void
    {
        $this->seedRows();
        $tools = $this->tools();

        $payload = $this->payload($tools->emailList());

        $this->assertSame('success', $payload['result']);
        $this->assertSame([4], array_column($payload['items'], 'id'));
    }

    #[Test]
    public function get_reports_absent_hash(): void
    {
        $this->seedRows();
        $tools = $this->tools();

        $payload = $this->payload($tools->emailGet([4]));

        $this->assertSame('absent', $payload['pairs'][0]['target_hash']);
    }

    #[Test]
    public function get_accepts_portuguese_br_as_target_language(): void
    {
        $this->seedRows();
        $tools = $this->tools();

        $payload = $this->payload($tools->emailGet([4], 'portuguese-br'));

        $this->assertSame('portuguese-br', $payload['target_language']);
    }

    #[Test]
    public function list_rejects_invalid_target_language(): void
    {
        $this->seedRows();
        $tools = $this->tools();

        $result = $tools->emailList(target_language: 'french');

        $this->assertInstanceOf(CallToolResult::class, $result);
        $payload = $this->payload($result);
        $this->assertSame('invalid_target_language', $payload['error_code']);
    }

    #[Test]
    public function status_reports_language_counts_and_dynamic_flag(): void
    {
        $this->seedRows();
        FakeCapsule::withRows('tblclients', [['language' => 'english'], ['language' => '']]);
        $tools = $this->tools();

        $payload = $this->payload($tools->status());

        $this->assertSame('success', $payload['result']);
        $this->assertSame('', $payload['source_language']);
        $this->assertSame('english', $payload['target_language']);
        $this->assertSame(['english', 'portuguese-br'], $payload['supported_target_languages']);
        $this->assertSame('unknown', $payload['dynamic_translations_enabled']);
        $this->assertArrayHasKey('client_languages', $payload);
        $this->assertSame(['by_language' => [], 'by_related_type' => []], $payload['dynamic_translations']);
    }
}
