<?php

declare(strict_types=1);

namespace NtMcp\Tests\Translation;

use NtMcp\Translation\TranslationValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranslationValidatorTest extends TestCase
{
    private function validator(): TranslationValidator
    {
        return new TranslationValidator();
    }

    #[Test]
    public function accepts_matching_smarty_and_html(): void
    {
        $errors = $this->validator()->validate(
            'Ola {$client_name}',
            '<p>Bem-vindo {$client_name}</p>',
            'Hello {$client_name}',
            '<p>Welcome {$client_name}</p>'
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function rejects_missing_smarty_token(): void
    {
        $errors = $this->validator()->validate(
            'Ola {$client_name}',
            'Corpo {$invoice_id}',
            'Hello',
            'Body'
        );

        $codes = array_column($errors, 'code');
        $this->assertContains('smarty_token_mismatch', $codes);
    }

    #[Test]
    public function rejects_extra_smarty_token(): void
    {
        $errors = $this->validator()->validate(
            'Ola',
            'Corpo',
            'Hello {$extra}',
            'Body'
        );

        $codes = array_column($errors, 'code');
        $this->assertContains('smarty_token_mismatch', $codes);
    }

    #[Test]
    public function rejects_altered_smarty_token(): void
    {
        $errors = $this->validator()->validate(
            'Ola {$client_name}',
            'Corpo',
            'Hello {$other_name}',
            'Body'
        );

        $codes = array_column($errors, 'code');
        $this->assertContains('smarty_token_mismatch', $codes);
    }

    #[Test]
    public function rejects_missing_html_tag(): void
    {
        $errors = $this->validator()->validate(
            'Subject',
            '<p>Corpo</p><div>rodape</div>',
            'Subject',
            '<p>Body</p>'
        );

        $codes = array_column($errors, 'code');
        $this->assertContains('html_tag_mismatch', $codes);
    }

    #[Test]
    public function rejects_extra_html_tag(): void
    {
        $errors = $this->validator()->validate(
            'Subject',
            '<p>Corpo</p>',
            'Subject',
            '<p>Body</p><div>extra</div>'
        );

        $codes = array_column($errors, 'code');
        $this->assertContains('html_tag_mismatch', $codes);
    }

    #[Test]
    public function rejects_empty_subject(): void
    {
        $errors = $this->validator()->validate('Subject', 'Corpo', '   ', 'Body');

        $codes = array_column($errors, 'code');
        $this->assertContains('empty_subject', $codes);
    }

    #[Test]
    public function rejects_empty_message(): void
    {
        $errors = $this->validator()->validate('Subject', 'Corpo', 'Subject EN', '   ');

        $codes = array_column($errors, 'code');
        $this->assertContains('empty_message', $codes);
    }

    #[Test]
    public function rejects_subject_over_255_chars(): void
    {
        $errors = $this->validator()->validate('Subject', 'Corpo', str_repeat('a', 256), 'Body');

        $codes = array_column($errors, 'code');
        $this->assertContains('subject_too_long', $codes);
    }

    #[Test]
    public function rejects_emoji_in_subject(): void
    {
        $errors = $this->validator()->validate('Subject', 'Corpo', "Hello \u{1F600}", 'Body');

        $codes = array_column($errors, 'code');
        $this->assertContains('unsupported_4byte_char', $codes);
    }

    #[Test]
    public function rejects_emoji_in_message(): void
    {
        $errors = $this->validator()->validate('Subject', 'Corpo', 'Subject', "Body \u{1F600}");

        $codes = array_column($errors, 'code');
        $this->assertContains('unsupported_4byte_char', $codes);
    }

    #[Test]
    public function rejects_invalid_utf8_in_subject(): void
    {
        $errors = $this->validator()->validate('Subject', 'Corpo', "Bad \xB1\x31", 'Body');

        $codes = array_column($errors, 'code');
        $this->assertContains('invalid_utf8', $codes);
    }

    #[Test]
    public function rejects_invalid_utf8_in_message(): void
    {
        $errors = $this->validator()->validate('Subject', 'Corpo', 'Subject', "Bad \xB1\x31");

        $codes = array_column($errors, 'code');
        $this->assertContains('invalid_utf8', $codes);
    }

    #[Test]
    public function accepts_regular_bmp_accented_characters(): void
    {
        $errors = $this->validator()->validate('Ola', 'Corpo', 'Olá, você está em dia', 'Corpo em português');

        $this->assertSame([], $errors);
    }

    #[Test]
    public function error_detail_never_carries_the_full_body(): void
    {
        $secretBody = 'CONFIDENCIAL-' . str_repeat('X', 500);
        $errors = $this->validator()->validate('Subject', $secretBody . '{$token}', 'Subject', 'Body sem token');

        foreach ($errors as $error) {
            $this->assertStringNotContainsString($secretBody, $error['detail']);
        }
    }
}
