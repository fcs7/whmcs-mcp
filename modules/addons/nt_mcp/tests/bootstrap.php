<?php
// Simula o ambiente WHMCS para testes unitários
// localAPI() é a função global do WHMCS — mockamos aqui

if (!function_exists('localAPI')) {
    function localAPI(string $command, array $values, string $adminuser = 'admin'): array {
        return ['result' => 'success'];
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

// Ensure Mockery expectations are verified after each test
register_shutdown_function(function () {
    \Mockery::close();
});
