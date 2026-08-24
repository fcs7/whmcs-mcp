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
 *  - nenhum cliente genérico de banco é usado: `tblpaymentgateways` guarda
 *    CREDENCIAIS de gateway na coluna `value`. Esta classe faz uma leitura
 *    estreita e projeta SOMENTE a coluna
 *    `gateway` — nunca `setting`/`value`.
 *
 * O acesso direto e estreito ao Capsule segue o padrão que o addon já usa em
 * `LocalApiClient::resolveAdminId()` (`tbladmins`) e em `BearerAuth`
 * (`mod_nt_mcp_oauth_tokens`, `tbladmins`).
 */
class PaymentGatewayDirectory
{
    /**
     * Sintaxe CANÔNICA de system name de gateway, conforme a documentação
     * oficial: o valor é o nome do arquivo em `modules/gateways/<name>.php`,
     * que a WHMCS exige em minúsculas e começando por letra.
     *
     * `PayPal`, `1paypal` e `_paypal` NÃO são system names carregáveis. Aceitar
     * qualquer um deles deixaria uma linha corrompida chegar ao primeiro efeito
     * financeiro — e a falha só apareceria no `UpdateInvoice`, depois da
     * cotação já aceita, recriando exatamente a parcial que o F3 evita.
     */
    // `\z` e não `$`: em PCRE, `$` casa antes de um \n final, então
    // "banktransfer\n" passaria por `^[a-z][a-z0-9_]*$`.
    private const CANONICAL_NAME_PATTERN = '/^[a-z][a-z0-9_]*\z/';

    /**
     * Sintaxe aceitável de INPUT. Mais frouxa só quanto a maiúsculas — o
     * casamento é case-insensitive —, mas ainda exige começar por letra. O
     * retorno continua sendo o canônico exato do banco.
     */
    private const INPUT_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*\z/';

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
     * System names configurados, já limpos e validados.
     *
     * A introspecção é ATÔMICA: se QUALQUER linha for inválida (vazia, só
     * espaço, sintaxe fora do padrão) ou se houver ambiguidade de capitalização
     * (`PayPal` e `paypal` ao mesmo tempo), a lista inteira é considerada não
     * confiável e a validação falha fechada. Descartar só a linha ruim deixaria
     * um diretório parcial passar por completo — e a decisão do plano é não
     * converter cotação com gateway que não foi provado.
     *
     * @return array<string>
     * @throws \RuntimeException se a introspecção não estiver disponível ou for inválida
     */
    public function configuredGateways(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        return $this->cache = $this->validateRows($this->readRows());
    }

    /** @return array<mixed> valores crus da coluna `gateway` */
    private function readRows(): array
    {
        if ($this->resolver !== null) {
            try {
                $rows = ($this->resolver)();
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'PaymentGatewayDirectory: failed to read configured payment gateways.',
                    0,
                    $e
                );
            }

            return is_array($rows) ? $rows : [];
        }

        if (!class_exists('\WHMCS\Database\Capsule')) {
            throw new \RuntimeException(
                'PaymentGatewayDirectory: WHMCS Capsule unavailable; cannot verify payment gateways.'
            );
        }

        try {
            $result = \WHMCS\Database\Capsule::table('tblpaymentgateways')
                ->select('gateway')      // NUNCA projeta setting/value (credenciais)
                ->distinct()
                ->get();
        } catch (\Throwable $e) {
            // Mensagem downstream NÃO é propagada (F2): erros de driver podem
            // carregar credenciais de conexão.
            throw new \RuntimeException(
                'PaymentGatewayDirectory: failed to read configured payment gateways.',
                0,
                $e
            );
        }

        $rows = [];
        foreach ($result as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Desembala a linha da projeção. Aplicado aos DOIS caminhos — Capsule real e
     * resolver de teste — para que um fake devolvendo linhas no formato do
     * driver exercite exatamente este código, e não um atalho.
     */
    private function unwrapRow(mixed $row): mixed
    {
        if (is_array($row)) {
            return $row['gateway'] ?? null;
        }

        if ($row instanceof \stdClass || is_object($row)) {
            return $row->gateway ?? null;
        }

        return $row;
    }

    /**
     * @param array<mixed> $rows
     * @return array<string>
     */
    private function validateRows(array $rows): array
    {
        $canonical = [];
        $byLower = [];

        foreach ($rows as $row) {
            $raw = $this->unwrapRow($row);

            if (!is_string($raw)) {
                throw new \RuntimeException(
                    'PaymentGatewayDirectory: payment gateway list contains a non-string entry; '
                    . 'refusing to validate against an unreliable directory.'
                );
            }

            // A linha é validada CRUA. Aparar antes do regex fazia
            // `' banktransfer '` passar e ser encaminhado aparado — que não é o
            // valor exato do banco, justamente o requisito do F3. Espaço em
            // volta indica coluna suja: invalida o diretório inteiro.
            $name = $raw;
            if ($name === '' || preg_match(self::CANONICAL_NAME_PATTERN, $name) !== 1) {
                throw new \RuntimeException(
                    'PaymentGatewayDirectory: payment gateway list contains an entry that is not a '
                    . 'valid WHMCS gateway system name (lowercase, starting with a letter, no '
                    . 'surrounding whitespace); refusing to validate against an unreliable directory.'
                );
            }

            $lower = strtolower($name);
            if (isset($byLower[$lower])) {
                if ($byLower[$lower] !== $name) {
                    throw new \RuntimeException(
                        'PaymentGatewayDirectory: payment gateway list is ambiguous (entries differing '
                        . 'only by capitalisation); refusing to guess the canonical system name.'
                    );
                }
                continue; // duplicata EXATA: dedup silencioso
            }

            $byLower[$lower] = $name;
            $canonical[] = $name;
        }

        return $canonical;
    }

    /**
     * Resolve o input para o system name EXATO armazenado no banco.
     *
     * Aceitar `BankTransfer` quando o banco guarda `banktransfer` é conveniente,
     * mas encaminhar `BankTransfer` ao `UpdateInvoice` dependeria de coerção não
     * documentada do WHMCS. Por isso o casamento é case-insensitive e o RETORNO
     * é sempre o valor canônico — e só esse valor pode seguir adiante.
     *
     * @return string system name canônico
     * @throws \InvalidArgumentException gateway inexistente ou input inválido
     * @throws \RuntimeException         introspecção indisponível/não confiável
     */
    public function resolve(string $systemName, string $field = 'paymentmethod'): string
    {
        $input = trim($systemName);
        if ($input === '' || preg_match(self::INPUT_NAME_PATTERN, $input) !== 1) {
            throw new \InvalidArgumentException(
                "{$field} must be a WHMCS gateway system name: start with a letter and contain "
                . 'only letters, digits and underscore.'
            );
        }

        $gateways = $this->configuredGateways();

        if ($gateways === []) {
            throw new \RuntimeException(
                'PaymentGatewayDirectory: no payment gateway is configured in WHMCS; '
                . "cannot validate {$field}."
            );
        }

        foreach ($gateways as $gateway) {
            if (strcasecmp($gateway, $input) === 0) {
                return $gateway; // valor EXATO do banco
            }
        }

        $known = $gateways;
        sort($known);
        throw new \InvalidArgumentException(sprintf(
            '%s "%s" is not a configured WHMCS payment gateway. Configured: %s',
            $field,
            $input,
            implode(', ', $known)
        ));
    }
}
