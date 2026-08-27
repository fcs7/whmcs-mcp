<?php

declare(strict_types=1);

namespace NtMcp\Tests\Admin;

use NtMcp\Admin\GateConfigAction;
use PHPUnit\Framework\TestCase;

final class GateConfigActionTest extends TestCase
{
    public function test_checkbox_present_writes_canonical_one_and_absent_writes_zero(): void
    {
        $result = GateConfigAction::fromPost(
            ['gate' => ['nt_mcp_enable_write' => '1']],
            $this->current(),
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('1', $result['changes']['nt_mcp_enable_write']['new']);
        // Os demais toggles ausentes do POST viram '0' explícito (desmarcar É gravar).
        $this->assertSame('0', $result['changes']['nt_mcp_enable_destructive']['new']);
        $this->assertSame('0', $result['changes']['nt_mcp_readonly']['new']);
    }

    public function test_never_produces_a_toggle_value_outside_the_canonical_pair(): void
    {
        // Valor do checkbox é irrelevante — presença decide. 'on'/'yes'/lixo
        // no POST nunca atravessam para o banco.
        $result = GateConfigAction::fromPost(
            ['gate' => ['nt_mcp_enable_write' => 'yes', 'nt_mcp_enable_cost' => 'garbage']],
            $this->current(),
        );

        $this->assertTrue($result['ok']);
        foreach (GateConfigAction::TOGGLE_KEYS as $key) {
            if (isset($result['changes'][$key])) {
                $this->assertContains($result['changes'][$key]['new'], ['1', '0'], $key);
            }
        }
        $this->assertSame('1', $result['changes']['nt_mcp_enable_write']['new']);
        $this->assertSame('1', $result['changes']['nt_mcp_enable_cost']['new']);
    }

    public function test_unchanged_values_produce_no_diff(): void
    {
        $current = $this->current([
            'nt_mcp_enable_write'              => '1',
            'nt_mcp_readonly'                  => '0',
            'nt_mcp_enable_destructive'        => '0',
            'nt_mcp_enable_financial'          => '0',
            'nt_mcp_enable_cost'               => '0',
            'nt_mcp_enable_comms'              => '0',
            'nt_mcp_write_allowlist_clientids' => '31,42',
        ]);

        $result = GateConfigAction::fromPost(
            [
                'gate' => ['nt_mcp_enable_write' => '1'],
                'nt_mcp_write_allowlist_clientids' => ' 31, 42 ',
                'nt_mcp_write_allowlist_ticketids' => '',
            ],
            $current,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['changes']);
    }

    public function test_allowlist_is_normalized_to_canonical_csv(): void
    {
        $result = GateConfigAction::fromPost(
            ['nt_mcp_write_allowlist_clientids' => ' 31 , 42 ,7 '],
            $this->current(),
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('31,42,7', $result['changes']['nt_mcp_write_allowlist_clientids']['new']);
    }

    public function test_invalid_allowlist_token_rejects_the_whole_form(): void
    {
        $result = GateConfigAction::fromPost(
            [
                'gate' => ['nt_mcp_enable_write' => '1'],
                'nt_mcp_write_allowlist_clientids' => '31,abc',
            ],
            $this->current(),
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('nt_mcp_write_allowlist_clientids', (string) $result['error']);
        // Validação parcial gravaria metade do estado: NADA pode mudar.
        $this->assertSame([], $result['changes']);
    }

    public function test_negative_and_zero_ids_are_rejected(): void
    {
        foreach (['0', '-1', '1,0', '2,-3'] as $csv) {
            $result = GateConfigAction::fromPost(
                ['nt_mcp_write_allowlist_ticketids' => $csv],
                $this->current(),
            );
            $this->assertFalse($result['ok'], "CSV '{$csv}' deveria ser rejeitado");
        }
    }

    public function test_clearing_a_configured_allowlist_writes_empty_string(): void
    {
        $current = $this->current(['nt_mcp_write_allowlist_clientids' => '31']);

        $result = GateConfigAction::fromPost(
            ['nt_mcp_write_allowlist_clientids' => ''],
            $current,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['changes']['nt_mcp_write_allowlist_clientids']['new']);
    }

    public function test_absent_allowlist_with_empty_field_is_not_a_change(): void
    {
        $result = GateConfigAction::fromPost(
            ['nt_mcp_write_allowlist_clientids' => ''],
            $this->current(),
        );

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('nt_mcp_write_allowlist_clientids', $result['changes']);
    }

    public function test_commas_only_allowlist_becomes_empty_string_not_error(): void
    {
        $current = $this->current(['nt_mcp_write_allowlist_clientids' => '31']);

        $result = GateConfigAction::fromPost(
            ['nt_mcp_write_allowlist_clientids' => ' , , '],
            $current,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['changes']['nt_mcp_write_allowlist_clientids']['new']);
    }

    public function test_non_string_allowlist_payload_is_rejected(): void
    {
        $result = GateConfigAction::fromPost(
            ['nt_mcp_write_allowlist_clientids' => ['31']],
            $this->current(),
        );

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['changes']);
    }

    /**
     * @param array<string, ?string> $overrides
     * @return array<string, ?string>
     */
    private function current(array $overrides = []): array
    {
        $current = array_fill_keys(
            array_merge(GateConfigAction::TOGGLE_KEYS, GateConfigAction::ALLOWLIST_KEYS),
            null,
        );

        return array_merge($current, $overrides);
    }
}
