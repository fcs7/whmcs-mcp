<?php
// src/Mcp/ServerAdapterInterface.php
namespace NtMcp\Mcp;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Fronteira interna entre o NT MCP e a biblioteca de servidor MCP concreta
 * (hoje o SDK oficial `mcp/sdk`, pinado em versão exata). Isola Server.php e
 * as Tools de mudanças de API da lib — um upgrade breaking futuro vira um novo
 * adapter, sem tocar no resto do addon.
 *
 * O contrato é PSR-7 puro: o chamador monta a ServerRequest (já autenticada e
 * com o corpo limitado) e emite a Response devolvida. O adapter não escreve
 * headers nem body por conta própria.
 */
interface ServerAdapterInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface;
}
