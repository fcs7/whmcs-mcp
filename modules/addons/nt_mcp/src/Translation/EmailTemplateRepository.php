<?php

declare(strict_types=1);

namespace NtMcp\Translation;

use NtMcp\Crm\CapsuleSchemaProbe;
use WHMCS\Database\Capsule;

/**
 * Fronteira ÚNICA entre as tools de tradução e `tblemailtemplates`.
 *
 * ACHADO no desenv (2026-09-18, ver plano — "Verificação", passo 0): os 92
 * masters (`language=''`) são MISTOS — a maioria (~75) são templates padrão
 * do WHMCS em inglês, e uma minoria (~17) são customizados em PT. Já existem
 * linhas `portuguese-br` (6) e `english` (1) no banco. Por isso o
 * idioma-alvo NÃO é fixo: cada chamada escolhe entre os literais de
 * `SUPPORTED_TARGET_LANGUAGES`, e `SOURCE_LANGUAGE` (o master, `''`)
 * continua o mesmo nos dois sentidos — o mecanismo de upsert por `name` não
 * muda.
 *
 * Nenhum método aceita nome de tabela ou coluna vindo de fora: todo acesso
 * usa as constantes de `TranslationSchema`. `target_language` só aceita os
 * literais de `SUPPORTED_TARGET_LANGUAGES` — nunca é interpolado em SQL fora
 * do `where` parametrizado, e um valor fora da lista é recusado ANTES de
 * qualquer consulta (inclusive antes do schema guard).
 */
final class EmailTemplateRepository
{
    private const TABLE = TranslationSchema::TABLE_EMAIL_TEMPLATES;

    /** Idioma do template master (fonte) — sempre `''`, nos dois sentidos de tradução. */
    public const SOURCE_LANGUAGE = '';

    /** Idiomas-alvo aceitos por esta fase. */
    public const SUPPORTED_TARGET_LANGUAGES = ['english', 'portuguese-br'];

    /** Alvo padrão quando o chamador não especifica um. */
    public const DEFAULT_TARGET_LANGUAGE = 'english';

    /** Colunas copiadas do master ao criar a linha EN (além de subject/message). */
    private const COPIED_COLUMNS = [
        'type', 'name', 'fromname', 'fromemail', 'attachments',
        'copyto', 'blind_copy_to', 'plaintext', 'disabled', 'custom',
    ];

    private TranslationSchemaGuard $guard;

    private TranslationValidator $validator;

    public function __construct(?TranslationSchemaGuard $guard = null, ?TranslationValidator $validator = null)
    {
        $this->guard = $guard ?? new TranslationSchemaGuard(new CapsuleSchemaProbe());
        $this->validator = $validator ?? new TranslationValidator();
    }

    /**
     * Masters (idioma-fonte, `type<>'admin'`), com `has_target` e `variants`
     * calculados por uma única consulta de TODOS os irmãos (`language<>''`,
     * `type<>'admin'`) — `variants` lista, em ordem, os idiomas que já têm
     * linha irmã para aquele `name`; `has_target` é `target_language in
     * variants`.
     *
     * @return array<string, mixed>
     */
    public function listMasters(?string $type, bool $onlyMissing, int $limit, int $offset, string $targetLanguage): array
    {
        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert();
        } catch (TranslationException $e) {
            return $e->toPublicArray();
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $query = Capsule::table(self::TABLE)
            ->where('language', self::SOURCE_LANGUAGE)
            ->where('type', '<>', 'admin');
        if ($type !== null && $type !== '') {
            $query = $query->where('type', $type);
        }

        $masters = $query->select(['id', 'type', 'name', 'subject'])->orderBy('id')->get();
        $variantsByTypeAndName = $this->siblingVariantsByTypeAndName();

        $items = [];
        foreach ($masters as $row) {
            $name = self::text($row, 'name');
            $variants = $variantsByTypeAndName[self::text($row, 'type') . "\0" . $name] ?? [];
            $hasTarget = in_array($targetLanguage, $variants, true);
            if ($onlyMissing && $hasTarget) {
                continue;
            }
            $items[] = [
                'id' => self::intOf($row, 'id'),
                'type' => self::text($row, 'type'),
                'name' => $name,
                'subject' => self::text($row, 'subject'),
                'has_target' => $hasTarget,
                'variants' => $variants,
            ];
        }

        $total = count($items);
        $page = array_slice($items, $offset, $limit);

        return [
            'result' => 'success',
            'target_language' => $targetLanguage,
            'items' => $page,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + count($page)) < $total,
        ];
    }

    /**
     * PT completo + variante no idioma-alvo (ou null) + `target_hash`, por id
     * (1–10). Id inexistente, `admin` ou não-fonte devolve erro por item —
     * sem interromper o lote.
     *
     * @param array<int, int> $ids
     * @return array<string, mixed>
     */
    public function getPairs(array $ids, string $targetLanguage): array
    {
        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert();
        } catch (TranslationException $e) {
            return $e->toPublicArray();
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > 10) {
            return [
                'result' => 'error',
                'error_code' => 'invalid_ids',
                'message' => 'ids deve conter de 1 a 10 inteiros.',
            ];
        }

        $rows = Capsule::table(self::TABLE)
            ->whereIn('id', $ids)
            ->select(['id', 'type', 'name', 'subject', 'message', 'language'])
            ->get();

        $byId = [];
        foreach ($rows as $row) {
            $byId[self::intOf($row, 'id')] = $row;
        }

        $pairs = [];
        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;
            if ($row === null) {
                $pairs[] = ['id' => $id, 'error' => 'not_found'];
                continue;
            }
            if (self::text($row, 'type') === 'admin' || self::text($row, 'language') !== self::SOURCE_LANGUAGE) {
                $pairs[] = ['id' => $id, 'error' => 'not_translatable'];
                continue;
            }

            $target = $this->findTargetSibling(self::text($row, 'name'), self::text($row, 'type'), $targetLanguage);
            $pairs[] = [
                'id' => $id,
                'source' => [
                    'id' => self::intOf($row, 'id'),
                    'type' => self::text($row, 'type'),
                    'name' => self::text($row, 'name'),
                    'subject' => self::text($row, 'subject'),
                    'message' => self::text($row, 'message'),
                ],
                'target' => $target === null ? null : [
                    'id' => self::intOf($target, 'id'),
                    'subject' => self::text($target, 'subject'),
                    'message' => self::text($target, 'message'),
                ],
                'target_hash' => $this->hashOf($target),
            ];
        }

        return ['result' => 'success', 'target_language' => $targetLanguage, 'pairs' => $pairs];
    }

    /**
     * Hash do par (subject, message). `'absent'` quando a linha não existe —
     * é o valor esperado por `expected_hash` para criar uma linha EN nova.
     */
    public function hashOf(mixed $row): string
    {
        if ($row === null) {
            return 'absent';
        }

        return hash('sha256', self::text($row, 'subject') . "\0" . self::text($row, 'message'));
    }

    /**
     * Resumo por idioma de `tblemailtemplates` (excluindo `type='admin'`) +
     * amostra de até 3 subjects de masters. `client_languages` e
     * `dynamic_translations_enabled` são responsabilidade de
     * `TranslationStatusReader` — não pertencem a esta tabela.
     *
     * @return array<string, mixed>
     */
    public function statusSummary(): array
    {
        try {
            $this->guard->assert();
        } catch (TranslationException $e) {
            return $e->toPublicArray();
        }

        $languageRows = Capsule::table(self::TABLE)
            ->where('type', '<>', 'admin')
            ->select(['language'])
            ->selectRaw('COUNT(*) as aggregate_count')
            ->groupBy('language')
            ->get();

        $byLanguage = [];
        foreach ($languageRows as $row) {
            $byLanguage[self::text($row, 'language')] = (int) self::rawValue($row, 'aggregate_count');
        }
        ksort($byLanguage);

        $sampleRows = Capsule::table(self::TABLE)
            ->where('type', '<>', 'admin')
            ->where('language', self::SOURCE_LANGUAGE)
            ->select(['subject'])
            ->orderBy('id')
            ->take(3)
            ->get();

        $sampleSubjects = [];
        foreach ($sampleRows as $row) {
            $sampleSubjects[] = self::text($row, 'subject');
        }

        return [
            'result' => 'success',
            'source_language' => self::SOURCE_LANGUAGE,
            'target_language' => self::DEFAULT_TARGET_LANGUAGE,
            'supported_target_languages' => self::SUPPORTED_TARGET_LANGUAGES,
            'by_language' => $byLanguage,
            'sample_subjects' => $sampleSubjects,
        ];
    }

    /**
     * Lote de upserts EN, tudo-ou-nada, com hash otimista e lock `FOR UPDATE`.
     *
     * `$dryRun=true` monta o preview e nunca grava (rollback ao final, mesmo
     * sem erro). `$dryRun=false` grava o backup ANTES do INSERT/UPDATE de cada
     * item, dentro da MESMA transação — se o backup lançar, a transação inteira
     * é revertida e a exceção sobe (não é erro de domínio, é falha inesperada).
     *
     * @param array<int, array{id?:mixed, subject?:mixed, message?:mixed, expected_hash?:mixed}> $items
     * @return array<string, mixed>
     */
    public function applyBatch(array $items, TranslationBackup $backup, bool $dryRun, string $targetLanguage): array
    {
        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert();
            $this->guard->assertInnoDb(self::TABLE);
        } catch (TranslationException $e) {
            return $e->toPublicArray();
        }

        if ($items === [] || count($items) > 10) {
            return [
                'result' => 'error',
                'error_code' => 'invalid_items',
                'message' => 'items deve conter de 1 a 10 elementos.',
            ];
        }

        $connection = Capsule::connection();
        $connection->beginTransaction();

        try {
            $results = [];

            foreach ($items as $item) {
                $outcome = $this->applyOne($item, $backup, $dryRun, $targetLanguage);
                if (($outcome['result'] ?? null) === 'error') {
                    $connection->rollBack();

                    return $outcome;
                }
                $results[] = $outcome;
            }

            if ($dryRun) {
                $connection->rollBack();
            } else {
                $connection->commit();
            }

            return ['result' => 'success', 'dry_run' => $dryRun, 'target_language' => $targetLanguage, 'items' => $results];
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * @param array{id?:mixed, subject?:mixed, message?:mixed, expected_hash?:mixed} $item
     * @return array<string, mixed>
     */
    private function applyOne(array $item, TranslationBackup $backup, bool $dryRun, string $targetLanguage): array
    {
        $id = (int) ($item['id'] ?? 0);
        $subject = (string) ($item['subject'] ?? '');
        $message = (string) ($item['message'] ?? '');
        $expectedHash = (string) ($item['expected_hash'] ?? '');

        if ($id <= 0) {
            return ['result' => 'error', 'error_code' => 'missing_id', 'message' => 'id é obrigatório em cada item.'];
        }

        $masterQuery = Capsule::table(self::TABLE)
            ->where('id', $id)
            ->where('type', '<>', 'admin')
            ->where('language', self::SOURCE_LANGUAGE)
            ->select(array_merge(['id', 'subject', 'message'], self::COPIED_COLUMNS));
        // Dry-run nunca segura lock de linha — só leitura, sem tudo-ou-nada real.
        if (!$dryRun) {
            $masterQuery = $masterQuery->lockForUpdate();
        }
        $master = $masterQuery->first();

        if ($master === null) {
            return [
                'result' => 'error',
                'error_code' => 'master_not_found',
                'message' => "Master id {$id} inexistente, admin ou nao-fonte.",
                'id' => $id,
            ];
        }

        $enQuery = Capsule::table(self::TABLE)
            ->where('language', $targetLanguage)
            ->where('type', self::text($master, 'type'))
            ->where('name', self::text($master, 'name'))
            ->select(['id', 'subject', 'message'])
            ->orderBy('id');
        if (!$dryRun) {
            $enQuery = $enQuery->lockForUpdate();
        }
        $en = $enQuery->first();

        $currentHash = $this->hashOf($en);
        if ($expectedHash !== $currentHash) {
            return [
                'result' => 'error',
                'error_code' => 'hash_conflict',
                'message' => "Conflito de edicao concorrente no id {$id}.",
                'id' => $id,
                'current_hash' => $currentHash,
            ];
        }

        $errors = $this->validator->validate(self::text($master, 'subject'), self::text($master, 'message'), $subject, $message);
        if ($errors !== []) {
            return [
                'result' => 'error',
                'error_code' => 'validation_failed',
                'message' => "Validacao de conteudo falhou no id {$id}.",
                'id' => $id,
                'errors' => $errors,
            ];
        }

        $action = $en === null ? 'insert' : 'update';
        $changedFields = $en === null
            ? ['subject', 'message']
            : array_values(array_filter([
                self::text($en, 'subject') !== $subject ? 'subject' : null,
                self::text($en, 'message') !== $message ? 'message' : null,
            ]));

        if ($dryRun) {
            return [
                'id' => $id,
                'action' => $action,
                'current_hash' => $currentHash,
                'changed_fields' => $changedFields,
                'subject_before' => $en === null ? null : self::text($en, 'subject'),
                'subject_after' => $subject,
                'message_excerpt' => mb_substr($message, 0, 300),
            ];
        }

        // Backup do estado ANTERIOR gravado ANTES da escrita — se lançar, o
        // repositório propaga e `applyBatch()` reverte a transação inteira.
        $backup->append([
            'ts' => gmdate('c'),
            'admin' => null,
            'master_id' => $id,
            'action' => $action,
            'target_language' => $targetLanguage,
            'previous' => $en === null ? null : [
                'id' => self::intOf($en, 'id'),
                'subject' => self::text($en, 'subject'),
                'message' => self::text($en, 'message'),
            ],
        ]);

        if ($action === 'insert') {
            $data = ['language' => $targetLanguage, 'subject' => $subject, 'message' => $message];
            foreach (self::COPIED_COLUMNS as $column) {
                $data[$column] = self::rawValue($master, $column);
            }
            $newId = Capsule::table(self::TABLE)->insertGetId($data);

            return ['id' => $id, 'action' => 'insert', 'en_id' => $newId];
        }

        Capsule::table(self::TABLE)->where('id', self::intOf($en, 'id'))->update([
            'subject' => $subject,
            'message' => $message,
        ]);

        return ['id' => $id, 'action' => 'update', 'en_id' => self::intOf($en, 'id')];
    }

    /**
     * Idiomas com linha irmã (`language<>''`, `type<>'admin'`), por `type` +
     * `name` — UMA consulta para todos os masters, em vez de N consultas por
     * master. A chave inclui `type` porque `name` sozinho não é único entre
     * tipos distintos (ex.: um template `general` e um `support` podem
     * compartilhar `name`); sem o `type` na chave, um sibling do tipo errado
     * podia ser contado como variante do master. `->get()` devolve
     * `Illuminate\Support\Collection` em produção; `foreach` (não
     * `array_map`) funciona igual em array e Collection.
     *
     * @return array<string, array<int, string>> "type\0name" => idiomas
     */
    private function siblingVariantsByTypeAndName(): array
    {
        $rows = Capsule::table(self::TABLE)
            ->where('language', '<>', self::SOURCE_LANGUAGE)
            ->where('type', '<>', 'admin')
            ->select(['type', 'name', 'language'])
            ->get();

        $byKey = [];
        foreach ($rows as $row) {
            $key = self::text($row, 'type') . "\0" . self::text($row, 'name');
            $byKey[$key][] = self::text($row, 'language');
        }

        foreach ($byKey as $key => $languages) {
            $languages = array_values(array_unique($languages));
            sort($languages);
            $byKey[$key] = $languages;
        }

        return $byKey;
    }

    /** `$type` é sempre o `type` do master (nunca `'admin'`, já filtrado a montante). */
    private function findTargetSibling(string $name, string $type, string $targetLanguage): mixed
    {
        return Capsule::table(self::TABLE)
            ->where('language', $targetLanguage)
            ->where('type', $type)
            ->where('name', $name)
            ->select(['id', 'subject', 'message'])
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, mixed>|null erro estruturado, ou `null` se o
     *     idioma-alvo for um dos literais suportados. Nenhuma consulta é
     *     feita antes desta checagem.
     */
    private function invalidTargetLanguage(string $targetLanguage): ?array
    {
        if (in_array($targetLanguage, self::SUPPORTED_TARGET_LANGUAGES, true)) {
            return null;
        }

        return [
            'result' => 'error',
            'error_code' => 'invalid_target_language',
            'message' => sprintf(
                'target_language deve ser um de: %s.',
                implode(', ', self::SUPPORTED_TARGET_LANGUAGES)
            ),
        ];
    }

    private static function rawValue(mixed $row, string $column): mixed
    {
        return is_array($row) ? ($row[$column] ?? null) : ($row->{$column} ?? null);
    }

    private static function text(mixed $row, string $column): string
    {
        $value = self::rawValue($row, $column);

        return $value === null ? '' : (string) $value;
    }

    private static function intOf(mixed $row, string $column): int
    {
        return (int) self::rawValue($row, $column);
    }
}
