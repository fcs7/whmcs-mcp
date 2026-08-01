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
            bootstrap: "<?php trigger_error(" . var_export($poison, true) . ", E_USER_WARNING); "
                . "throw new \\RuntimeException(" . var_export($poison, true) . ");"
        );
        $result = $this->runEndpoint($root);
        $all = $result['stdout'] . "\n" . $result['stderr'] . "\n" . $result['log'];

        $this->assertStringContainsString('Internal server error. Correlation id:', $result['stdout']);
        $this->assertMatchesRegularExpression('/Correlation id: [0-9a-f]{8}/', $result['stdout']);
        $this->assertStringContainsString('category=unhandled_exception', $result['log']);
        $this->assertStringContainsString('category=runtime_failure', $result['log']);
        $this->assertStringContainsString('exception=RuntimeException', $result['log']);
        $this->assertStringNotContainsString($poison, $all);
        $this->assertStringNotContainsString('/var/www/configuration.php', $all);
        $this->assertStringNotContainsString('Stack trace', $all);
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
            foreach ($tokens as $token) {
                if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'error_log') {
                    $writers[] = realpath($path);
                }
            }
        }

        $this->assertSame([$allowed], array_values(array_unique($writers)));
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

    private function sandbox(bool $withAutoload, string $bootstrap): string
    {
        $root = sys_get_temp_dir() . '/nt_mcp_bootstrap_' . bin2hex(random_bytes(6));
        $module = $root . '/modules/addons/nt_mcp';
        mkdir($module . '/vendor', 0700, true);
        copy(dirname(__DIR__, 2) . '/mcp.php', $module . '/mcp.php');

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
