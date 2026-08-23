<?php

declare(strict_types=1);

namespace NtMcp\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Stream PSR-7 mínimo cujo `__toString()` dispara um callback ANTES de
 * devolver o conteúdo — usado para provar o estado de um lock externo no
 * instante exato em que `Server::emit()` lê o corpo da resposta (`echo
 * (string) $response->getBody()`), não apenas durante `adapter->handle()`.
 */
final class ProbingStream implements StreamInterface
{
    private bool $fired = false;

    public function __construct(
        private readonly string $content,
        private readonly \Closure $onRead,
    ) {
    }

    public function __toString(): string
    {
        if (!$this->fired) {
            $this->fired = true;
            ($this->onRead)();
        }

        return $this->content;
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return strlen($this->content);
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new \RuntimeException('not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        return $this->__toString();
    }

    public function getContents(): string
    {
        return $this->__toString();
    }

    public function getMetadata($key = null)
    {
        return $key === null ? [] : null;
    }
}
