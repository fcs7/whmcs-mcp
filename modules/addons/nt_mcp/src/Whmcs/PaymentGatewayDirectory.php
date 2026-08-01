<?php
// src/Whmcs/PaymentGatewayDirectory.php
namespace NtMcp\Whmcs;

/**
 * Introspecção READ-ONLY dos gateways de pagamento configurados no WHMCS.
 *
 * Existe para validar o system name de `paymentmethod` ANTES do primeiro efeito
 * financeiro da conversão de cotação. Sem isso, `AcceptQuote` persiste a
 * conversão e só então `UpdateInvoice` descobre que o gateway não existe —
 * produzindo de propósito a falha parcial irreversível que o plano manda evitar.
 *
 * Fonte de verdade
 * ----------------
 * `tblpaymentgateways`, cuja coluna `gateway` guarda o system name do módulo.
 * É o que a documentação oficial do WHMCS instrui a consultar: "You can find the
 * internal name of the Stripe module by looking in the gateway column in the
 * tblpaymentgateways database table" (docs.whmcs.com, Stripe module). E
 * `UpdateInvoice` documenta `paymentmethod` como "The payment method of the
 * invoice in system format".
 *
 * Por que NÃO usar as alternativas
 * --------------------------------
 *  - `GetPaymentMethods` (LocalAPI) reintroduziria um 52º comando na allowlist,
 *    explicitamente vedado.
 *  - `CapsuleClient` NÃO é usado aqui de propósito: sua allowlist é compartilhada
 *    com as tools de CRM, e `tblpaymentgateways` guarda CREDENCIAIS de gateway
 *    na coluna `value`. Liberar a tabela lá daria às tools de CRM leitura de
 *    segredos. Esta classe faz uma leitura estreita e projeta SOMENTE a coluna
 *    `gateway` — nunca `setting`/`value`.
 *
 * O acesso direto e estreito ao Capsule segue o padrão que o addon já usa em
 * `LocalApiClient::resolveAdminId()` (`tbladmins`) e em `BearerAuth`
 * (`mod_nt_mcp_oauth_tokens`, `tbladmins`).
 */
class PaymentGatewayDirectory
{
    /** @var callable|null Injeção para testes: fn(): array<string> */
    private $resolver = null;

    /** @var array<string>|null cache por request */
    private ?array $cache = null;

    /** Substitui a leitura do banco em testes. */
    public function setResolver(callable $fn): void
    {
        $this->resolver = $fn;
        $this->cache = null;
    }

    /**
     * System names dos gateways configurados.
     *
     * @return array<string>
     * @throws \RuntimeException se a introspecção não estiver disponível
     */
    public function configuredGateways(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if ($this->resolver !== null) {
            return $this->cache = array_values(array_unique(($this->resolver)()));
        }

        if (!class_exists('\WHMCS\Database\Capsule')) {
            throw new \RuntimeException(
                'PaymentGatewayDirectory: WHMCS Capsule unavailable; cannot verify payment gateways.'
            );
        }

        try {
            $rows = \WHMCS\Database\Capsule::table('tblpaymentgateways')
                ->select('gateway')      // NUNCA projeta setting/value (credenciais)
                ->distinct()
                ->get();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'PaymentGatewayDirectory: failed to read configured payment gateways: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $gateways = [];
        foreach ($rows as $row) {
            $name = is_array($row) ? ($row['gateway'] ?? null) : ($row->gateway ?? null);
            if (is_string($name) && $name !== '') {
                $gateways[] = $name;
            }
        }

        return $this->cache = array_values(array_unique($gateways));
    }

    /**
     * Valida o system name ANTES de qualquer efeito.
     *
     * Conservador por desenho: se a introspecção falhar, a validação NÃO é
     * ignorada — a exceção sobe e o chamador recusa a operação. `paymentmethod`
     * é opcional, então falhar fechado aqui nunca impede a conversão em si;
     * apenas impede converter com um gateway que não pôde ser verificado.
     *
     * @throws \InvalidArgumentException gateway inexistente
     * @throws \RuntimeException         introspecção indisponível
     */
    public function assertConfigured(string $systemName, string $field = 'paymentmethod'): void
    {
        $gateways = $this->configuredGateways();

        if ($gateways === []) {
            throw new \RuntimeException(
                'PaymentGatewayDirectory: no payment gateway is configured in WHMCS; '
                . "cannot validate {$field}."
            );
        }

        foreach ($gateways as $gateway) {
            if (strcasecmp($gateway, $systemName) === 0) {
                return;
            }
        }

        sort($gateways);
        throw new \InvalidArgumentException(sprintf(
            '%s "%s" is not a configured WHMCS payment gateway. Configured: %s',
            $field,
            $systemName,
            implode(', ', $gateways)
        ));
    }
}
