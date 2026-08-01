<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Probes mecânicos da fronteira de bootstrap e do único writer operacional. */
final class DiagnosticBoundaryTest extends TestCase
{
    private const FIXED_FAILURE_JSON = '{"jsonrpc":"2.0","error":{"code":-32603,"message":"Internal server error."},"id":null}';

    /** @var list<string> */
    private array $sandboxes = [];

    protected function tearDown(): void
    {
        foreach ($this->sandboxes as $root) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($root);
        }
    }

    public function test_autoload_failure_with_display_errors_enabled_has_controlled_response_and_no_raw_log(): void
    {
        $root = $this->sandbox(withAutoload: false, bootstrap: '');
        $result = $this->runEndpoint($root);

        $this->assertNotSame(0, $result['exit']);
        $this->assertSame(self::FIXED_FAILURE_JSON, $result['stdout']);
        $this->assertStringNotContainsString('vendor/autoload.php', $result['stdout'] . $result['stderr'] . $result['log']);
        $this->assertStringNotContainsString($root, $result['stdout'] . $result['stderr'] . $result['log']);
        $this->assertStringNotContainsString('Fatal error', $result['stdout'] . $result['stderr'] . $result['log']);
    }

    public function test_exception_inside_init_uses_structured_handler_without_message_or_stack(): void
    {
        $poison = 'SQLSTATE[HY000] password=hunter2 token=tok_secret /var/www/configuration.php';
        $root = $this->sandbox(
            withAutoload: true,
            bootstrap: "<?php throw new \\RuntimeException(" . var_export($poison, true) . ");"
        );
        $result = $this->runEndpoint($root);
        $all = $result['stdout'] . "\n" . $result['stderr'] . "\n" . $result['log'];

        $this->assertSame(self::FIXED_FAILURE_JSON, $result['stdout']);
        $this->assertMatchesRegularExpression('/\[corr:[0-9a-f]{8}\]/', $result['log']);
        $this->assertStringContainsString('category=unhandled_exception', $result['log']);
        $this->assertStringContainsString('exception=RuntimeException', $result['log']);
        $this->assertStringNotContainsString($poison, $all);
        $this->assertStringNotContainsString('/var/www/configuration.php', $all);
        $this->assertStringNotContainsString('Stack trace', $all);
    }

    public function test_warning_after_partial_output_becomes_one_controlled_json_response(): void
    {
        $poison = 'warning poison tok_secret /var/www/configuration.php';
        $root = $this->sandbox(
            withAutoload: true,
            bootstrap: '<?php echo "partial-warning-output"; trigger_error('
                . var_export($poison, true) . ', E_USER_WARNING); echo "must-not-run";'
        );
        $result = $this->runEndpoint($root);
        $all = $result['stdout'] . $result['stderr'] . $result['log'];

        $decoded = json_decode($result['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertSame(-32603, $decoded['error']['code'] ?? null);
        $this->assertStringContainsString('category=runtime_failure', $result['log']);
        $this->assertStringNotContainsString('partial-warning-output', $result['stdout']);
        $this->assertStringNotContainsString('must-not-run', $result['stdout']);
        $this->assertStringNotContainsString($poison, $all);
        $this->assertSame(1, substr_count($result['stdout'], '"jsonrpc"'));
    }

    public function test_partial_output_then_throw_is_replaced_by_one_json_response(): void
    {
        $poison = 'partial-bootstrap-output tok_secret';
        $root = $this->sandbox(
            withAutoload: true,
            bootstrap: '<?php while (ob_get_level() > 0) { if (!@ob_end_flush()) break; } echo '
                . var_export($poison, true) . '; throw new \\RuntimeException("boom");'
        );
        $result = $this->runEndpoint($root);

        $decoded = json_decode($result['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertSame(-32603, $decoded['error']['code'] ?? null);
        $this->assertStringNotContainsString($poison, $result['stdout']);
        $this->assertSame(1, substr_count($result['stdout'], '"jsonrpc"'));
    }

    public function test_partial_output_then_fatal_is_replaced_by_one_fixed_json_response(): void
    {
        $poison = 'partial-before-fatal tok_secret';
        $root = $this->sandbox(
            withAutoload: true,
            bootstrap: '<?php while (ob_get_level() > 0) { if (!@ob_end_flush()) break; } echo '
                . var_export($poison, true) . '; restore_error_handler(); '
                . 'trigger_error("fatal-secret", E_USER_ERROR);'
        );
        $result = $this->runEndpoint($root);

        $decoded = json_decode($result['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertSame(-32603, $decoded['error']['code'] ?? null);
        $this->assertStringNotContainsString($poison, $result['stdout']);
        $this->assertSame(1, substr_count($result['stdout'], '"jsonrpc"'));
    }

    public function test_init_output_is_discarded_but_application_output_is_preserved(): void
    {
        $root = $this->sandbox(
            withAutoload: true,
            bootstrap: '<?php ob_start(static fn (string $buffer): string => "transformed:" . $buffer); '
                . 'echo "bootstrap-noise"; set_error_handler(static fn (): bool => true);',
            afterReinstall: 'echo \'{"transport":"ok"}\'; $ntMcpResponseState = \'success\'; exit;'
        );
        $result = $this->runEndpoint($root);

        $this->assertSame('{"transport":"ok"}', $result['stdout']);
        $this->assertStringNotContainsString('bootstrap-noise', $result['stdout']);
        $this->assertStringNotContainsString('transformed:', $result['stdout']);
        $this->assertSame('', $result['stderr']);
        $this->assertFalse($result['timed_out']);
    }

    #[DataProvider('normalTerminationProvider')]
    public function test_exit_and_die_replace_bootstrap_status_headers_and_body(string $termination): void
    {
        $root = $this->sandbox(
            withAutoload: true,
            bootstrap: '<?php http_response_code(418); header("Content-Type: text/html"); '
                . 'header("X-Bootstrap-Secret: present"); echo "bootstrap-secret<html>"; ' . $termination . ';'
        );
        $response = $this->runEndpointOverHttp($root);

        $this->assertSame(500, $response['status']);
        $this->assertSame('application/json', $response['headers']['content-type'] ?? null);
        $this->assertArrayNotHasKey('x-bootstrap-secret', $response['headers']);
        $this->assertSame(self::FIXED_FAILURE_JSON, $response['body']);
        $this->assertStringNotContainsString('bootstrap-secret', $response['body']);
        $this->assertStringNotContainsString('<html>', $response['body']);
    }

    public static function normalTerminationProvider(): array
    {
        return [
            'exit' => ['exit'],
            'die' => ['die'],
        ];
    }

    public function test_handler_replaced_by_init_is_reinstalled_before_application_code(): void
    {
        $root = $this->sandbox(
            withAutoload: true,
            bootstrap: '<?php set_exception_handler(static function (): void { echo "host-handler-leak"; });',
            afterReinstall: 'echo "partial-app-output"; throw new \\RuntimeException("application poison");'
        );
        $result = $this->runEndpoint($root);

        $decoded = json_decode($result['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertSame(-32603, $decoded['error']['code'] ?? null);
        $this->assertStringNotContainsString('host-handler-leak', $result['stdout']);
        $this->assertStringNotContainsString('partial-app-output', $result['stdout']);
        $this->assertStringNotContainsString('application poison', $result['stdout'] . $result['log']);
    }

    #[DataProvider('childBufferOutcomeProvider')]
    public function test_root_buffer_arbitrates_every_child_buffer_and_termination(
        string $bufferSetup,
        string $outcome,
        bool $expectSuccess,
    ): void {
        $bootstrap = '<?php ' . $bufferSetup . ' echo "child-secret"; ';
        $afterReinstall = '';

        if ($outcome === 'success') {
            $afterReinstall = 'echo \'{"transport":"ok"}\'; $ntMcpResponseState = \'success\'; exit;';
        } elseif ($outcome === 'throwable') {
            $bootstrap .= 'throw new \\RuntimeException("throw-secret");';
        } elseif ($outcome === 'warning') {
            $bootstrap .= 'trigger_error("warning-secret", E_USER_WARNING);';
        } elseif ($outcome === 'fatal') {
            $bootstrap .= 'restore_error_handler(); trigger_error("fatal-secret", E_USER_ERROR);';
        } else {
            $bootstrap .= 'exit;';
        }

        $root = $this->sandbox(withAutoload: true, bootstrap: $bootstrap, afterReinstall: $afterReinstall);
        $result = $this->runEndpoint($root);

        $this->assertFalse($result['timed_out'], 'unwind de buffer entrou em loop sem progresso');
        if ($expectSuccess) {
            $this->assertSame('{"transport":"ok"}', $result['stdout']);
        } else {
            $this->assertSame(self::FIXED_FAILURE_JSON, $result['stdout']);
            $this->assertIsArray(json_decode($result['stdout'], true));
        }
        $this->assertStringNotContainsString('child-secret', $result['stdout']);
        $this->assertStringNotContainsString('child-transform', $result['stdout']);
        $this->assertSame(1, substr_count($result['stdout'], '"jsonrpc"') + substr_count($result['stdout'], '"transport"'));
    }

    public static function childBufferOutcomeProvider(): array
    {
        $buffers = [
            'removable' => [
                'ob_start(static fn (string $buffer): string => "child-transform:" . $buffer);',
                true,
            ],
            'non-removable-cleanable' => [
                'ob_start(static fn (string $buffer): string => "child-transform:" . $buffer, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);',
                false,
            ],
            'non-removable-non-cleanable' => [
                'ob_start(static fn (string $buffer): string => "child-transform:" . $buffer, 0, 0);',
                false,
            ],
        ];
        $cases = [];

        foreach ($buffers as $bufferName => [$setup, $canSucceed]) {
            foreach (['success', 'throwable', 'warning', 'fatal', 'exit'] as $outcome) {
                $cases["{$bufferName}-{$outcome}"] = [$setup, $outcome, $canSucceed && $outcome === 'success'];
            }
        }

        return $cases;
    }

    public function test_diagnostics_is_the_only_error_log_writer_in_production_php(): void
    {
        $module = dirname(__DIR__, 2);
        $allowed = realpath($module . '/src/Whmcs/Diagnostics.php');
        $writers = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($module, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($path, '/tests/') || str_contains($path, '/vendor/')) {
                continue;
            }

            $tokens = token_get_all((string) file_get_contents($path));
            $source = (string) file_get_contents($path);
            if (preg_match('/->\s*(?:debug|info|notice|warning|error|critical|alert|emergency)\s*\(/i', $source) === 1) {
                $alternateSinks[] = realpath($path);
            }
            if (preg_match('/\bSTDERR\b|php:\/\/stderr/i', $source) === 1) {
                $alternateSinks[] = realpath($path);
            }
            foreach ($tokens as $token) {
                if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'error_log') {
                    $writers[] = realpath($path);
                }
            }
        }

        $this->assertSame([$allowed], array_values(array_unique($writers)));
    }

    public function test_activity_log_has_one_closed_writer_and_no_alternate_production_sink(): void
    {
        $module = dirname(__DIR__, 2);
        $allowed = realpath($module . '/src/Whmcs/ActivityLog.php');
        $writers = [];
        $alternateSinks = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($module, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($path, '/tests/') || str_contains($path, '/vendor/')) {
                continue;
            }

            $tokens = token_get_all((string) file_get_contents($path));
            foreach ($tokens as $token) {
                if (!is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }
                if (strtolower($token[1]) === 'logactivity') {
                    $writers[] = realpath($path);
                }
                if (in_array(strtolower($token[1]), ['logmodulecall', 'syslog', 'trigger_error'], true)) {
                    $alternateSinks[] = realpath($path);
                }
            }
        }

        $this->assertSame([$allowed], array_values(array_unique($writers)));
        $this->assertSame([], array_values(array_unique($alternateSinks)));
    }

    public function test_operational_events_do_not_receive_raw_ip_or_path_variables(): void
    {
        $module = dirname(__DIR__, 2);
        $offenders = [];
        foreach (['src/Http/TlsEnforcer.php', 'src/Security/RateLimiter.php', 'src/Server.php'] as $relative) {
            $source = (string) file_get_contents($module . '/' . $relative);
            if (preg_match('/Diagnostics::event\((?:(?!\);).)*\$(?:remoteAddr|clientIp|rateFile|lockFile|dataDir)/s', $source) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders);
    }

    private function sandbox(bool $withAutoload, string $bootstrap, string $afterReinstall = ''): string
    {
        $root = sys_get_temp_dir() . '/nt_mcp_bootstrap_' . bin2hex(random_bytes(6));
        $module = $root . '/modules/addons/nt_mcp';
        mkdir($module . '/vendor', 0700, true);
        $endpoint = (string) file_get_contents(dirname(__DIR__, 2) . '/mcp.php');
        if ($afterReinstall !== '') {
            $needle = "\\NtMcp\\Whmcs\\Diagnostics::installExceptionHandler('mcp_endpoint', \$ntMcpMarkFailure);";
            $position = strrpos($endpoint, $needle);
            $this->assertNotFalse($position);
            $position += strlen($needle);
            $endpoint = substr($endpoint, 0, $position) . "\n" . $afterReinstall . substr($endpoint, $position);
        }
        file_put_contents($module . '/mcp.php', $endpoint);

        if ($withAutoload) {
            $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
            file_put_contents($module . '/vendor/autoload.php', '<?php require ' . var_export($autoload, true) . ';');
            file_put_contents($root . '/init.php', $bootstrap);
        }

        $this->sandboxes[] = $root;

        return $root;
    }

    /** @return array{stdout:string,stderr:string,log:string,exit:int,timed_out:bool} */
    private function runEndpoint(string $root): array
    {
        $endpoint = $root . '/modules/addons/nt_mcp/mcp.php';
        $log = $root . '/php-error.log';
        $command = [
            PHP_BINARY,
            '-d', 'display_errors=1',
            '-d', 'display_startup_errors=1',
            '-d', 'log_errors=1',
            '-d', 'error_log=' . $log,
            $endpoint,
        ];

        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = microtime(true) + 3.0;
        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(10000);
        } while (true);
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'log' => is_file($log) ? (string) file_get_contents($log) : '',
            'exit' => $exit,
            'timed_out' => $timedOut,
        ];
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function runEndpointOverHttp(string $root): array
    {
        $reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($reservation, "porta local indisponível: {$errno} {$error}");
        $address = (string) stream_socket_get_name($reservation, false);
        fclose($reservation);
        $port = (int) substr(strrchr($address, ':'), 1);

        $log = $root . '/php-http-error.log';
        $command = [
            PHP_BINARY,
            '-d', 'display_errors=1',
            '-d', 'display_startup_errors=1',
            '-d', 'log_errors=1',
            '-d', 'error_log=' . $log,
            '-S', "127.0.0.1:{$port}",
            '-t', $root,
        ];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        $client = false;
        $deadline = microtime(true) + 3.0;
        while ($client === false && microtime(true) < $deadline) {
            $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $connectErrno, $connectError, 0.1);
            if ($client === false) {
                usleep(20000);
            }
        }
        $this->assertIsResource($client, "servidor HTTP não iniciou: {$connectErrno} {$connectError}");

        fwrite(
            $client,
            "GET /modules/addons/nt_mcp/mcp.php HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n"
        );
        stream_set_timeout($client, 3);
        $raw = (string) stream_get_contents($client);
        fclose($client);

        proc_terminate($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);

        [$headerBlock, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $headerBlock);
        $statusLine = array_shift($lines) ?? '';
        preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $statusLine, $match);
        $headers = [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [
            'status' => isset($match[1]) ? (int) $match[1] : 0,
            'headers' => $headers,
            'body' => $body,
        ];
    }
}
