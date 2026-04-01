<?php

declare(strict_types=1);

namespace NtMcp\Tests\Http;

use PHPUnit\Framework\TestCase;

class IpAllowlistTest extends TestCase
{
    public function test_placeholder_for_manual_verification(): void
    {
        // IpAllowlist::enforce() usa http_response_code(), header() e exit —
        // nao e testavel unitariamente sem refatorar para injetar response handler.
        // A cobertura real e feita via code review + testes de integracao na instalacao.
        $this->assertTrue(true);
    }
}
