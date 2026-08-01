<?php

declare(strict_types=1);

namespace NtMcp\Tests\Whmcs;

use PHPUnit\Framework\TestCase;

/** Probes mecânicos da fronteira de bootstrap e do único writer operacional. */
final class DiagnosticBoundaryTest extends TestCase
{
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
        $this->assertStringContainsString('Internal server error during bootstrap', $result['stdout']);
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

        $this->assertStringContainsString('Internal server error. Correlation id:', $result['stdout']);
        $this->assertMatchesRegularExpression('/Correlation id: [0-9a-f]{8}/', $result['stdout']);
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
                . var_export($poison, true) . '; undefined_nt_mcp_function();'
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
            bootstrap: '<?php echo "bootstrap-noise";',
            afterReinstall: 'echo \'{"transport":"ok"}\'; exit;'
        );
        $result = $this->runEndpoint($root);

        $this->assertSame('{"transport":"ok"}', $result['stdout']);
        $this->assertStringNotContainsString('bootstrap-noise', $result['stdout']);
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
            $needle = "\\NtMcp\\Whmcs\\Diagnostics::installExceptionHandler('mcp_endpoint', \$ntMcpOwnedBufferLevel, \$ntMcpRelease);";
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

    /** @return array{stdout:string,stderr:string,log:string,exit:int} */
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
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'log' => is_file($log) ? (string) file_get_contents($log) : '',
            'exit' => $exit,
        ];
    }
}
