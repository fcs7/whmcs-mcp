<?php
// src/Server.php
namespace NtMcp;

use NtMcp\Mcp\PhpMcpV1Adapter;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\CapsuleClient;

class Server
{
    private const LOCK_TIMEOUT_SECONDS = 5;
    private const RETRY_AFTER_MIN_SECONDS = 5;
    private const RETRY_AFTER_MAX_SECONDS = 8;

    public static function run(string $adminUser = ''): void
    {
        // ------------------------------------------------------------------
        // Admin user resolution: prefer per-token admin from authenticate(),
        // fall back to global config. SECURITY (WO-7 consistency): if none is
        // resolvable, fail CLOSED (401) instead of binding the superadmin
        // 'admin' — mirrors BearerAuth::getFallbackAdmin(). In practice mcp.php
        // never calls run() with an empty admin (authenticate() denies first),
        // so this only closes a latent inconsistency.
        // ------------------------------------------------------------------
        if ($adminUser === '') {
            try {
                $configured = trim(\WHMCS\Config\Setting::getValue('nt_mcp_admin_user') ?? '');
                if ($configured !== '') {
                    $adminUser = $configured;
                }
            } catch (\Throwable $_ex) {
                // Setting not available — leave empty to deny below
            }
            if ($adminUser === '') {
                error_log('NT MCP Server: no admin user resolved and none configured — denying request');
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Unauthorized: no admin user configured']);
                return;
            }
        }

        // ------------------------------------------------------------------
        // 1. Parse the HTTP request FIRST — we need clientId before setup
        // ------------------------------------------------------------------
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $rawSessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? '';
        $clientId = preg_match('/^[a-zA-Z0-9._\-]{8,128}$/', $rawSessionId)
            ? $rawSessionId
            : bin2hex(random_bytes(16));

        // ------------------------------------------------------------------
        // 2. Handle GET (405) and other methods early — no server needed
        // ------------------------------------------------------------------
        if ($method === 'GET') {
            http_response_code(405);
            header('Allow: POST');
            header('Content-Type: application/json');
            echo json_encode(['error' => 'SSE not supported; use POST']);
            return;
        }
        if ($method !== 'POST') {
            http_response_code(405);
            header('Allow: GET, POST');
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        // SECURITY FIX (M-02): Reject oversized POST bodies before reading
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 1048576) { // 1 MB
            http_response_code(413);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Request body too large (max 1 MB)']);
            return;
        }

        // Read at most 1 MB + 1 byte so an oversized body is rejected without
        // materializing the whole payload (covers missing/incorrect Content-Length
        // and chunked Transfer-Encoding). The header guard above still runs first.
        $maxBytes = 1048576; // 1 MB
        $input = (string) file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (strlen($input) > $maxBytes) {
            http_response_code(413);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Request body too large (max 1 MB)']);
            return;
        }
        $decoded = json_decode($input, true);

        // ------------------------------------------------------------------
        // 3. Acquire GLOBAL lock before ANY cache access.
        //    The FileCache stores ALL sessions in one JSON file. Without a
        //    global lock, concurrent workers do read-modify-write cycles that
        //    overwrite each other's data (TOCTOU race), causing "Client not
        //    initialized" errors on tools/list.
        // ------------------------------------------------------------------
        $dataDir = __DIR__ . '/../data';
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0700, true);
        }
        $lockFile = $dataDir . '/nt_mcp_global.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) {
            // SECURITY FIX (F5 -- audit): Fail visibly if the lock file cannot
            // be opened, instead of proceeding without concurrency protection.
            error_log('NT MCP Server: Cannot open lock file: ' . $lockFile);
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Internal server error: lock acquisition failed']);
            return;
        }
        // PERF/AVAILABILITY FIX: bounded lock acquisition. A slow request must
        // fail fast (503 + Retry-After) instead of parking this PHP-FPM worker
        // on an unbounded LOCK_EX wait. An unbounded queue exhausts the FPM
        // pool and cascades 504s onto unrelated admin pages (observed on
        // /admin/configaddonmods.php). Correctness is unchanged — the lock is
        // still exclusive while held; under contention the client simply
        // retries (MCP clients honor 503/Retry-After).
        if (!self::acquireLockWithTimeout($lock, self::LOCK_TIMEOUT_SECONDS)) {
            error_log(
                'NT MCP Server: lock busy after '
                . self::LOCK_TIMEOUT_SECONDS
                . 's, returning 503: '
                . $lockFile
            );
            fclose($lock);
            http_response_code(503);
            header('Content-Type: application/json');
            header('Retry-After: ' . self::retryAfterSeconds());
            echo json_encode(['error' => 'Server busy, retry shortly']);
            return;
        }

        try {
            // ------------------------------------------------------------------
            // 4. Build + run the request via the MCP adapter (inside the lock —
            //    cache access is serialized here). The adapter (FASE 3) hides
            //    the php-mcp/server v1 API; internally it skips the tool scan
            //    when the elements cache is warm and pre-registers Tools (FASE 2).
            // ------------------------------------------------------------------
            $localApi = new LocalApiClient($adminUser);
            $capsule  = new CapsuleClient();

            $adapter  = new PhpMcpV1Adapter($localApi, $capsule, __DIR__);
            $messages = $adapter->handle($input, $clientId, $decoded['method'] ?? '');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        // ------------------------------------------------------------------
        // 6. Send the HTTP response (outside lock — no cache access needed)
        // ------------------------------------------------------------------
        $requestId = $decoded['id'] ?? null;

        header('Mcp-Session-Id: ' . $clientId);

        if ($requestId !== null) {
            header('Content-Type: application/json');

            foreach ($messages as $message) {
                if (isset($message['id']) && $message['id'] === $requestId) {
                    $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    // Fix: PHP json_encode([]) produces [] but MCP JSON Schema
                    // requires "properties" to be an object {}.  A targeted
                    // str_replace is safe here because "properties" only appears
                    // as a schema keyword, never as user data in tool responses.
                    $json = str_replace('"properties":[]', '"properties":{}', $json);
                    echo $json;
                    return;
                }
            }

            // SECURITY FIX (F6 -- audit): Return a JSON-RPC error instead of
            // an empty HTTP 200 when the request ID has no matching response.
            // An empty success response misleads MCP clients into thinking
            // the request was processed when it was silently dropped.
            echo json_encode([
                'jsonrpc' => '2.0',
                'id'      => $requestId,
                'error'   => [
                    'code'    => -32603,
                    'message' => 'Internal error: no response generated for this request',
                ],
            ], JSON_UNESCAPED_SLASHES);

        } else {
            http_response_code(202);
        }
    }

    /**
     * Acquire an exclusive lock with a bounded wait.
     *
     * Polls flock(LOCK_EX | LOCK_NB) until it succeeds or $timeoutSec elapses,
     * sleeping 100ms between attempts. Returns true if the lock was acquired,
     * false on timeout — the caller must then fail fast (503) rather than
     * block indefinitely, so a single slow request cannot exhaust the PHP-FPM
     * pool. Extracted as a pure helper so the timeout behaviour is unit
     * testable without a live WHMCS bootstrap.
     *
     * @param resource $handle  An open file handle from fopen().
     */
    private static function acquireLockWithTimeout($handle, float $timeoutSec): bool
    {
        $deadline = microtime(true) + $timeoutSec;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return true;
            }
            usleep(100000); // 100ms
        } while (microtime(true) < $deadline);

        return false;
    }

    private static function retryAfterSeconds(): int
    {
        return random_int(
            self::RETRY_AFTER_MIN_SECONDS,
            self::RETRY_AFTER_MAX_SECONDS
        );
    }
}
