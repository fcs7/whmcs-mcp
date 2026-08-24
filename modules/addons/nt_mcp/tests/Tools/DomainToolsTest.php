<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\DomainTools;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

class DomainToolsTest extends TestCase
{
    private function makeTools(?callable $callable = null): DomainTools
    {
        $api = new LocalApiClient('testadmin');
        $api->setGates([]);
        $api->setCallable($callable ?? fn() => ['result' => 'success']);
        return new DomainTools($api);
    }

    // #20 — preço 0.00 é "não configurado", não R$ 0
    public function test_tld_pricing_drops_zero_years_and_flags_unconfigured_fees(): void
    {
        $tools = $this->makeTools(fn() => ['result' => 'success', 'currency' => ['code' => 'BRL'], 'pricing' => [
            'com' => [
                'categories' => ['gTLD'],
                'register' => ['1' => '50.00', '2' => '100.00', '3' => '150.00'],
                'transfer' => ['1' => '50.00', '2' => '0.00', '3' => '0.00'],
                'renew'    => ['1' => '55.00', '2' => '0.00'],
                'grace_period' => ['days' => 30, 'price' => ['amount' => 0, 'formatted' => 'R$ 0,00']],
                'redemption'   => ['days' => 30, 'price' => '80.00'],
            ],
        ]]);
        $tld = json_decode($tools->getTldPricing(), true)['pricing']['com'];

        $this->assertSame([1, 2, 3], array_keys($tld['register']));
        $this->assertSame([1], array_keys($tld['transfer']));
        $this->assertSame([1], array_keys($tld['renew']));
        $this->assertSame([1], array_map('intval', $tld['years_available']['renew']));
        $this->assertNull($tld['grace_period']['price']);
        $this->assertTrue($tld['grace_period']['not_configured']);
        $this->assertSame('80.00', $tld['redemption']['price']);
        $this->assertArrayNotHasKey('not_configured', $tld['redemption']);
    }
}
