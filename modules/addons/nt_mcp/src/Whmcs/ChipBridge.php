<?php
// src/Whmcs/ChipBridge.php
namespace NtMcp\Whmcs;

/**
 * Ponte para o addon nt_chips (gestão de chips/eSIM da NT-Móvel).
 *
 * Os dois addons vivem no mesmo docroot WHMCS e compartilham a Capsule do
 * core, então chamar `NtChips\ChipRepository` direto dá transação real, lock
 * `FOR UPDATE` e a sincronização de custom fields que uma reimplementação por
 * SQL perderia. O que esta classe acrescenta é a fronteira: um ponto único que
 * decide se o nt_chips está mesmo disponível e traduz o retorno cru (bool,
 * stdClass) em algo que a tool consegue explicar ao chamador.
 *
 * ## Autoload
 *
 * Quando o addon nt_chips está ATIVO, o `hooks.php` dele é carregado pelo
 * `init.php` e já registra o autoloader — `class_exists()` resolve sozinho.
 * O fallback existe para o caso de o addon estar instalado mas inativo (ou
 * carregado numa ordem diferente): registra um autoloader mínimo apenas para o
 * prefixo `NtChips\`, apontando para o `src/` deles.
 *
 * O que o fallback deliberadamente NÃO faz é dar `require` no
 * `vendor/autoload.php` do nt_chips. O build de produção deles roda PHP-Scoper
 * com `Illuminate\` e `Psr\` FORA da lista de prefixados — carregar aquele
 * vendor registraria uma segunda cópia dessas interfaces por cima das nossas,
 * que é exatamente o fatal silencioso de "declaration compatibility" descrito
 * nos gotchas do CLAUDE.md. As duas classes que usamos (`ChipRepository`,
 * `PlayApiClient`) dependem só da Capsule do WHMCS e de curl nativo, então o
 * autoloader de prefixo basta.
 *
 * Indisponível = fail-closed: `available()` volta false e nenhuma tool tenta
 * adivinhar o schema.
 */
class ChipBridge
{
    public const TABLE = 'mod_nt_chips_chips';

    /** Colunas que podem sair para o chamador. `pdf_*` e `lpa_code` NUNCA entram aqui. */
    private const PUBLIC_COLUMNS = [
        'id', 'iccid', 'msisdn', 'client_id', 'service_id', 'status', 'tipo',
        'operadora_id', 'play_status', 'ativacao_status', 'arquivado_em',
    ];

    private static bool $autoloaderRegistered = false;

    public function available(): bool
    {
        if (class_exists('\NtChips\ChipRepository')) {
            return true;
        }

        self::registerFallbackAutoloader();

        return class_exists('\NtChips\ChipRepository');
    }

    /**
     * Autoloader mínimo para `NtChips\` → `modules/addons/nt_chips/src/`.
     * Só o prefixo do addon; nada do vendor scoped dele.
     */
    private static function registerFallbackAutoloader(): void
    {
        if (self::$autoloaderRegistered) {
            return;
        }
        self::$autoloaderRegistered = true;

        $src = dirname(__DIR__, 2) . '/nt_chips/src';
        if (!is_dir($src)) {
            return;
        }

        spl_autoload_register(static function (string $class) use ($src): void {
            if (!str_starts_with($class, 'NtChips\\')) {
                return;
            }
            $relative = str_replace('\\', '/', substr($class, strlen('NtChips\\')));
            $file = $src . '/' . $relative . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    // ---------------------------------------------------------------
    // Leituras
    // ---------------------------------------------------------------

    /** Linha crua do chip (uso interno do pré-diagnóstico), ou null. */
    public function find(int $chipId): ?object
    {
        return \NtChips\ChipRepository::find($chipId);
    }

    /**
     * Busca por ICCID exato. O ICCID é UNIQUE na tabela, então no máximo um.
     */
    public function findByIccid(string $iccid): ?object
    {
        return $this->query()->where('iccid', $iccid)->first();
    }

    /** @return object[] */
    public function findByServiceId(int $serviceId): array
    {
        return array_values($this->query()->where('service_id', $serviceId)->get()->all());
    }

    /** @return object[] */
    public function findByClientId(int $clientId, int $limit = 50): array
    {
        return array_values(
            $this->query()->where('client_id', $clientId)->orderBy('id')->limit($limit)->get()->all()
        );
    }

    /** `userid` do serviço em tblhosting; null quando o serviço não existe. */
    public function serviceOwner(int $serviceId): ?int
    {
        $row = \WHMCS\Database\Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->first(['userid']);

        if ($row === null) {
            return null;
        }
        $userId = is_array($row) ? ($row['userid'] ?? null) : ($row->userid ?? null);

        return is_numeric($userId) ? (int) $userId : null;
    }

    /** ICCID do chip que já ocupa o serviço (excluindo o próprio), ou null. */
    public function serviceOccupant(int $serviceId, int $exceptChipId = 0): ?string
    {
        return \NtChips\ChipRepository::serviceOccupant($serviceId, $exceptChipId);
    }

    // ---------------------------------------------------------------
    // Escritas — delegam aos estáticos do repositório do nt_chips
    // ---------------------------------------------------------------

    /** @param array<string,mixed> $data @return int id do chip criado */
    public function create(array $data): int
    {
        return \NtChips\ChipRepository::create($data);
    }

    /**
     * CAS: sem `$expectedIccid`, o update só vale se o ICCID ainda for NULL.
     * O repositório também exige tipo físico, ativação não concluída e msisdn
     * vazio — false quando qualquer condição falha.
     */
    public function setIccid(int $chipId, string $iccid): bool
    {
        return \NtChips\ChipRepository::setIccid($chipId, $iccid);
    }

    public function setLpaCode(int $chipId, string $lpa): void
    {
        \NtChips\ChipRepository::setLpaCode($chipId, $lpa);
    }

    public function updatePlayStatus(int $chipId, string $status): bool
    {
        return \NtChips\ChipRepository::updatePlayStatus($chipId, $status);
    }

    public function assignToService(int $chipId, int $serviceId, string $msisdn = ''): bool
    {
        return \NtChips\ChipRepository::assignToService($chipId, $serviceId, $msisdn);
    }

    /**
     * Valida o chip contra a Play e PERSISTE o `play_status` resultante.
     *
     * A classificação é do nt_chips (`AtivacaoService::validarChip()` →
     * `classificarIccid()`) de propósito: ela discrimina pelo campo estruturado
     * `success` da Play, trata o 409 "ICCID já alocado" (que vem com
     * `success:false` e ainda assim significa alocado) e falha fechado em
     * resposta desconhecida. Reimplementar isso aqui daria duas leituras
     * divergentes do mesmo payload — e a que errasse para `alocado` liberaria
     * o vínculo com um ICCID que a Play recusou.
     *
     * `validarChip()` também exige operadora com `suporte_play` e chip não
     * arquivado, e grava o status — a tool só reporta.
     *
     * @return array{ok:bool,status:?string,reason:?string}
     *         `ok=false` quando o token da Play não está configurado ou o
     *         transporte falhou: condição de operação, não bug, então vira
     *         envelope explicando em vez de -32603 genérico.
     */
    public function validateAgainstPlay(int $chipId): array
    {
        try {
            $service = new \NtChips\AtivacaoService(
                new \NtChips\PlayApiClient(\NtChips\PlayApiClient::tokenFromConfig())
            );
            $status = $service->validarChip($chipId);
        } catch (\Throwable $e) {
            $correlationId = Diagnostics::report(Diagnostics::CATEGORY_UNHANDLED, 'chip_play_lookup', $e);

            return ['ok' => false, 'status' => null, 'reason' => $correlationId];
        }

        return ['ok' => true, 'status' => $status, 'reason' => null];
    }

    // ---------------------------------------------------------------
    // Projeção
    // ---------------------------------------------------------------

    /**
     * Projeção pública do chip. Allowlist de colunas, não denylist: uma coluna
     * nova no nt_chips (outro campo de PDF, outro segredo) não passa a vazar
     * sozinha só porque foi criada.
     *
     * @return array<string,mixed>
     */
    public static function project(object $chip): array
    {
        $row = get_object_vars($chip);
        $out = [];
        foreach (self::PUBLIC_COLUMNS as $col) {
            if (!array_key_exists($col, $row)) {
                continue;
            }
            $out[$col === 'id' ? 'chip_id' : $col] = $row[$col];
        }

        return $out;
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        return \WHMCS\Database\Capsule::table(self::TABLE)->whereNull('arquivado_em');
    }
}
