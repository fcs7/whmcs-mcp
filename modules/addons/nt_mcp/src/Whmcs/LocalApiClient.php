<?php
// src/Whmcs/LocalApiClient.php
namespace NtMcp\Whmcs;

class LocalApiClient
{
    /** @var callable|null Para injecao em testes */
    private $callable = null;

    public function __construct(private readonly string $adminUser = 'admin') {}

    public function setCallable(callable $fn): void
    {
        $this->callable = $fn;
    }

    public function call(string $command, array $params = []): array
    {
        if ($this->callable !== null) {
            $result = ($this->callable)($command, $params);
        } else {
            $result = localAPI($command, $params, $this->adminUser);
        }

        if (($result['result'] ?? '') === 'error') {
            throw new \RuntimeException($result['message'] ?? "WHMCS API error: {$command}");
        }

        return $result;
    }
}
