<?php

declare(strict_types=1);

namespace NtMcp\OAuth;

use NtMcp\Auth\AdminValidator;

use WHMCS\Database\Capsule;

/**
 * OAuth 2.1 §6.1 refresh token grant — rotação obrigatória (single-use),
 * família revogável por inteiro no reuso detectado.
 *
 * Toda a lógica testável fica aqui, fora do handler (F4, fora de escopo):
 * os handlers atuais fazem `echo` direto e por isso não têm teste — este
 * serviço não repete esse padrão.
 */
final class RefreshTokenService
{
    private const TABLE = 'mod_nt_mcp_oauth_refresh_tokens';
    private const TOKENS_TABLE = 'mod_nt_mcp_oauth_tokens';

    /** TTL do refresh token: 30 dias, sliding a cada rotação. */
    private const TTL_SECONDS = 30 * 24 * 60 * 60;

    public function __construct(private readonly AdminValidator $adminValidator = new AdminValidator())
    {
    }

    /**
     * Gera um family_id novo — uso: emissão original (authorization_code),
     * quando ainda não existe família para reaproveitar. F4 deve chamar isto
     * explicitamente e passar o valor para `issue()` (e para o insert do
     * access token, que precisa do MESMO family_id) em vez de contar com o
     * `$familyId = null` de `issue()`, cujo valor gerado internamente não é
     * devolvido pela assinatura do plano (`issue(): string`).
     */
    public static function newFamilyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Emite um refresh token novo e devolve o plaintext (nunca persistido —
     * só o hash SHA-256 vai para o banco). `$familyId` null cria uma família
     * nova internamente (ver `newFamilyId()`); use explícito quando o
     * chamador precisar do family_id para mais alguma coisa (ex.: gravar no
     * access token emitido junto).
     */
    public function issue(string $clientId, ?string $adminUser, ?string $familyId, int $now): string
    {
        $plaintext = bin2hex(random_bytes(32));

        Capsule::table(self::TABLE)->insertGetId([
            'token_hash' => hash('sha256', $plaintext),
            'client_id'  => $clientId,
            'admin_user' => $adminUser,
            'family_id'  => $familyId ?? self::newFamilyId(),
            'expires_at' => $now + self::TTL_SECONDS,
            'used'       => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    /**
     * Consome (rotaciona) um refresh token. Single-use: a linha presente é
     * marcada `used=true` atomicamente; uma segunda apresentação do MESMO
     * plaintext é reuso (família inteira revogada) ou, na pior hipótese, uma
     * corrida perdida contra outra requisição legítima (não revoga).
     */
    public function redeem(string $presented, string $clientId, int $now): RefreshRedemption
    {
        $tokenHash = hash('sha256', $presented);

        $row = Capsule::table(self::TABLE)
            ->where('token_hash', $tokenHash)
            ->first();

        if ($row === null) {
            return RefreshRedemption::denied('invalid_grant');
        }

        // Reuso: token JÁ consumido sendo apresentado de novo — bug de
        // cliente ou replay de token roubado. OAuth 2.1 §6.1: revoga a
        // família inteira (defesa padrão).
        if ((bool) $row->used) {
            $this->revokeFamily((string) $row->family_id);
            return RefreshRedemption::reuseDetected();
        }

        if ((int) $row->expires_at <= $now) {
            return RefreshRedemption::denied('invalid_grant');
        }

        if ((string) $row->client_id !== $clientId) {
            return RefreshRedemption::denied('invalid_grant');
        }

        // SECURITY FIX (mesmo padrão H-04 dos codes): consumo atômico.
        if (!$this->attemptConsume((int) $row->id)) {
            // Perdeu a corrida contra outra requisição para o MESMO token —
            // não é ladrão, é concorrência. Não revoga a família.
            return RefreshRedemption::denied('invalid_grant');
        }

        $adminUser = property_exists($row, 'admin_user') ? trim((string) ($row->admin_user ?? '')) : '';

        // admin_user null/vazio NÃO nega o redeem: BearerAuth tem cadeia de
        // fallback (per-token → nt_mcp_admin_user global → 401). Negar aqui
        // mataria por 30 dias uma família legítima cujo approved_by veio
        // null. O null é propagado para o access token novo; BearerAuth
        // resolve o fallback e falha fechado no uso, como hoje.
        if ($adminUser !== '' && !$this->adminValidator->isActive($adminUser)) {
            $this->revokeFamily((string) $row->family_id);
            return RefreshRedemption::denied('invalid_grant');
        }

        return RefreshRedemption::ok($clientId, $adminUser !== '' ? $adminUser : null, (string) $row->family_id);
    }

    /**
     * Revoga uma família inteira: refresh tokens e access tokens com o
     * mesmo `family_id`. Usada no reuso detectado, no admin inativo e (fora
     * de escopo aqui) pelo `revoke_oauth_token` individual do F6.
     */
    public function revokeFamily(string $familyId): void
    {
        Capsule::connection()->transaction(function () use ($familyId): void {
            Capsule::table(self::TABLE)->where('family_id', $familyId)->delete();
            Capsule::table(self::TOKENS_TABLE)->where('family_id', $familyId)->delete();
        });
    }

    public function purgeExpired(int $now): int
    {
        return Capsule::table(self::TABLE)
            ->where('expires_at', '<=', $now)
            ->delete();
    }

    /**
     * Consumo atômico isolado (mesmo padrão H-04): `where(id)->where(used,
     * false)->update(used=true)`, `$affected === 0` = corrida perdida.
     * Extraído em método próprio para ser testável em isolamento — um teste
     * ponta-a-ponta via `redeem()` não consegue reproduzir a corrida real
     * (o pré-check do passo 2 sempre intercepta `used=true` primeiro; a
     * corrida real só existe entre dois processos concorrentes, algo que um
     * fake síncrono de banco não reproduz).
     */
    private function attemptConsume(int $id): bool
    {
        $affected = Capsule::table(self::TABLE)
            ->where('id', $id)
            ->where('used', false)
            ->update(['used' => true]);

        return $affected > 0;
    }
}
