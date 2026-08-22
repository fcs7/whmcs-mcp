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
}
