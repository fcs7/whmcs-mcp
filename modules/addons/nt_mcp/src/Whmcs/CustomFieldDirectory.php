<?php
// src/Whmcs/CustomFieldDirectory.php
namespace NtMcp\Whmcs;

/**
 * Introspecção READ-ONLY dos campos personalizados de CLIENTE configurados no
 * WHMCS.
 *
 * Existe porque `GetClientsDetails` devolve `customfields` como lista de
 * `{id, value}` — sem rótulo. Um `{"id":134,"value":"12345678900"}` não diz a
 * quem lê se aquilo é CPF, inscrição estadual ou número de contrato, e o agente
 * MCP fica adivinhando pelo formato do valor.
 *
 * Fonte de verdade
 * ----------------
 * `tblcustomfields`, filtrada por `type = 'client'`. É a mesma tabela que a UI
 * do admin usa em Setup → Custom Client Fields.
 *
 * Por que NÃO usar as alternativas
 * --------------------------------
 *  - Não existe comando de LocalAPI que liste custom fields; adicionar um ao
 *    allowlist não é opção porque não há qual adicionar.
 *  - `CapsuleClient` NÃO é usado aqui de propósito: a allowlist dele é
 *    compartilhada com as tools de CRM, e liberar uma tabela core lá daria às
 *    tools de CRM leitura fora do escopo delas. Esta classe faz uma leitura
 *    estreita e projeta SOMENTE `id`, `fieldname` e `fieldtype` — nunca
 *    `fieldoptions`, `regexpr` ou `description`.
 *
 * O acesso direto e estreito ao Capsule segue o padrão já usado em
 * `PaymentGatewayDirectory` (`tblpaymentgateways`),
 * `LocalApiClient::resolveAdminId()` (`tbladmins`) e `BearerAuth`.
 *
 * Falha SUAVE por decisão
 * -----------------------
 * Ao contrário de `PaymentGatewayDirectory` — cuja leitura protege um efeito
 * financeiro e portanto falha fechado —, este diretório só ENRIQUECE uma
 * resposta de leitura. Banco indisponível, tabela ausente ou linha corrompida
 * resultam em "sem rótulo": o payload sai exatamente como o WHMCS mandou.
 * Derrubar `whmcs_get_client` inteiro por causa de um label seria trocar um
 * inconveniente por uma indisponibilidade.
 */
class CustomFieldDirectory
{
    /** @var callable|null Injeção para testes: fn(): iterable<mixed> */
    private $resolver = null;

    /** @var array<int, array{name:string, type:string}>|null cache por request */
    private ?array $cache = null;

    /** Substitui a leitura do banco em testes. */
    public function setResolver(callable $fn): void
    {
        $this->resolver = $fn;
        $this->cache = null;
    }

    /**
     * Rótulo de um campo, ou null quando o id não resolve.
     *
     * @return array{name:string, type:string}|null
     */
    public function labelFor(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return $this->fields()[$id] ?? null;
    }

    /** @return array<int, array{name:string, type:string}> */
    public function fields(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        return $this->cache = self::index($this->readRows());
    }

    /** @return iterable<mixed> */
    private function readRows(): iterable
    {
        if ($this->resolver !== null) {
            try {
                $rows = ($this->resolver)();
            } catch (\Throwable) {
                return [];
            }

            return is_iterable($rows) ? $rows : [];
        }

        if (!class_exists('\WHMCS\Database\Capsule')) {
            return [];
        }

        try {
            return \WHMCS\Database\Capsule::table('tblcustomfields')
                ->where('type', 'client')
                ->select('id', 'fieldname', 'fieldtype')
                ->get();
        } catch (\Throwable $e) {
            // A mensagem downstream NÃO é propagada: erros de driver carregam
            // DSN e credencial. Só classe e fingerprint viajam.
            Diagnostics::report(Diagnostics::CATEGORY_DB_EXCEPTION, 'tblcustomfields', $e);

            return [];
        }
    }

    /**
     * Indexa por id, descartando linha que não sirva. Linha ruim é ignorada
     * individualmente (não invalida o diretório inteiro): aqui um rótulo a menos
     * custa clareza, não correção.
     *
     * @param iterable<mixed> $rows
     * @return array<int, array{name:string, type:string}>
     */
    private static function index(iterable $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $id = (int) (self::field($row, 'id') ?? 0);
            $name = self::field($row, 'fieldname');
            if ($id <= 0 || !is_string($name)) {
                continue;
            }
            // O admin do WHMCS aceita HTML no nome do campo; o rótulo aqui é
            // consumido como TEXTO por um agente, então as tags saem.
            $name = html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // `&nbsp;` decodifica para U+00A0, que `\s` do PCRE não cobre — sem
            // isto o rótulo carrega um espaço invisível que quebra comparação.
            $name = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $name) ?? $name);
            if ($name === '') {
                continue;
            }
            $type = self::field($row, 'fieldtype');
            $indexed[$id] = [
                'name' => $name,
                'type' => is_string($type) && $type !== '' ? $type : 'text',
            ];
        }

        return $indexed;
    }

    /**
     * Desembala a coluna da projeção. Aplicado aos DOIS caminhos — Capsule real
     * e resolver de teste — para que um fake no formato do driver exercite
     * exatamente este código.
     */
    private static function field(mixed $row, string $column): mixed
    {
        if (is_array($row)) {
            return $row[$column] ?? null;
        }
        if (is_object($row)) {
            return $row->{$column} ?? null;
        }

        return null;
    }
}
