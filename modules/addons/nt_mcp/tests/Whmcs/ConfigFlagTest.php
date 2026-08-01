<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\CapsuleClient;
use NtMcp\Whmcs\ConfigFlag;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M3 — o master read-only não pode degradar para "desligado" quando a config
 * carrega um valor não canônico. Cobre o parser tri-state isolado e, com o stub
 * REAL de \WHMCS\Config\Setting, as duas rotas de mutação: LocalApiClient e
 * CapsuleClient.
 */
class ConfigFlagTest extends TestCase
{
    protected function setUp(): void
    {
        \WHMCS\Config\Setting::reset();
    }

    protected function tearDown(): void
    {
        \WHMCS\Config\Setting::reset();
    }

    // ---------------------------------------------------------------
    // Parser isolado
    // ---------------------------------------------------------------

    #[DataProvider('canonicalOnProvider')]
    public function test_canonical_on_values_parse_as_on(mixed $raw): void
    {
        $this->assertSame(ConfigFlag::On, ConfigFlag::parse($raw));
    }

    public static function canonicalOnProvider(): array
    {
        return [[true], [1], ['1'], [' 1 ']];
    }

    #[DataProvider('canonicalOffProvider')]
    public function test_canonical_off_values_parse_as_off(mixed $raw): void
    {
        $this->assertSame(ConfigFlag::Off, ConfigFlag::parse($raw));
    }

    public static function canonicalOffProvider(): array
    {
        return [[false], [0], ['0'], [' 0 ']];
    }

    #[DataProvider('absentProvider')]
    public function test_absent_values_parse_as_absent(mixed $raw): void
    {
        $this->assertSame(ConfigFlag::Absent, ConfigFlag::parse($raw));
    }

    public static function absentProvider(): array
    {
        return [[null], [''], ['   ']];
    }

    /** Exatamente os valores que a revisão reproduziu como bypass. */
    #[DataProvider('invalidProvider')]
    public function test_non_canonical_present_values_parse_as_invalid(mixed $raw): void
    {
        $this->assertSame(ConfigFlag::Invalid, ConfigFlag::parse($raw));
    }

    public static function invalidProvider(): array
    {
        return [
            'true'    => ['true'],
            'yes'     => ['yes'],
            'on'      => ['on'],
            'garbage' => ['garbage'],
            'int 2'   => [2],
            'int -1'  => [-1],
            'float'   => [1.0],
            'array'   => [[1]],
        ];
    }

    public function test_invalid_resolves_to_fail_closed_value_and_audits(): void
    {
        $logged = [];
        $result = ConfigFlag::parse('garbage')->resolve(
            default: false,
            failClosed: true,
            key: 'nt_mcp_readonly',
            auditor: function (string $m) use (&$logged) { $logged[] = $m; },
        );

        $this->assertTrue($result);
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('nt_mcp_readonly', $logged[0]);
        $this->assertStringContainsString('não reconhecido', $logged[0]);
    }

    public function test_absent_resolves_to_default_without_auditing(): void
    {
        $logged = [];
        $result = ConfigFlag::parse(null)->resolve(
            default: false,
            failClosed: true,
            key: 'nt_mcp_readonly',
            auditor: function (string $m) use (&$logged) { $logged[] = $m; },
        );

        $this->assertFalse($result);
        $this->assertSame([], $logged);
    }

    // ---------------------------------------------------------------
    // LocalApiClient — rota LocalAPI
    // ---------------------------------------------------------------

    /**
     * Cenário exato da revisão: write habilitado, readonly com lixo dentro.
     * Antes do fixup, AddClient passava.
     */
    #[DataProvider('invalidReadonlyProvider')]
    public function test_local_api_blocks_mutation_when_readonly_value_is_invalid(mixed $raw): void
    {
        \WHMCS\Config\Setting::$store = [
            'nt_mcp_enable_write' => '1',
            'nt_mcp_readonly' => $raw,
        ];

        $called = false;
        $client = new LocalApiClient('testadmin');
        $client->setCallable(function () use (&$called) {
            $called = true;
            return ['result' => 'success'];
        });

        try {
            $client->call('AddClient', ['firstname' => 'a', 'noemail' => true]);
            $this->fail('readonly inválido deveria bloquear a mutação');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('blocked', $e->getMessage());
        }

        $this->assertFalse($called, 'nada pode chegar à localAPI');
    }

    public static function invalidReadonlyProvider(): array
    {
        return [['true'], ['yes'], ['garbage'], [2]];
    }

    public function test_local_api_allows_mutation_when_readonly_is_canonically_off(): void
    {
        \WHMCS\Config\Setting::$store = [
            'nt_mcp_enable_write' => '1',
            'nt_mcp_readonly' => '0',
        ];

        $client = new LocalApiClient('testadmin');
        $client->setCallable(fn() => ['result' => 'success']);

        $result = $client->call('AddClient', ['firstname' => 'a', 'noemail' => true]);

        $this->assertSame('success', $result['result']);
    }

    public function test_local_api_blocks_when_readonly_absent_but_write_gate_value_is_invalid(): void
    {
        // Gate com lixo também falha fechado — mas como gate desligado.
        \WHMCS\Config\Setting::$store = ['nt_mcp_enable_write' => 'yes'];

        $client = new LocalApiClient('testadmin');
        $client->setCallable(fn() => ['result' => 'success']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('class WRITE disabled');
        $client->call('AddClient', ['firstname' => 'a', 'noemail' => true]);
    }

    // ---------------------------------------------------------------
    // CapsuleClient — rota CRM direta
    // ---------------------------------------------------------------

    #[DataProvider('invalidReadonlyProvider')]
    public function test_capsule_blocks_write_when_readonly_value_is_invalid(mixed $raw): void
    {
        \WHMCS\Config\Setting::$store = [
            'nt_mcp_enable_write' => '1',
            'nt_mcp_readonly' => $raw,
        ];

        $capsule = new CapsuleClient();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('writes disabled');
        $capsule->insert('mod_mgcrm_contacts', ['name' => 'x']);
    }

    public function test_capsule_blocks_write_when_write_gate_value_is_invalid(): void
    {
        \WHMCS\Config\Setting::$store = ['nt_mcp_enable_write' => 'true'];

        $capsule = new CapsuleClient();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('writes disabled');
        $capsule->insert('mod_mgcrm_contacts', ['name' => 'x']);
    }

    public function test_capsule_blocks_write_when_config_read_fails(): void
    {
        \WHMCS\Config\Setting::$throwOnRead = true;

        $capsule = new CapsuleClient();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('writes disabled');
        $capsule->insert('mod_mgcrm_contacts', ['name' => 'x']);
    }

    public function test_local_api_blocks_when_config_read_fails(): void
    {
        \WHMCS\Config\Setting::$throwOnRead = true;

        $client = new LocalApiClient('testadmin');
        $client->setCallable(fn() => ['result' => 'success']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked');
        $client->call('AddClient', ['firstname' => 'a', 'noemail' => true]);
    }

    /** READ continua liberado mesmo com config corrompida. */
    public function test_read_still_works_when_readonly_value_is_invalid(): void
    {
        \WHMCS\Config\Setting::$store = ['nt_mcp_readonly' => 'garbage'];

        $client = new LocalApiClient('testadmin');
        $client->setCallable(fn() => ['result' => 'success']);

        $this->assertSame('success', $client->call('GetClients', [])['result']);
    }
}
