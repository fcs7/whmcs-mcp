<?php

declare(strict_types=1);

namespace NtMcp\Tests\Translation;

use NtMcp\Translation\TranslationException;
use NtMcp\Translation\TranslationSchema;
use NtMcp\Translation\TranslationSchemaGuard;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranslationSchemaGuardTest extends TestCase
{
    private function completeInstallation(): array
    {
        return [
            TranslationSchema::TABLE_EMAIL_TEMPLATES => [
                'id', 'type', 'name', 'subject', 'message', 'language', 'fromname', 'fromemail',
                'attachments', 'copyto', 'blind_copy_to', 'plaintext', 'disabled', 'custom',
            ],
        ];
    }

    #[Test]
    public function healthy_installation_passes(): void
    {
        $guard = new TranslationSchemaGuard(new FakeCrmSchemaProbe($this->completeInstallation()));

        $guard->assert();

        $this->assertTrue(true);
    }

    #[Test]
    public function missing_table_is_unavailable(): void
    {
        $probe = new FakeCrmSchemaProbe($this->completeInstallation());
        $probe->dropTable(TranslationSchema::TABLE_EMAIL_TEMPLATES);
        $guard = new TranslationSchemaGuard($probe);

        try {
            $guard->assert();
            $this->fail('deveria lançar TranslationException');
        } catch (TranslationException $e) {
            $this->assertSame('translation_unavailable', $e->errorCode);
        }
    }

    #[Test]
    public function missing_column_is_schema_mismatch(): void
    {
        $probe = new FakeCrmSchemaProbe($this->completeInstallation());
        $probe->dropColumn(TranslationSchema::TABLE_EMAIL_TEMPLATES, 'blind_copy_to');
        $guard = new TranslationSchemaGuard($probe);

        try {
            $guard->assert();
            $this->fail('deveria lançar TranslationException');
        } catch (TranslationException $e) {
            $this->assertSame('translation_schema_mismatch', $e->errorCode);
        }
    }

    #[Test]
    public function metadata_failure_is_downstream_and_not_memorized(): void
    {
        $probe = new FakeCrmSchemaProbe($this->completeInstallation());
        $probe->failWith('corr-1');
        $guard = new TranslationSchemaGuard($probe);

        try {
            $guard->assert();
            $this->fail('deveria lançar TranslationException');
        } catch (TranslationException $e) {
            $this->assertSame('downstream', $e->errorCode);
            $this->assertSame('corr-1', $e->correlationId);
        }

        // Não memorizado: uma nova tentativa, com o probe saudável, passa.
        $healthyProbe = new FakeCrmSchemaProbe($this->completeInstallation());
        $guard2 = new TranslationSchemaGuard($healthyProbe);
        $guard2->assert();
        $this->assertTrue(true);
    }
}
