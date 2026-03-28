<?php
// src/Auth/BearerAuth.php
namespace NtMcp\Auth;

class BearerAuth
{
    public function __construct(private readonly string $expectedToken) {}

    public function isValid(): bool
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return false;
        }
        $token = substr($header, 7);
        return hash_equals($this->expectedToken, $token);
    }

    public static function denyAndExit(): never
    {
        http_response_code(401);
        header('WWW-Authenticate: Bearer realm="WHMCS MCP"');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}
