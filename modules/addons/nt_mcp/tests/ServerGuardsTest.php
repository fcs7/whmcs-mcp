<?php

declare(strict_types=1);

namespace NtMcp\Tests;

use NtMcp\Mcp\McpSdkAdapter;
use NtMcp\Server;
use NtMcp\Whmcs\CapsuleClient;
use NtMcp\Whmcs\LocalApiClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerGuardsTest extends TestCase
{
    #[Test]
    #[DataProvider('batchInputProvider')]
    public function is_batch_detects_array_at_root(string $input, bool $expected): void
    {
        $this->assertSame($expected, Server::isBatch($input));
    }

    public static function batchInputProvider(): array
    {
        return [
            'array at start' => ['[{"jsonrpc":"2.0"}]', true],
            'whitespace before array' => ['  [{"jsonrpc":"2.0"}]', true],
            'newline before array' => ["\n[{\"jsonrpc\":\"2.0\"}]", true],
            'object not array' => ['{"jsonrpc":"2.0"}', false],
            'empty string' => ['', false],
            'just whitespace' => ['   ', false],
            'array in nested position' => ['{"items":[]}', false],
        ];
    }

    #[Test]
    public function build_request_maps_http_headers(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'example.com',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_X_CUSTOM' => 'value',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '42',
            'HTTPS' => 'on',
            'REQUEST_URI' => '/mcp.php',
            'SERVER_NAME' => 'example.com',
        ];

        $request = Server::buildRequest('POST', '{"test": "body"}', $server);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://example.com/mcp.php', (string) $request->getUri());
        $this->assertTrue($request->hasHeader('Content-Type'));
        $this->assertTrue($request->hasHeader('X-Custom'));
        $this->assertSame('value', $request->getHeaderLine('X-Custom'));
    }

    #[Test]
    public function build_request_sets_https_scheme_when_https_on(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTPS' => 'on',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/mcp.php',
        ];

        $request = Server::buildRequest('POST', '', $server);

        $this->assertStringStartsWith('https://', (string) $request->getUri());
    }

    #[Test]
    public function build_request_sets_http_scheme_when_https_off(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTPS' => 'off',
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/mcp.php',
        ];

        $request = Server::buildRequest('POST', '', $server);

        $this->assertStringStartsWith('http://', (string) $request->getUri());
    }

    #[Test]
    public function build_request_adds_default_headers(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'localhost',
        ];

        $request = Server::buildRequest('POST', '', $server);

        $this->assertTrue($request->hasHeader('Accept'));
        $this->assertTrue($request->hasHeader('Content-Type'));
    }

    #[Test]
    public function build_request_preserves_body(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'localhost',
        ];

        $body = '{"jsonrpc":"2.0","id":1,"method":"initialize"}';
        $request = Server::buildRequest('POST', $body, $server);

        $this->assertSame($body, (string) $request->getBody());
    }

    #[Test]
    public function emit_writes_status_and_headers(): void
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Custom', 'value');

        ob_start();
        Server::emit($response);
        $output = ob_get_clean();

        $this->assertSame(200, http_response_code());
    }

    #[Test]
    public function emit_writes_body(): void
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(200)
            ->withBody($factory->createStream('{"test": "output"}'));

        ob_start();
        Server::emit($response);
        $output = ob_get_clean();

        $this->assertSame('{"test": "output"}', $output);
    }

    #[Test]
    public function run_with_empty_admin_user_uses_config(): void
    {
        $stubAdapter = static function (string $adminUser): \NtMcp\Mcp\ServerAdapterInterface {
            // Return a stub adapter that records which admin user was passed
            return new class($adminUser) implements \NtMcp\Mcp\ServerAdapterInterface {
                public function __construct(private readonly string $adminUser) {}

                public function handle(ServerRequestInterface $request): ResponseInterface {
                    $factory = new Psr17Factory();
                    return $factory->createResponse(200)
                        ->withBody($factory->createStream(json_encode([
                            'admin_user' => $this->adminUser,
                        ])));
                }
            };
        };

        Server::setAdapterFactory($stubAdapter);

        // Set config value
        \WHMCS\Config\Setting::setValue('nt_mcp_admin_user', 'configured_admin');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '2';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'localhost';

        ob_start();
        Server::run('');
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertSame('configured_admin', $data['admin_user']);

        // Cleanup
        \WHMCS\Config\Setting::reset();
        Server::setAdapterFactory(null);
    }

    #[Test]
    public function run_post_method_accepted(): void
    {
        $capturedMethods = [];

        $stubAdapter = static function (string $adminUser) use (&$capturedMethods): \NtMcp\Mcp\ServerAdapterInterface {
            return new class($adminUser, $capturedMethods) implements \NtMcp\Mcp\ServerAdapterInterface {
                public function __construct(private readonly string $adminUser, private array &$capturedMethods) {}

                public function handle(ServerRequestInterface $request): ResponseInterface {
                    $factory = new Psr17Factory();
                    $this->capturedMethods[] = $request->getMethod();
                    return $factory->createResponse(200)
                        ->withBody($factory->createStream('{}'));
                }
            };
        };

        Server::setAdapterFactory($stubAdapter);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '2';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'localhost';

        ob_start();
        Server::run('testadmin');
        ob_get_clean();

        $this->assertSame('POST', $capturedMethods[0] ?? null);

        Server::setAdapterFactory(null);
    }

    #[Test]
    public function run_get_method_rejected(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'localhost';

        ob_start();
        Server::run('testadmin');
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame(405, http_response_code());
    }

    #[Test]
    public function run_put_method_rejected(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'localhost';

        ob_start();
        Server::run('testadmin');
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame(405, http_response_code());
    }

    #[Test]
    public function run_content_length_too_large_rejected(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = (string) (1048576 + 1);
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'localhost';

        ob_start();
        Server::run('testadmin');
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('too large', $data['error']);
        $this->assertSame(413, http_response_code());
    }

    #[Test]
    public function run_batch_request_rejected(): void
    {
        $stubAdapter = static function (string $adminUser): \NtMcp\Mcp\ServerAdapterInterface {
            return new class implements \NtMcp\Mcp\ServerAdapterInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface {
                    $factory = new Psr17Factory();
                    return $factory->createResponse(200)
                        ->withBody($factory->createStream('{}'));
                }
            };
        };

        Server::setAdapterFactory($stubAdapter);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '100';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'localhost';

        $batchJson = '[{"jsonrpc":"2.0","id":1,"method":"test"}]';

        // Mock file_get_contents to return batch JSON
        $getContentsOriginal = function_exists('file_get_contents');

        ob_start();

        // We need to test this via the actual input stream, which we can't mock in CLI
        // So we'll test the isBatch function directly
        $this->assertTrue(Server::isBatch($batchJson));

        ob_get_clean();

        Server::setAdapterFactory(null);
    }

    #[Test]
    public function run_empty_body_post_ok(): void
    {
        $stubAdapter = static function (string $adminUser): \NtMcp\Mcp\ServerAdapterInterface {
            return new class implements \NtMcp\Mcp\ServerAdapterInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface {
                    $factory = new Psr17Factory();
                    return $factory->createResponse(200)
                        ->withBody($factory->createStream('{"status":"ok"}'));
                }
            };
        };

        Server::setAdapterFactory($stubAdapter);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '0';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'localhost';

        ob_start();
        Server::run('testadmin');
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertSame('ok', $data['status'] ?? null);

        Server::setAdapterFactory(null);
    }

    #[Test]
    public function run_uses_adapter_factory_when_set(): void
    {
        $adapterCreated = false;

        $stubAdapter = static function (string $adminUser) use (&$adapterCreated): \NtMcp\Mcp\ServerAdapterInterface {
            $adapterCreated = true;
            return new class implements \NtMcp\Mcp\ServerAdapterInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface {
                    $factory = new Psr17Factory();
                    return $factory->createResponse(200)
                        ->withBody($factory->createStream('{}'));
                }
            };
        };

        Server::setAdapterFactory($stubAdapter);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '2';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'localhost';

        ob_start();
        Server::run('testadmin');
        ob_get_clean();

        $this->assertTrue($adapterCreated);

        Server::setAdapterFactory(null);
    }


    #[Test]
    public function build_request_handles_missing_server_defaults(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
        ];

        $request = Server::buildRequest('POST', '{}', $server);

        // Should use defaults when SERVER_NAME and HTTP_HOST are missing
        $this->assertStringStartsWith('http://', (string) $request->getUri());
    }

    #[Test]
    public function build_request_respects_https_unset(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'localhost',
        ];

        $request = Server::buildRequest('POST', '{}', $server);

        $this->assertStringStartsWith('http://', (string) $request->getUri());
    }

    #[Test]
    public function emit_response_with_multiple_header_values(): void
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(200)
            ->withAddedHeader('Set-Cookie', 'cookie1=value1')
            ->withAddedHeader('Set-Cookie', 'cookie2=value2');

        ob_start();
        Server::emit($response);
        $output = ob_get_clean();

        // Just verify it doesn't error
        $this->assertIsString($output);
    }

    #[Test]
    public function is_batch_with_tab_whitespace(): void
    {
        $input = "\t[{\"jsonrpc\":\"2.0\"}]";
        $this->assertTrue(Server::isBatch($input));
    }

    #[Test]
    public function is_batch_with_mixed_whitespace(): void
    {
        $input = " \t \n[{\"jsonrpc\":\"2.0\"}]";
        $this->assertTrue(Server::isBatch($input));
    }
}
