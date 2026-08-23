<?php

declare(strict_types=1);

namespace NtMcp\Tests\Http;

use PHPUnit\Framework\TestCase;

class IpAllowlistTest extends TestCase
{
    public function test_placeholder_for_manual_verification(): void
    {
        // A cobertura efetiva dos envelopes 403/503 vive em
        // McpEndpointHttpTest, pelo endpoint e servidor HTTP reais.
        $this->assertTrue(true);
    }
}
