<?php

declare(strict_types=1);

namespace NtMcp\Http;

/** Headers CORS validados e eventual envelope terminal, sem side effects. */
/* PHP 8.1 compat: readonly por propriedade (desenv/prod rodam 8.1) */
final class CorsDecision
{
    /** @param array<string, string> $headers */
    private function __construct(
        private readonly array $headers,
        private readonly ?TerminalResponse $terminal,
    ) {}

    /** @param string[] $exposeHeaders */
    public static function proceed(?string $origin, array $exposeHeaders, string $methods): self
    {
        return new self(self::closedHeaders($origin, $exposeHeaders, $methods), null);
    }

    /** @param string[] $exposeHeaders */
    public static function preflight(?string $origin, array $exposeHeaders, string $methods): self
    {
        return new self(
            self::closedHeaders($origin, $exposeHeaders, $methods),
            TerminalResponse::preflight(),
        );
    }

    public static function terminal(TerminalResponse $terminal): self
    {
        return new self([], $terminal);
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function terminalResponse(): ?TerminalResponse
    {
        return $this->terminal;
    }

    /** Emissão explícita somente pelos endpoints que consomem a decisão. */
    public function emitHeaders(): void
    {
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
    }

    /** @param string[] $exposeHeaders @return array<string, string> */
    private static function closedHeaders(?string $origin, array $exposeHeaders, string $methods): array
    {
        if (!in_array($methods, ['POST, OPTIONS', 'GET, POST, OPTIONS'], true)) {
            throw new \InvalidArgumentException('Unsupported closed CORS method profile.');
        }
        if ($exposeHeaders !== [] && $exposeHeaders !== ['MCP-Session-Id']) {
            throw new \InvalidArgumentException('Unsupported closed CORS expose profile.');
        }

        $headers = [
            'Access-Control-Allow-Methods' => $methods,
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, MCP-Protocol-Version, MCP-Session-Id',
        ];
        if ($origin !== null) {
            if ($origin !== '*' && !self::isSafeOrigin($origin)) {
                throw new \InvalidArgumentException('Invalid CORS origin.');
            }
            $headers['Access-Control-Allow-Origin'] = $origin;
            if ($origin !== '*') {
                $headers['Vary'] = 'Origin';
            }
        }
        if ($exposeHeaders !== []) {
            $headers['Access-Control-Expose-Headers'] = 'MCP-Session-Id';
        }

        return $headers;
    }

    private static function isSafeOrigin(string $origin): bool
    {
        if (preg_match('/[\r\n]/', $origin) === 1) {
            return false;
        }
        $parts = parse_url($origin);

        return is_array($parts)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && isset($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }
}
