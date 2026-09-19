<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\TranslationContentTools;
use NtMcp\Translation\AnnouncementRepository;
use NtMcp\Translation\KnowledgebaseRepository;
use NtMcp\Translation\TranslationBackup;
use NtMcp\Translation\TranslationGuard;
use NtMcp\Translation\TranslationSchemaGuard;
use NtMcp\Tests\Support\FakeCapsule;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use NtMcp\Whmcs\AuthorizationException;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranslationContentToolsTest extends TestCase
{
    private const ARTICLE_COLUMNS = ['id', 'title', 'article', 'views', 'votes', 'useful', 'private', 'order', 'parentid', 'language'];

    private const CAT_COLUMNS = ['id', 'parentid', 'name', 'description', 'hidden', 'catid', 'language'];

    private const ANNOUNCEMENT_COLUMNS = ['id', 'date', 'title', 'announcement', 'published', 'parentid', 'language'];

    protected function setUp(): void
    {
        FakeCapsule::reset();
    }

    protected function tearDown(): void
    {
        FakeCapsule::reset();
    }

    private function healthyProbe(): FakeCrmSchemaProbe
    {
        return new FakeCrmSchemaProbe([
            'tblknowledgebase' => self::ARTICLE_COLUMNS,
            'tblknowledgebasecats' => self::CAT_COLUMNS,
            'tblannouncements' => self::ANNOUNCEMENT_COLUMNS,
        ]);
    }

    private function seedArticles(): void
    {
        FakeCapsule::withRows('tblknowledgebase', [
            ['id' => 1, 'title' => 'Reset de senha', 'article' => 'Instrucoes', 'views' => 0, 'votes' => 0, 'useful' => 0, 'private' => '0', 'order' => 1, 'parentid' => 0, 'language' => ''],
        ]);
    }

    private function seedCategories(): void
    {
        FakeCapsule::withRows('tblknowledgebasecats', [
            ['id' => 1, 'parentid' => 0, 'name' => 'Geral', 'description' => 'Categoria geral', 'hidden' => '0', 'catid' => 0, 'language' => ''],
        ]);
    }

    private function seedAnnouncements(): void
    {
        FakeCapsule::withRows('tblannouncements', [
            ['id' => 1, 'date' => '2026-01-01', 'title' => 'Novidade', 'announcement' => 'Corpo', 'published' => '1', 'parentid' => 0, 'language' => ''],
        ]);
    }

    private function tools(array $gates = ['write' => true, 'readonly' => false]): TranslationContentTools
    {
        $probe = $this->healthyProbe();
        $guard = new TranslationSchemaGuard($probe);
        $backup = new TranslationBackup(sys_get_temp_dir() . '/nt_mcp_content_tools_test_' . uniqid('', true));

        return new TranslationContentTools(
            new KnowledgebaseRepository($guard),
            new AnnouncementRepository($guard),
            new TranslationGuard($gates),
            $backup
        );
    }

    private function payload(string|CallToolResult $result): array
    {
        $json = $result instanceof CallToolResult ? $result->content[0]->text : $result;

        return json_decode($json, true);
    }

    // -----------------------------------------------------------
    // kb_category
    // -----------------------------------------------------------

    #[Test]
    public function kb_category_list_returns_success(): void
    {
        $this->seedCategories();

        $result = $this->payload($this->tools()->kbCategoryList());

        $this->assertSame('success', $result['result']);
        $this->assertSame([1], array_column($result['items'], 'id'));
    }

    #[Test]
    public function kb_category_set_rejects_invalid_item_count(): void
    {
        $result = $this->payload($this->tools()->kbCategorySet([]));

        $this->assertSame('error', $result['result']);
        $this->assertSame('invalid_items', $result['error_code']);
    }

    #[Test]
    public function kb_category_set_dry_run_never_writes_and_skips_gate(): void
    {
        $this->seedCategories();
        $tools = $this->tools(['write' => false, 'readonly' => false]);

        $result = $this->payload($tools->kbCategorySet(
            [['id' => 1, 'name' => 'General', 'description' => 'General category', 'expected_hash' => 'absent']],
            false
        ));

        $this->assertSame('success', $result['result']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function kb_category_set_confirm_true_requires_write_gate(): void
    {
        $this->seedCategories();
        $tools = $this->tools(['write' => false, 'readonly' => false]);

        $this->expectException(AuthorizationException::class);
        $tools->kbCategorySet(
            [['id' => 1, 'name' => 'General', 'description' => 'General category', 'expected_hash' => 'absent']],
            true
        );
    }

    #[Test]
    public function kb_category_set_confirm_true_writes_and_audits(): void
    {
        $this->seedCategories();
        $tools = $this->tools();

        $result = $this->payload($tools->kbCategorySet(
            [['id' => 1, 'name' => 'General', 'description' => 'General category', 'expected_hash' => 'absent']],
            true
        ));

        $this->assertSame('success', $result['result']);
        $this->assertFalse($result['dry_run']);
        $insert = null;
        foreach (FakeCapsule::$mutations as $mutation) {
            if ($mutation['verb'] === 'INSERT') {
                $insert = $mutation;
            }
        }
        $this->assertNotNull($insert);
    }

    // -----------------------------------------------------------
    // kb_article
    // -----------------------------------------------------------

    #[Test]
    public function kb_article_list_and_get_return_success(): void
    {
        $this->seedArticles();
        $tools = $this->tools();

        $list = $this->payload($tools->kbArticleList());
        $this->assertSame('success', $list['result']);

        $get = $this->payload($tools->kbArticleGet([1]));
        $this->assertSame('success', $get['result']);
        $this->assertSame('Reset de senha', $get['items'][0]['source']['title']);
    }

    #[Test]
    public function kb_article_set_rejects_invalid_item_count(): void
    {
        $result = $this->payload($this->tools()->kbArticleSet(array_fill(0, 11, ['id' => 1, 'title' => 'x', 'article' => 'y', 'expected_hash' => 'absent'])));

        $this->assertSame('invalid_items', $result['error_code']);
    }

    #[Test]
    public function kb_article_set_confirm_true_writes_and_audits(): void
    {
        $this->seedArticles();
        $tools = $this->tools();

        $result = $this->payload($tools->kbArticleSet(
            [['id' => 1, 'title' => 'Password reset', 'article' => 'Instructions', 'expected_hash' => 'absent']],
            true
        ));

        $this->assertSame('success', $result['result']);
        $this->assertSame('insert', $result['items'][0]['action']);
    }

    // -----------------------------------------------------------
    // announcement
    // -----------------------------------------------------------

    #[Test]
    public function announcement_list_and_get_return_success(): void
    {
        $this->seedAnnouncements();
        $tools = $this->tools();

        $list = $this->payload($tools->announcementList());
        $this->assertSame('success', $list['result']);

        $get = $this->payload($tools->announcementGet([1]));
        $this->assertSame('success', $get['result']);
        $this->assertSame('Novidade', $get['items'][0]['source']['title']);
    }

    #[Test]
    public function announcement_set_dry_run_never_writes_and_skips_gate(): void
    {
        $this->seedAnnouncements();
        $tools = $this->tools(['write' => false, 'readonly' => false]);

        $result = $this->payload($tools->announcementSet(
            [['id' => 1, 'title' => 'News', 'announcement' => 'Body', 'expected_hash' => 'absent']],
            false
        ));

        $this->assertSame('success', $result['result']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame([], FakeCapsule::$mutations);
    }

    #[Test]
    public function announcement_set_confirm_true_requires_write_gate(): void
    {
        $this->seedAnnouncements();
        $tools = $this->tools(['write' => false, 'readonly' => false]);

        $this->expectException(AuthorizationException::class);
        $tools->announcementSet(
            [['id' => 1, 'title' => 'News', 'announcement' => 'Body', 'expected_hash' => 'absent']],
            true
        );
    }

    #[Test]
    public function announcement_set_confirm_true_writes_and_audits(): void
    {
        $this->seedAnnouncements();
        $tools = $this->tools();

        $result = $this->payload($tools->announcementSet(
            [['id' => 1, 'title' => 'News', 'announcement' => 'Body', 'expected_hash' => 'absent']],
            true
        ));

        $this->assertSame('success', $result['result']);
        $insert = null;
        foreach (FakeCapsule::$mutations as $mutation) {
            if ($mutation['verb'] === 'INSERT') {
                $insert = $mutation;
            }
        }
        $this->assertNotNull($insert);
        $this->assertSame('2026-01-01', $insert['values']['date']);
        $this->assertSame('1', $insert['values']['published']);
    }

    #[Test]
    public function readonly_master_switch_blocks_confirm_true_even_with_write_enabled(): void
    {
        $this->seedAnnouncements();
        $tools = $this->tools(['write' => true, 'readonly' => true]);

        $this->expectException(AuthorizationException::class);
        $tools->announcementSet(
            [['id' => 1, 'title' => 'News', 'announcement' => 'Body', 'expected_hash' => 'absent']],
            true
        );
    }
}
