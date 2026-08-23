<?php

declare(strict_types=1);

namespace NtMcp\Tests\Mcp;

use NtMcp\Mcp\FileElementCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileElementCacheTest extends TestCase
{
    private string $tempDir;
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cache-test-' . bin2hex(random_bytes(8));
        @mkdir($this->tempDir, 0700, true);
        $this->cacheFile = $this->tempDir . '/test.cache';
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile);
        @rmdir($this->tempDir);
    }

    #[Test]
    public function get_returns_default_for_missing_key(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $this->assertNull($cache->get('missing'));
        $this->assertSame('default', $cache->get('missing', 'default'));
    }

    #[Test]
    public function set_and_get_stores_and_retrieves_value(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $cache->set('key', 'value');

        $this->assertSame('value', $cache->get('key'));
    }

    #[Test]
    public function has_returns_true_for_existing_key(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $cache->set('key', 'value');

        $this->assertTrue($cache->has('key'));
    }

    #[Test]
    public function has_returns_false_for_missing_key(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $this->assertFalse($cache->has('missing'));
    }

    #[Test]
    public function delete_removes_key(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $cache->set('key', 'value');
        $this->assertTrue($cache->has('key'));

        $cache->delete('key');

        $this->assertFalse($cache->has('key'));
    }

    #[Test]
    public function clear_removes_all_keys(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');

        $cache->clear();

        $this->assertFalse($cache->has('key1'));
        $this->assertFalse($cache->has('key2'));
    }

    #[Test]
    public function get_returns_default_when_ttl_expired(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        // Set with 1-second TTL
        $cache->set('key', 'value', 1);

        // Should be present immediately
        $this->assertSame('value', $cache->get('key'));

        // Wait for expiry
        sleep(2);

        // Should return default after expiry
        $this->assertNull($cache->get('key'));
        $this->assertSame('default', $cache->get('key', 'default'));
    }

    #[Test]
    public function negative_ttl_expires_immediately(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $cache->set('key', 'value', -1);

        $this->assertNull($cache->get('key'));
    }

    #[Test]
    public function date_interval_ttl_works(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $interval = new \DateInterval('PT1S');
        $cache->set('key', 'value', $interval);

        $this->assertSame('value', $cache->get('key'));

        sleep(2);

        $this->assertNull($cache->get('key'));
    }

    #[Test]
    public function get_multiple_returns_all_values(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');

        $result = $cache->getMultiple(['key1', 'key2', 'missing']);

        $arr = iterator_to_array($result);
        $this->assertSame('value1', $arr['key1']);
        $this->assertSame('value2', $arr['key2']);
        $this->assertNull($arr['missing']);
    }

    #[Test]
    public function set_multiple_stores_all_values(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $cache->setMultiple([
            'key1' => 'value1',
            'key2' => 'value2',
        ]);

        $this->assertSame('value1', $cache->get('key1'));
        $this->assertSame('value2', $cache->get('key2'));
    }

    #[Test]
    public function delete_multiple_removes_all_keys(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');
        $cache->set('key3', 'value3');

        $cache->deleteMultiple(['key1', 'key2']);

        $this->assertFalse($cache->has('key1'));
        $this->assertFalse($cache->has('key2'));
        $this->assertTrue($cache->has('key3'));
    }

    #[Test]
    public function persist_is_atomic_no_tmp_file_left(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $cache->set('key', 'value');

        // Check that no .tmp files are left in the directory
        $tmpFiles = glob($this->tempDir . '/*.tmp');
        $this->assertEmpty($tmpFiles, 'Temporary files left after persist');
    }

    #[Test]
    public function unreadable_file_yields_empty_cache(): void
    {
        // Create an unreadable file
        file_put_contents($this->cacheFile, 'garbage', LOCK_EX);
        chmod($this->cacheFile, 0000);

        $cache = new FileElementCache($this->cacheFile);

        // Should not throw, returns default
        $this->assertNull($cache->get('key'));

        // Cleanup
        chmod($this->cacheFile, 0600);
    }

    #[Test]
    public function garbage_file_yields_empty_cache(): void
    {
        file_put_contents($this->cacheFile, 'not_serialized_data');

        $cache = new FileElementCache($this->cacheFile);

        // Should not throw, returns empty cache
        $this->assertNull($cache->get('key'));
    }

    #[Test]
    public function objects_round_trip_via_serialize(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        // Use stdClass instead of anonymous class for serializability
        $obj = (object) ['prop' => 'value'];

        $cache->set('obj', $obj);

        $retrieved = $cache->get('obj');
        $this->assertIsObject($retrieved);
        $this->assertSame('value', $retrieved->prop);
    }

    #[Test]
    public function complex_nested_data_round_trips(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $data = [
            'string' => 'value',
            'number' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
            'array' => [1, 2, 3],
            'nested' => [
                'key' => 'value',
                'list' => [4, 5, 6],
            ],
        ];

        $cache->set('complex', $data);

        $retrieved = $cache->get('complex');
        $this->assertSame($data, $retrieved);
    }

    #[Test]
    public function multiple_operations_persist_correctly(): void
    {
        $cache1 = new FileElementCache($this->cacheFile);

        $cache1->set('key1', 'value1');
        $cache1->set('key2', 'value2');
        $cache1->delete('key1');

        // New instance reads from disk
        $cache2 = new FileElementCache($this->cacheFile);

        $this->assertNull($cache2->get('key1'));
        $this->assertSame('value2', $cache2->get('key2'));
    }

    #[Test]
    public function null_ttl_means_no_expiry(): void
    {
        $cache = new FileElementCache($this->cacheFile);

        $cache->set('key', 'value', null);

        sleep(1);

        // Should still be present with null TTL
        $this->assertSame('value', $cache->get('key'));
    }

    // ------------------------------------------------------------------
    // unserialize() como fronteira hostil (F)
    // ------------------------------------------------------------------

    #[Test]
    public function foreign_object_with_magic_method_is_rejected_before_unserialize(): void
    {
        \NtMcp\Tests\Mcp\WakeupCanary::$woke = false;
        $payload = serialize(['k' => [new \NtMcp\Tests\Mcp\WakeupCanary(), null]]);
        file_put_contents($this->cacheFile, $payload);

        $cache = new FileElementCache($this->cacheFile);
        $this->assertNull($cache->get('k'));
        $this->assertFalse(\NtMcp\Tests\Mcp\WakeupCanary::$woke, '__wakeup de classe fora da allowlist não pode rodar');
        $this->assertFileDoesNotExist($this->cacheFile, 'cache adulterado é removido');
    }

    #[Test]
    public function custom_serializable_payload_is_rejected(): void
    {
        file_put_contents($this->cacheFile, 'a:1:{s:1:"k";a:2:{i:0;C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}i:1;N;}}');
        $cache = new FileElementCache($this->cacheFile);
        $this->assertFalse($cache->has('k'));
        $this->assertFileDoesNotExist($this->cacheFile);
    }

    #[Test]
    public function malformed_envelope_is_rejected(): void
    {
        foreach ([
            serialize(['k' => 'not-an-entry']),
            serialize(['k' => ['only-one']]),
            serialize(['k' => ['v', 'not-int-expiry']]),
            serialize([0 => ['v', null]]),
            serialize('string'),
            'a:1:{s:1:"k";a:2:{i:0;O:9:"Nope\\Gone":0:{}i:1;N;}}',
        ] as $payload) {
            file_put_contents($this->cacheFile, $payload);
            $cache = new FileElementCache($this->cacheFile);
            $this->assertNull($cache->get('k'), $payload);
            $this->assertFileDoesNotExist($this->cacheFile, $payload);
        }
    }

    #[Test]
    public function decode_accepts_exactly_the_discovery_graph(): void
    {
        $this->assertSame(
            [\Mcp\Capability\Discovery\DiscoveryState::class, \Mcp\Capability\Registry\ToolReference::class, \Mcp\Schema\Tool::class, \stdClass::class],
            FileElementCache::ALLOWED_CLASSES
        );
        $this->assertNull(FileElementCache::decode('a:1:{s:1:"k";a:2:{i:0;O:8:"stdClass":0:{}i:1;s:1:"x";}}'));
        $this->assertIsArray(FileElementCache::decode('a:1:{s:1:"k";a:2:{i:0;O:8:"stdClass":0:{}i:1;N;}}'));
    }

    #[Test]
    public function real_discovery_round_trips_through_hardened_cache(): void
    {
        $logger = \NtMcp\Mcp\McpSdkAdapter::compatLogger();
        $cache = new FileElementCache($this->cacheFile);
        $discoverer = new \Mcp\Capability\Discovery\CachedDiscoverer(
            new \Mcp\Capability\Discovery\Discoverer($logger),
            $cache,
            $logger
        );
        $src = realpath(__DIR__ . '/../../src');
        $fresh = $discoverer->discover($src, ['Tools'], []);
        $this->assertCount(64, $fresh->getTools());
        $this->assertFileExists($this->cacheFile);

        // Processo novo: relê do arquivo pela fronteira endurecida.
        $second = new \Mcp\Capability\Discovery\CachedDiscoverer(
            new class implements \Mcp\Capability\Discovery\DiscovererInterface {
                public function discover(string $basePath, array $directories, array $excludeDirs = [], array $namePatterns = []): \Mcp\Capability\Discovery\DiscoveryState
                {
                    throw new \LogicException('cache deveria ter servido');
                }
            },
            new FileElementCache($this->cacheFile),
            $logger
        );
        $cached = $second->discover($src, ['Tools'], []);
        $this->assertCount(64, $cached->getTools());
    }

    #[Test]
    public function cache_file_is_0600_and_directory_0700(): void
    {
        $cache = new FileElementCache($this->cacheFile);
        $this->assertTrue($cache->set('k', 'v'));
        clearstatcache();
        $this->assertSame(0600, fileperms($this->cacheFile) & 0777);
        $this->assertSame(0700, fileperms(dirname($this->cacheFile)) & 0777);
    }
}

final class WakeupCanary
{
    public static bool $woke = false;
    public function __wakeup(): void { self::$woke = true; }

    #[Test]
    public function cyclic_stdclass_is_rejected_without_exhausting_memory(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $a->next = $b;
        $b->next = $a; // ciclo: unserialize() reconstrói sem erro

        $cache = new FileElementCache($this->cacheFile);
        $this->assertTrue($cache->set('k', $a));

        $before = memory_get_usage();
        $fresh = new FileElementCache($this->cacheFile);
        $value = $fresh->get('k');
        $after = memory_get_usage();

        $this->assertNotNull($value, 'stdClass continua permitido — só o ciclo específico é seguro de percorrer');
        $this->assertLessThan(8 * 1024 * 1024, $after - $before, 'varredura de containsIncompleteClass() não pode crescer sem limite num ciclo');
    }

    #[Test]
    public function decode_rejects_a_syntactically_valid_but_semantically_wrong_entry_when_a_type_is_expected(): void
    {
        // Envelope perfeitamente válido: chave string, [valor, expiry|null] —
        // mas o valor é uma string, não a DiscoveryState que este cache real
        // sempre grava. Sem `expectedValueType`, continua aceito (genérico).
        $raw = 'a:1:{s:1:"k";a:2:{i:0;s:16:"not-a-discovery!";i:1;N;}}';
        $this->assertIsArray(FileElementCache::decode($raw));
        $this->assertNull(FileElementCache::decode($raw, \Mcp\Capability\Discovery\DiscoveryState::class));
    }

    #[Test]
    public function corrupted_discovery_cache_self_heals_instead_of_500ing_forever(): void
    {
        // Mesmo cenário acima, mas fim-a-fim: constrói com expectedValueType
        // (como McpSdkAdapter faz) e escreve o payload adulterado direto no
        // arquivo — decode() rejeita, o arquivo é removido, o próximo get()
        // é um miss limpo em vez de devolver uma string onde o SDK espera
        // DiscoveryState (que era o 500 persistente reportado).
        file_put_contents($this->cacheFile, 'a:1:{s:1:"k";a:2:{i:0;s:16:"not-a-discovery!";i:1;N;}}');
        $cache = new FileElementCache($this->cacheFile, \Mcp\Capability\Discovery\DiscoveryState::class);

        $this->assertNull($cache->get('k'));
        $this->assertFileDoesNotExist($this->cacheFile);
    }
}
