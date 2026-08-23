<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\SystemTools;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

class SystemToolsTest extends TestCase
{
    private function makeTools(?callable $callable = null, array $gates = ['readonly' => true]): SystemTools
    {
        $api = new LocalApiClient('testadmin');
        $api->setGates($gates);
        $api->setCallable($callable ?? function (string $cmd, array $params) {
            return ['result' => 'success'];
        });
        // Pour les tests, résoudre testadmin à un ID arbitraire
        $api->setAdminIdResolver(fn(string $username) => $username === 'testadmin' ? 42 : null);

        return new SystemTools($api);
    }

    /** Regressão (2026-08-23): tool documentada em docs/TOOLS.md, nunca implementada. */
    public function test_get_currencies_calls_get_currencies(): void
    {
        $captured = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$captured) {
            $captured = ['cmd' => $cmd, 'params' => $params];
            return ['result' => 'success', 'currencies' => ['currency' => [
                ['id' => 1, 'code' => 'BRL', 'prefix' => 'R$'],
            ]]];
        });

        $result = json_decode($tools->getCurrencies(), true);

        $this->assertSame('GetCurrencies', $captured['cmd']);
        $this->assertSame('BRL', $result['currencies']['currency'][0]['code']);
    }

    /**
     * Com "Hooks Debug" ligado, o WHMCS grava uma linha por hook: o log do
     * desenv chegou a 905.222 entradas e as primeiras páginas eram só isso.
     * O filtro é client-side porque a API só oferece filtro POSITIVO de
     * `description` — daí `filtered_out` ser reportado explicitamente.
     */
    public function test_activity_log_drops_hook_debug_noise_and_reports_the_count(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'totalresults' => 3, 'activity' => ['entry' => [
            ['id' => 1, 'description' => 'Hooks Debug: ClientAreaPage'],
            ['id' => 2, 'description' => 'Admin Login - admin'],
            ['id' => 3, 'description' => 'Hooks Debug: InvoiceCreated'],
        ]]]);

        $data = json_decode($tools->getActivityLog(), true);

        $this->assertSame(2, $data['filtered_out']);
        $this->assertCount(1, $data['activity']['entry']);
        $this->assertSame('Admin Login - admin', $data['activity']['entry'][0]['description']);
    }

    public function test_activity_log_keeps_everything_when_the_filter_is_disabled(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'activity' => ['entry' => [
            ['id' => 1, 'description' => 'Hooks Debug: ClientAreaPage'],
            ['id' => 2, 'description' => 'Admin Login - admin'],
        ]]]);

        $data = json_decode($tools->getActivityLog(hide_hook_debug: false), true);

        $this->assertArrayNotHasKey('filtered_out', $data);
        $this->assertCount(2, $data['activity']['entry']);
    }

    /**
     * Regressão (2026-08-23): página inicial 100% "Hooks Debug" fazia a tool
     * devolver `activity.entry` vazio mesmo com linha real logo na página
     * seguinte. A sonda (2ª chamada em diante) pede um lote de 200, maior
     * que o `limitnum` do chamador.
     */
    public function test_activity_log_advances_pages_when_the_first_page_is_all_noise(): void
    {
        $calls = [];
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$calls) {
            $calls[] = $params;
            if ($params['limitstart'] === 0) {
                return [
                    'result' => 'success',
                    'totalresults' => 200,
                    'activity' => ['entry' => array_fill(0, 25, ['id' => 1, 'description' => 'Hooks Debug: Noise'])],
                ];
            }

            return [
                'result' => 'success',
                'totalresults' => 200,
                'activity' => ['entry' => [
                    ['id' => 2, 'description' => 'Hooks Debug: Noise'],
                    ['id' => 3, 'description' => 'Admin Login - admin'],
                ]],
            ];
        });

        $data = json_decode($tools->getActivityLog(), true);

        $this->assertCount(1, $data['activity']['entry']);
        $this->assertSame('Admin Login - admin', $data['activity']['entry'][0]['description']);
        $this->assertSame(26, $data['filtered_out']);
        $this->assertSame(2, $data['pages_scanned']);
        $this->assertArrayNotHasKey('scan_capped', $data);
        $this->assertSame(0, $calls[0]['limitstart']);
        $this->assertSame(25, $calls[0]['limitnum']);
        $this->assertSame(25, $calls[1]['limitstart']);
        $this->assertSame(200, $calls[1]['limitnum'], 'sonda pede lote maior que o limitnum do chamador');
    }

    /** Regressão (2026-08-23): teto de segurança precisa avisar quando o resultado fica parcial. */
    public function test_activity_log_flags_scan_capped_when_the_call_ceiling_is_hit(): void
    {
        $tools = $this->makeTools(fn() => [
            'result' => 'success',
            'totalresults' => 1000000,
            'activity' => ['entry' => array_fill(0, 25, ['id' => 1, 'description' => 'Hooks Debug: Noise'])],
        ]);

        $data = json_decode($tools->getActivityLog(), true);

        $this->assertSame([], $data['activity']['entry']);
        $this->assertSame(20, $data['pages_scanned']);
        $this->assertTrue($data['scan_capped']);
    }

    // ---------------------------------------------------------------
    // #22: updateToDoItem accepts duedate
    // ---------------------------------------------------------------

    /** #22 — duedate normalizado pelo DateNormalizer. */
    public function test_update_todo_item_accepts_duedate_ymd(): void
    {
        $captured = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$captured) {
            $captured = ['cmd' => $cmd, 'params' => $params];
            return ['result' => 'success'];
        }, ['write' => true]);

        $tools->updateToDoItem(42, duedate: '2026-12-25');

        $this->assertSame('UpdateToDoItem', $captured['cmd']);
        $this->assertSame('2026-12-25', $captured['params']['duedate']);
    }

    /** #22 — duedate vazio é omitido. */
    public function test_update_todo_item_omits_empty_duedate(): void
    {
        $captured = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$captured) {
            $captured = ['cmd' => $cmd, 'params' => $params];
            return ['result' => 'success'];
        }, ['write' => true]);

        $tools->updateToDoItem(42, duedate: '');

        $this->assertArrayNotHasKey('duedate', $captured['params']);
    }

    /** #22 — duedate localizado é rejeitado. */
    public function test_update_todo_item_rejects_localized_duedate(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success']);

        $this->expectException(\InvalidArgumentException::class);
        $tools->updateToDoItem(42, duedate: '31/12/2026');
    }

    // ---------------------------------------------------------------
    // #23: getAdminDetails exposes CRM capability
    // ---------------------------------------------------------------

    /** #23 — probe true → available */
    public function test_get_admin_details_shows_crm_available_when_probe_true(): void
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable(fn() => ['result' => 'success', 'id' => 1, 'username' => 'admin']);
        $api->setAdminIdResolver(fn(string $username) => $username === 'testadmin' ? 42 : null);

        $tools = new \NtMcp\Tools\SystemTools($api, null, fn() => true);

        $result = json_decode($tools->getAdminDetails(), true);

        $this->assertSame('available', $result['capabilities']['crm']);
    }

    /** #23 — probe false → unavailable */
    public function test_get_admin_details_shows_crm_unavailable_when_probe_false(): void
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable(fn() => ['result' => 'success', 'id' => 1, 'username' => 'admin']);
        $api->setAdminIdResolver(fn(string $username) => $username === 'testadmin' ? 42 : null);

        $tools = new \NtMcp\Tools\SystemTools($api, null, fn() => false);

        $result = json_decode($tools->getAdminDetails(), true);

        $this->assertSame('unavailable', $result['capabilities']['crm']);
    }

    /** #23 — probe exception → unavailable */
    public function test_get_admin_details_shows_crm_unavailable_when_probe_throws(): void
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable(fn() => ['result' => 'success', 'id' => 1, 'username' => 'admin']);
        $api->setAdminIdResolver(fn(string $username) => $username === 'testadmin' ? 42 : null);

        $tools = new \NtMcp\Tools\SystemTools($api, null, function () {
            throw new \RuntimeException('probe failed');
        });

        $result = json_decode($tools->getAdminDetails(), true);

        $this->assertSame('unavailable', $result['capabilities']['crm']);
    }

    /** #23 — no probe → unknown */
    public function test_get_admin_details_shows_crm_unknown_when_no_probe(): void
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable(fn() => ['result' => 'success', 'id' => 1, 'username' => 'admin']);
        $api->setAdminIdResolver(fn(string $username) => $username === 'testadmin' ? 42 : null);

        $tools = new \NtMcp\Tools\SystemTools($api);

        $result = json_decode($tools->getAdminDetails(), true);

        $this->assertSame('unknown', $result['capabilities']['crm']);
    }

    // ---------------------------------------------------------------
    // #24: getStats returns snapshot note
    // ---------------------------------------------------------------

    /** #24 — getStats inclui nota de snapshot. */
    public function test_get_stats_includes_snapshot_note(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'ticketsstatus' => ['open' => 5]]);

        $result = json_decode($tools->getStats(), true);

        $this->assertSame('snapshot; não é histórico', $result['note']);
    }

}
