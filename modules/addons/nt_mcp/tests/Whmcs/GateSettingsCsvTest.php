<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\GateSettings;
use PHPUnit\Framework\TestCase;

final class GateSettingsCsvTest extends TestCase
{
    public function test_or_null_variant_returns_null_on_invalid_token_without_audit_side_effect(): void
    {
        $this->assertNull(GateSettings::parseIdCsvOrNull('31,abc'));
        $this->assertNull(GateSettings::parseIdCsvOrNull('0'));
        $this->assertNull(GateSettings::parseIdCsvOrNull('2,-3'));
    }

    public function test_or_null_variant_parses_and_trims_valid_csv(): void
    {
        $this->assertSame([31, 42], GateSettings::parseIdCsvOrNull(' 31 , 42 '));
        // Só vírgulas/espaços = lista vazia, NÃO inválido.
        $this->assertSame([], GateSettings::parseIdCsvOrNull(','));
        $this->assertSame([], GateSettings::parseIdCsvOrNull(' , , '));
    }

    public function test_read_path_keeps_the_fail_closed_contract(): void
    {
        // Contrato original: token inválido → [] (nega tudo) + audit.
        $this->assertSame([], GateSettings::parseIdCsv('31,abc', 'nt_mcp_write_allowlist_clientids'));
        $this->assertSame([31, 42], GateSettings::parseIdCsv('31,42', 'nt_mcp_write_allowlist_clientids'));
    }
}
