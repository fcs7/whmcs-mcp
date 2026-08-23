<?php
// src/Mcp/FileElementCache.php
declare(strict_types=1);

namespace NtMcp\Mcp;

use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Tool;
use NtMcp\Whmcs\Diagnostics;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 mínimo, de arquivo único, para o cache de discovery do SDK.
 *
 * O SDK guarda ali um `DiscoveryState` (as 64 tools refletidas dos atributos)
 * — estado derivado do código, idempotente de reconstruir. Por isso NÃO há
 * lock: escrita é atômica (tmp + rename) e uma corrida entre dois cold starts
 * só custa um scan duplicado. O arquivo vive em data/cache (0700, negado pelo
 * .htaccess) e é invalidado em nt_mcp_upgrade().
 *
 * `unserialize()` é tratado como fronteira hostil mesmo assim: o grafo de
 * classes que a discovery precisa é fechado (`ALLOWED_CLASSES`, medido com a
 * discovery real — DiscoveryState, ToolReference, Tool e stdClass para
 * `properties: {}`). O payload é pré-varrido antes de desserializar: qualquer
 * `O:` fora da lista ou qualquer `C:` rejeita o arquivo inteiro, que é
 * removido e reconstruído. Envelope, shape e expiração também são validados;
 * qualquer Throwable vira cache miss. Tudo fica registrado no Diagnostics —
 * um cache permanentemente rejeitado viraria rediscovery a cada request.
 *
 * Arquivos são criados já com 0600 (fail-closed se o chmod não confirmar);
 * o diretório é mantido em 0700.
 */
final class FileElementCache implements CacheInterface
{
    public const ALLOWED_CLASSES = [
        DiscoveryState::class,
        ToolReference::class,
        Tool::class,
        \stdClass::class,
    ];

    /** @var array<string, array{0: mixed, 1: int|null}>|null */
    private ?array $data = null;

    /**
     * @param string|null $expectedValueType Classe que TODO valor decodificado precisa
     *   satisfazer (`instanceof`). Esta classe é genérica (PSR-16) só nos testes; em
     *   produção há um único chamador — o cache de discovery do SDK, sempre gravando
     *   `DiscoveryState` — e um payload sintaticamente válido mas semanticamente
     *   errado (ex.: uma string na posição do valor) passaria pela allowlist de
     *   classes E pelo envelope, e só explodiria depois, dentro do SDK, como 500
     *   persistente. Passar a classe esperada aqui fecha esse buraco sem acoplar
     *   este arquivo ao SDK.
     */
    public function __construct(
        private readonly string $file,
        private readonly ?string $expectedValueType = null,
    ) {
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

        $decoded = self::decode($raw, $this->expectedValueType);
        if ($decoded === null) {
            $this->reject('element_cache_rejected');
            return;
        }
        $this->data = $decoded;
    }

    /**
     * Desserialização fechada. Retorna null para qualquer payload que não seja
     * exatamente o envelope que `persist()` escreve com o grafo de classes
     * permitido.
     *
     * @return array<string, array{0: mixed, 1: int|null}>|null
     */
    public static function decode(string $raw, ?string $expectedValueType = null): ?array
    {
        // Pré-varredura: nenhum objeto fora da allowlist e nenhum `C:` (classes
        // com Serializable custom) chega ao unserialize().
        if (preg_match('/(?<![A-Za-z0-9_])C:\d+:"/', $raw) === 1) {
            return null;
        }
        if (preg_match_all('/O:\d+:"([^"]*)"/', $raw, $matches) > 0) {
            foreach ($matches[1] as $class) {
                if (!in_array($class, self::ALLOWED_CLASSES, true)) {
                    return null;
                }
            }
        }

        try {
            $decoded = @unserialize($raw, ['allowed_classes' => self::ALLOWED_CLASSES]);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        foreach ($decoded as $key => $entry) {
            if (!is_string($key)
                || !is_array($entry)
                || count($entry) !== 2
                || !array_key_exists(0, $entry)
                || !array_key_exists(1, $entry)
                || !($entry[1] === null || is_int($entry[1]))
                || self::containsIncompleteClass($entry[0])
                || ($expectedValueType !== null && !($entry[0] instanceof $expectedValueType))) {
                return null;
            }
        }

        return $decoded;
    }

    private const MAX_INSPECT_DEPTH = 32;

    /**
     * Além de `__PHP_Incomplete_Class`, esta varredura é a defesa contra um
     * `stdClass` (classe permitida, propriedades dinâmicas) cuja propriedade
     * aponta para si mesmo — `unserialize()` reconstrói o ciclo sem erro, e
     * uma varredura ingênua recursaria até esgotar a memória do processo.
     * `SplObjectStorage` corta ciclos (objeto já visto = não desce de novo);
     * um teto de profundidade cobre estruturas profundas sem ciclo.
     */
    private static function containsIncompleteClass(
        mixed $value,
        int $depth = 0,
        ?\SplObjectStorage $seen = null,
    ): bool {
        if ($depth > self::MAX_INSPECT_DEPTH) {
            return true;
        }
        if ($value instanceof \__PHP_Incomplete_Class) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsIncompleteClass($item, $depth + 1, $seen)) {
                    return true;
                }
            }
        } elseif (is_object($value)) {
            $seen ??= new \SplObjectStorage();
            if ($seen->offsetExists($value)) {
                return false;
            }
            $seen->offsetSet($value);
            foreach ((array) $value as $item) {
                if (self::containsIncompleteClass($item, $depth + 1, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function reject(string $context): void
    {
        $this->data = [];
        @unlink($this->file);
        try {
            Diagnostics::event(Diagnostics::CATEGORY_RUNTIME, $context); // detail fechado: nome de arquivo nunca entra no log
        } catch (\Throwable) {
            // diagnóstico nunca pode impedir a reconstrução do cache
        }
    }

    private function persist(): bool
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        if ((@fileperms($dir) & 0777) !== 0700 && !@chmod($dir, 0700)) {
            return false;
        }

        $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $handle = @fopen($tmp, 'x');
        if ($handle === false) {
            return false;
        }
        // Modo fechado ANTES de qualquer byte de conteúdo; verificado, não assumido.
        if (!@chmod($tmp, 0600) || (@fileperms($tmp) & 0777) !== 0600) {
            fclose($handle);
            @unlink($tmp);
            return false;
        }
        $payload = serialize($this->data);
        $written = fwrite($handle, $payload);
        fflush($handle);
        fclose($handle);
        if ($written !== strlen($payload)) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
