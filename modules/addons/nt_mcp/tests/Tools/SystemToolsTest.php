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

}
