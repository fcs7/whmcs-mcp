<?php
// src/Mcp/ServerAdapterInterface.php
namespace NtMcp\Mcp;

/**
 * Fronteira interna entre o NT MCP e a biblioteca de servidor MCP concreta
 * (hoje php-mcp/server ^1.0). Isola Server.php e as Tools de mudanças de API
 * da lib pinada — um upgrade breaking futuro vira um novo adapter, sem tocar
 * no resto do addon (FASE 3 / débito arquitetural #3).
 */
interface ServerAdapterInterface
{
    /**
     * Processa um corpo JSON-RPC MCP e retorna as mensagens enfileiradas para
     * o cliente (cada uma no formato JSON-RPC). O chamador serializa a resposta
     * HTTP — o adapter não escreve headers nem body.
     *
     * @param string      $input     Corpo bruto da requisição (JSON-RPC).
     * @param string      $clientId  Session id já validado.
     * @param string|null $mcpMethod Método JSON-RPC do corpo, usado no pré-seed
     *                               do flag de inicialização; '' / null se ausente.
     * @return array<int,array<string,mixed>> Mensagens enfileiradas para o cliente.
     */
    public function handle(string $input, string $clientId, ?string $mcpMethod): array;
}
