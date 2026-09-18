<?php

// Loaded only inside isolated HookTest processes, never by the global bootstrap.
function add_hook(string $name, int $priority, callable $callback): void
{
    $GLOBALS['nt_webmcp_test_hooks'][] = [$name, $priority, $callback];
}
