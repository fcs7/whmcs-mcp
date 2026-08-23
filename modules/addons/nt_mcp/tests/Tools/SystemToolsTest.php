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
}
