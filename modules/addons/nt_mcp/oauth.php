<?php
/**
 * NT MCP — OAuth 2.1 Authorization Server
 *
 * Implements OAuth 2.1 with PKCE (S256) for MCP Streamable HTTP transport.
 * All endpoints routed via PATH_INFO on this single file.
 *
 * Endpoints:
 *   GET  /resource-metadata                    → Protected Resource Metadata (RFC 9728)
 *   GET  /.well-known/openid-configuration     → Authorization Server Metadata (RFC 8414)
 *   POST /register                             → Dynamic Client Registration (RFC 7591)
 *   GET  /authorize                            → Authorization page
 *   POST /authorize                            → Process approval
 *   POST /token                                → Token exchange
 */

// 1. Autoload do Composer PRIMEIRO — garante que psr/log v3 e demais
//    PSR packages do addon sejam registrados antes do WHMCS carregar
//    suas versões v1, evitando fatal "declaration compatibility" errors.
require_once __DIR__ . '/vendor/autoload.php';

// 2. Inicializar WHMCS (3 niveis: addons/nt_mcp -> modules -> whmcs root)
define('CLIENTAREA', true);
require_once __DIR__ . '/../../../init.php';

use NtMcp\Http\TlsEnforcer;
use NtMcp\Http\SecurityHeaders;
use NtMcp\Http\CorsHandler;
use NtMcp\Http\IpAllowlist;
use NtMcp\Security\RateLimiter;
use NtMcp\OAuth\OAuthMigration;
use NtMcp\OAuth\OAuthRouter;

// SECURITY FIX (F1 -- audit): TLS enforcement (RFC 6749 §3.1)
TlsEnforcer::enforce();

// SECURITY CONTROL (9.4): Optional IP allowlist (WO-6)
IpAllowlist::enforce();

// SECURITY FIX (WO-6): IP-based rate limiting for OAuth entry points
(new RateLimiter('nt_mcp_oauth_rl_', 60, 60))->enforce();

// SECURITY FIX (F2 -- audit): Security response headers
SecurityHeaders::emit();

// CORS headers
if (CorsHandler::handle()) {
    exit;
}

// Ensure OAuth tables exist (lazy creation)
OAuthMigration::ensureTables();

// Route to appropriate handler
OAuthRouter::dispatch();
exit;
