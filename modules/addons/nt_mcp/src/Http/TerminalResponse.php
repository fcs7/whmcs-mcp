<?php

declare(strict_types=1);

namespace NtMcp\Http;

/**
 * Resposta HTTP terminal criada somente por factories fechadas do addon.
 *
 * Guards devolvem este envelope em vez de escrever e encerrar o processo. O
 * endpoint MCP pode então selar status, headers e body atomicamente; o endpoint
 * OAuth ainda pode emiti-lo diretamente sem aceitar conteúdo arbitrário.
 */
final readonly class TerminalResponse
{
    /** @param array<string, string> $headers */
    private function __construct(
        private int $status,
        private string $body,
        private array $headers,
    ) {}

    public static function tlsRequired(): self
    {
        return self::json(421, ['error' => 'TLS required. Plain HTTP requests are rejected for security.']);
    }

    public static function preflight(): self
    {
        return new self(204, '', []);
    }

    public static function corsForbidden(): self
    {
        return self::json(403, ['error' => 'Forbidden: origin not allowed.']);
    }

    public static function serviceUnavailable(): self
    {
        return self::json(503, ['error' => 'Service temporarily unavailable.']);
    }

    public static function clientIpUnavailable(): self
    {
        return self::json(403, ['error' => 'Forbidden: unable to determine client IP.']);
    }

    public static function ipForbidden(): self
    {
        return self::json(403, ['error' => 'Forbidden: IP address not in allowlist.']);
    }

    public static function unauthorized(string $resourceMetadataUrl = ''): self
    {
        $challenge = 'Bearer realm="WHMCS MCP"';
        if (self::isSafeAbsoluteUrl($resourceMetadataUrl)) {
            $challenge = 'Bearer resource_metadata="' . $resourceMetadataUrl . '"';
        }

        return self::json(401, ['error' => 'Unauthorized'], [
            'WWW-Authenticate' => $challenge,
        ]);
    }

    public static function rateLimited(int $retryAfter, ?string $description = null): self
    {
        $retryAfter = max(1, min(86400, $retryAfter));
        $knownDescriptions = [
            'Too many token requests. Maximum 30 per minute.',
            'Too many authorization requests. Maximum 20 per minute.',
            'Too many client registrations. Maximum 20 per hour.',
        ];

        $payload = $description !== null && in_array($description, $knownDescriptions, true)
            ? ['error' => 'rate_limit_exceeded', 'error_description' => $description]
            : ['error' => 'Rate limit exceeded. Try again later.'];

        return self::json(429, $payload, ['Retry-After' => (string) $retryAfter]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Emissão direta para endpoints legados que não possuem árbitro raiz. */
    public function emit(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        header('Content-Length: ' . strlen($this->body), true);
        if ($this->body !== '') {
            echo $this->body;
        }
    }

    /** @param array<string, scalar> $payload @param array<string, string> $headers */
    private static function json(int $status, array $payload, array $headers = []): self
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new \LogicException('Closed HTTP response could not be encoded.');
        }

        return new self($status, $body, ['Content-Type' => 'application/json'] + $headers);
    }

    private static function isSafeAbsoluteUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\r\n"]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && isset($parts['host']);
    }
}
