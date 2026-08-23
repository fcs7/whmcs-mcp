<?php

declare(strict_types=1);

namespace NtMcp\Tests\Http;

use NtMcp\Http\TerminalResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TerminalResponseTest extends TestCase
{
    #[DataProvider('contentLengthProvider')]
    public function test_content_length_policy_omits_for_bodyless_statuses_and_counts_bytes(
        int $status,
        string $body,
        ?int $expected,
    ): void {
        $this->assertSame($expected, TerminalResponse::contentLength($status, $body));
    }

    public static function contentLengthProvider(): array
    {
        return [
            '100' => [100, '', null],
            '150' => [150, 'ignored', null],
            '199' => [199, '', null],
            '204' => [204, '', null],
            '304' => [304, 'ignored', null],
            '200 ASCII' => [200, 'body', 4],
            '200 UTF-8 bytes' => [200, 'ação', 6],
            '403 JSON' => [403, '{"error":"denied"}', 18],
        ];
    }
}
