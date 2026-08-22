<?php
// src/Mcp/FileElementCache.php
declare(strict_types=1);

namespace NtMcp\Mcp;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 mínimo, de arquivo único, para o cache de discovery do SDK.
 *
 * O SDK guarda ali um `DiscoveryState` (as 86 tools refletidas dos atributos)
 * — estado derivado do código, idempotente de reconstruir. Por isso NÃO há
 * lock: escrita é atômica (tmp + rename) e uma corrida entre dois cold starts
 * só custa um scan duplicado. O arquivo vive em data/cache (0700, negado pelo
 * .htaccess) e é invalidado em nt_mcp_upgrade().
 *
 * `unserialize()` roda sobre um arquivo que só este processo escreve; mesmo
 * assim o conteúdo é validado como array antes de ser usado.
 */
final class FileElementCache implements CacheInterface
{
    /** @var array<string, array{0: mixed, 1: int|null}>|null */
    private ?array $data = null;

    public function __construct(private readonly string $file)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        if (!isset($this->data[$key])) {
            return $default;
        }
        [$value, $expires] = $this->data[$key];
        if ($expires !== null && $expires < time()) {
            unset($this->data[$key]);
            return $default;
        }
        return $value;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->load();
        $this->data[$key] = [$value, $this->expiry($ttl)];
        return $this->persist();
    }

    public function delete(string $key): bool
    {
        $this->load();
        unset($this->data[$key]);
        return $this->persist();
    }

    public function clear(): bool
    {
        $this->data = [];
        return !is_file($this->file) || @unlink($this->file);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }
        return $out;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $this->load();
        foreach ($values as $key => $value) {
            $this->data[$key] = [$value, $this->expiry($ttl)];
        }
        return $this->persist();
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $this->load();
        foreach ($keys as $key) {
            unset($this->data[$key]);
        }
        return $this->persist();
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    private function expiry(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl)->getTimestamp();
        }
        return time() + $ttl;
    }

    private function load(): void
    {
        if ($this->data !== null) {
            return;
        }
        $this->data = [];
        if (!is_file($this->file)) {
            return;
        }
        $raw = @file_get_contents($this->file);
        if ($raw === false || $raw === '') {
            return;
        }
        $decoded = @unserialize($raw, ['allowed_classes' => true]);
        if (is_array($decoded)) {
            $this->data = $decoded;
        }
    }

    private function persist(): bool
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, serialize($this->data), LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
