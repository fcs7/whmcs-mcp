<?php

declare(strict_types=1);

namespace NtMcp\Translation;

use NtMcp\Crm\CapsuleSchemaProbe;
use WHMCS\Database\Capsule;

/**
 * Fronteira ÚNICA entre as tools de tradução e `tblknowledgebase` /
 * `tblknowledgebasecats` (Fase 4). Única classe que toca as duas tabelas —
 * artigo e categoria de KB são domínios pequenos o bastante para compartilhar
 * o repositório (mesmo modelo de linha-filha, mesmas cinco proteções).
 *
 * Artigo: linha original `parentid=0`, `language=''`; variante EN é linha
 * filha `parentid=<id original>`, com `private`/`order` COPIADOS do original
 * no insert e `views`/`votes`/`useful` zerados (contadores próprios da
 * variante, nunca herdados).
 *
 * Categoria: linha original `catid=0`, `language=''`; variante EN é linha
 * filha `catid=<id original>`, com `parentid`/`hidden` COPIADOS do original
 * no insert. Sem tool de "get" para categoria — nome/descrição cabem na
 * listagem (mesmo corte de `product_group`).
 *
 * MODELO DE ARMAZENAMENTO A CONFIRMAR AO VIVO no desenv — ver
 * `whmcs_translation_status` (`content_variants`) para a confirmação real.
 *
 * Nenhum método aceita nome de tabela/coluna vindo de fora; `target_language`
 * só aceita os literais de `SUPPORTED_TARGET_LANGUAGES`, recusado ANTES de
 * qualquer consulta (inclusive antes do schema guard).
 */
final class KnowledgebaseRepository
{
    private const TABLE_ARTICLES = TranslationSchema::TABLE_KNOWLEDGEBASE;

    private const TABLE_CATS = TranslationSchema::TABLE_KNOWLEDGEBASE_CATS;

    public const SOURCE_LANGUAGE = '';

    /** Nesta fase, só inglês é aceito como idioma-alvo. */
    public const SUPPORTED_TARGET_LANGUAGES = ['english'];

    public const DEFAULT_TARGET_LANGUAGE = 'english';

    private const LIST_EXCERPT = 200;

    private const DRY_RUN_EXCERPT = 300;

    private TranslationSchemaGuard $guard;

    private TranslationValidator $validator;

    public function __construct(?TranslationSchemaGuard $guard = null, ?TranslationValidator $validator = null)
    {
        $this->guard = $guard ?? new TranslationSchemaGuard(new CapsuleSchemaProbe());
        $this->validator = $validator ?? new TranslationValidator();
    }

    // -----------------------------------------------------------
    // Artigos
    // -----------------------------------------------------------

    /** @return array<string, mixed> */
    public function listArticles(bool $onlyMissing, int $limit, int $offset, string $targetLanguage): array
    {
        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert(TranslationSchema::CAPABILITY_KB_ARTICLE);
        } catch (TranslationException $e) {
            return $e->toPublicArray();
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $originals = Capsule::table(self::TABLE_ARTICLES)
            ->where('parentid', 0)
            ->where('language', self::SOURCE_LANGUAGE)
            ->select(['id', 'title', 'article', 'private'])
            ->orderBy('id')
            ->get();

        $targetsByParent = $this->articleTargetsByParentId($targetLanguage);

        $items = [];
        foreach ($originals as $row) {
            $id = self::intOf($row, 'id');
            $hasTarget = isset($targetsByParent[$id]);
            if ($onlyMissing && $hasTarget) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'title' => self::text($row, 'title'),
                'excerpt' => mb_substr(self::text($row, 'article'), 0, self::LIST_EXCERPT),
                'has_target' => $hasTarget,
                'private' => self::text($row, 'private'),
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
     * @param array<int, int> $ids
     * @return array<string, mixed>
     */
    public function getArticles(array $ids, string $targetLanguage): array
    {
        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert(TranslationSchema::CAPABILITY_KB_ARTICLE);
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

        $rows = Capsule::table(self::TABLE_ARTICLES)
            ->whereIn('id', $ids)
            ->select(['id', 'title', 'article', 'parentid', 'language', 'private'])
            ->get();

        $byId = [];
        foreach ($rows as $row) {
            $byId[self::intOf($row, 'id')] = $row;
        }

        $items = [];
        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;
            if ($row === null) {
                $items[] = ['id' => $id, 'error' => 'not_found'];
                continue;
            }
            if (self::intOf($row, 'parentid') !== 0 || self::text($row, 'language') !== self::SOURCE_LANGUAGE) {
                $items[] = ['id' => $id, 'error' => 'not_translatable'];
                continue;
            }

            $target = $this->findArticleTarget($id, $targetLanguage);
            $items[] = [
                'id' => $id,
                'source' => [
                    'id' => $id,
                    'title' => self::text($row, 'title'),
                    'article' => self::text($row, 'article'),
                ],
                'private' => self::text($row, 'private'),
                'target' => $target === null ? null : [
                    'id' => self::intOf($target, 'id'),
                    'title' => self::text($target, 'title'),
                    'article' => self::text($target, 'article'),
                ],
                'target_hash' => $this->articleHashOf($target),
            ];
        }

        return ['result' => 'success', 'target_language' => $targetLanguage, 'items' => $items];
    }

    public function articleHashOf(mixed $row): string
    {
        if ($row === null) {
            return 'absent';
        }

        return hash('sha256', self::text($row, 'title') . "\0" . self::text($row, 'article'));
    }

    /**
     * @param array<int, array{id?:mixed, title?:mixed, article?:mixed, expected_hash?:mixed}> $items
     * @return array<string, mixed>
     */
    public function applyArticleBatch(array $items, TranslationBackup $backup, bool $dryRun, string $targetLanguage): array
    {
        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert(TranslationSchema::CAPABILITY_KB_ARTICLE);
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
                $outcome = $this->applyOneArticle($item, $backup, $dryRun, $targetLanguage);
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
     * @param array{id?:mixed, title?:mixed, article?:mixed, expected_hash?:mixed} $item
     * @return array<string, mixed>
     */
    private function applyOneArticle(array $item, TranslationBackup $backup, bool $dryRun, string $targetLanguage): array
    {
        $id = (int) ($item['id'] ?? 0);
        $title = (string) ($item['title'] ?? '');
        $article = (string) ($item['article'] ?? '');
        $expectedHash = (string) ($item['expected_hash'] ?? '');

        if ($id <= 0) {
            return ['result' => 'error', 'error_code' => 'missing_id', 'message' => 'id é obrigatório em cada item.'];
        }

        $original = Capsule::table(self::TABLE_ARTICLES)
            ->where('id', $id)
            ->where('parentid', 0)
            ->where('language', self::SOURCE_LANGUAGE)
            ->select(['id', 'title', 'article', 'private', 'order'])
            ->lockForUpdate()
            ->first();

        if ($original === null) {
            return [
                'result' => 'error',
                'error_code' => 'source_not_found',
                'message' => "Artigo original id {$id} inexistente ou nao-fonte.",
                'id' => $id,
            ];
        }

        $target = Capsule::table(self::TABLE_ARTICLES)
            ->where('parentid', $id)
            ->where('language', $targetLanguage)
            ->select(['id', 'title', 'article'])
            ->lockForUpdate()
            ->first();

        $currentHash = $this->articleHashOf($target);
        if ($expectedHash !== $currentHash) {
            return [
                'result' => 'error',
                'error_code' => 'hash_conflict',
                'message' => "Conflito de edicao concorrente no id {$id}.",
                'id' => $id,
                'current_hash' => $currentHash,
            ];
        }

        $errors = $this->validator->validate(self::text($original, 'title'), self::text($original, 'article'), $title, $article);
        if ($errors !== []) {
            return [
                'result' => 'error',
                'error_code' => 'validation_failed',
                'message' => "Validacao de conteudo falhou no id {$id}.",
                'id' => $id,
                'errors' => $errors,
            ];
        }

        $action = $target === null ? 'insert' : 'update';
        $changedFields = $target === null
            ? ['title', 'article']
            : array_values(array_filter([
                self::text($target, 'title') !== $title ? 'title' : null,
                self::text($target, 'article') !== $article ? 'article' : null,
            ]));

        if ($dryRun) {
            return [
                'id' => $id,
                'action' => $action,
                'current_hash' => $currentHash,
                'changed_fields' => $changedFields,
                'title_before' => $target === null ? null : self::text($target, 'title'),
                'title_after' => $title,
                'article_excerpt' => mb_substr($article, 0, self::DRY_RUN_EXCERPT),
            ];
        }

        $backup->append([
            'ts' => gmdate('c'),
            'admin' => null,
            'source_id' => $id,
            'action' => $action,
            'target_language' => $targetLanguage,
            'previous' => $target === null ? null : [
                'id' => self::intOf($target, 'id'),
                'title' => self::text($target, 'title'),
                'article' => self::text($target, 'article'),
            ],
        ], 'kb-article');

        if ($action === 'insert') {
            $newId = Capsule::table(self::TABLE_ARTICLES)->insertGetId([
                'parentid' => $id,
                'language' => $targetLanguage,
                'title' => $title,
                'article' => $article,
                'private' => self::rawValue($original, 'private'),
                'order' => self::rawValue($original, 'order'),
                'views' => 0,
                'votes' => 0,
                'useful' => 0,
            ]);

            return ['id' => $id, 'action' => 'insert', 'target_id' => $newId];
        }

        Capsule::table(self::TABLE_ARTICLES)->where('id', self::intOf($target, 'id'))->update([
            'title' => $title,
            'article' => $article,
        ]);

        return ['id' => $id, 'action' => 'update', 'target_id' => self::intOf($target, 'id')];
    }

    /** @return array<int, true> parentid => true */
    private function articleTargetsByParentId(string $targetLanguage): array
    {
        $rows = Capsule::table(self::TABLE_ARTICLES)
            ->where('language', $targetLanguage)
            ->select(['parentid'])
            ->get();

        $byParent = [];
        foreach ($rows as $row) {
            $byParent[self::intOf($row, 'parentid')] = true;
        }

        return $byParent;
    }

    private function findArticleTarget(int $id, string $targetLanguage): mixed
    {
        return Capsule::table(self::TABLE_ARTICLES)
            ->where('parentid', $id)
            ->where('language', $targetLanguage)
            ->select(['id', 'title', 'article'])
            ->first();
    }

    // -----------------------------------------------------------
    // Categorias
    // -----------------------------------------------------------

    /** @return array<string, mixed> */
    public function listCategories(bool $onlyMissing, int $limit, int $offset, string $targetLanguage): array
    {
        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert(TranslationSchema::CAPABILITY_KB_CATEGORY);
        } catch (TranslationException $e) {
            return $e->toPublicArray();
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $originals = Capsule::table(self::TABLE_CATS)
            ->where('catid', 0)
            ->where('language', self::SOURCE_LANGUAGE)
            ->select(['id', 'name', 'description'])
            ->orderBy('id')
            ->get();

        $targetsByCat = $this->categoryTargetsByCatId($targetLanguage);

        $items = [];
        foreach ($originals as $row) {
            $id = self::intOf($row, 'id');
            $target = $targetsByCat[$id] ?? null;
            $hasTarget = $target !== null;
            if ($onlyMissing && $hasTarget) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'name' => self::text($row, 'name'),
                'description' => self::text($row, 'description'),
                'target' => $hasTarget ? [
                    'name' => self::text($target, 'name'),
                    'description' => self::text($target, 'description'),
                ] : null,
                'has_target' => $hasTarget,
                'target_hash' => $this->categoryHashOf($target),
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

    public function categoryHashOf(mixed $row): string
    {
        if ($row === null) {
            return 'absent';
        }

        return hash('sha256', self::text($row, 'name') . "\0" . self::text($row, 'description'));
    }

    /**
     * @param array<int, array{id?:mixed, name?:mixed, description?:mixed, expected_hash?:mixed}> $items
     * @return array<string, mixed>
     */
    public function applyCategoryBatch(array $items, TranslationBackup $backup, bool $dryRun, string $targetLanguage): array
    {
        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert(TranslationSchema::CAPABILITY_KB_CATEGORY);
        } catch (TranslationException $e) {
            return $e->toPublicArray();
        }

        if ($items === [] || count($items) > 20) {
            return [
                'result' => 'error',
                'error_code' => 'invalid_items',
                'message' => 'items deve conter de 1 a 20 elementos.',
            ];
        }

        $connection = Capsule::connection();
        $connection->beginTransaction();

        try {
            $results = [];

            foreach ($items as $item) {
                $outcome = $this->applyOneCategory($item, $backup, $dryRun, $targetLanguage);
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
     * @param array{id?:mixed, name?:mixed, description?:mixed, expected_hash?:mixed} $item
     * @return array<string, mixed>
     */
    private function applyOneCategory(array $item, TranslationBackup $backup, bool $dryRun, string $targetLanguage): array
    {
        $id = (int) ($item['id'] ?? 0);
        $name = (string) ($item['name'] ?? '');
        $description = (string) ($item['description'] ?? '');
        $expectedHash = (string) ($item['expected_hash'] ?? '');

        if ($id <= 0) {
            return ['result' => 'error', 'error_code' => 'missing_id', 'message' => 'id é obrigatório em cada item.'];
        }

        $original = Capsule::table(self::TABLE_CATS)
            ->where('id', $id)
            ->where('catid', 0)
            ->where('language', self::SOURCE_LANGUAGE)
            ->select(['id', 'parentid', 'name', 'description', 'hidden'])
            ->lockForUpdate()
            ->first();

        if ($original === null) {
            return [
                'result' => 'error',
                'error_code' => 'source_not_found',
                'message' => "Categoria original id {$id} inexistente ou nao-fonte.",
                'id' => $id,
            ];
        }

        $target = Capsule::table(self::TABLE_CATS)
            ->where('catid', $id)
            ->where('language', $targetLanguage)
            ->select(['id', 'name', 'description'])
            ->lockForUpdate()
            ->first();

        $currentHash = $this->categoryHashOf($target);
        if ($expectedHash !== $currentHash) {
            return [
                'result' => 'error',
                'error_code' => 'hash_conflict',
                'message' => "Conflito de edicao concorrente no id {$id}.",
                'id' => $id,
                'current_hash' => $currentHash,
            ];
        }

        $errors = $this->validator->validate(self::text($original, 'name'), self::text($original, 'description'), $name, $description);
        if ($errors !== []) {
            return [
                'result' => 'error',
                'error_code' => 'validation_failed',
                'message' => "Validacao de conteudo falhou no id {$id}.",
                'id' => $id,
                'errors' => $errors,
            ];
        }

        $action = $target === null ? 'insert' : 'update';
        $changedFields = $target === null
            ? ['name', 'description']
            : array_values(array_filter([
                self::text($target, 'name') !== $name ? 'name' : null,
                self::text($target, 'description') !== $description ? 'description' : null,
            ]));

        if ($dryRun) {
            return [
                'id' => $id,
                'action' => $action,
                'current_hash' => $currentHash,
                'changed_fields' => $changedFields,
                'name_before' => $target === null ? null : self::text($target, 'name'),
                'name_after' => $name,
                'description_excerpt' => mb_substr($description, 0, self::DRY_RUN_EXCERPT),
            ];
        }

        $backup->append([
            'ts' => gmdate('c'),
            'admin' => null,
            'source_id' => $id,
            'action' => $action,
            'target_language' => $targetLanguage,
            'previous' => $target === null ? null : [
                'id' => self::intOf($target, 'id'),
                'name' => self::text($target, 'name'),
                'description' => self::text($target, 'description'),
            ],
        ], 'kb-category');

        if ($action === 'insert') {
            $newId = Capsule::table(self::TABLE_CATS)->insertGetId([
                'catid' => $id,
                'language' => $targetLanguage,
                'name' => $name,
                'description' => $description,
                'parentid' => self::rawValue($original, 'parentid'),
                'hidden' => self::rawValue($original, 'hidden'),
            ]);

            return ['id' => $id, 'action' => 'insert', 'target_id' => $newId];
        }

        Capsule::table(self::TABLE_CATS)->where('id', self::intOf($target, 'id'))->update([
            'name' => $name,
            'description' => $description,
        ]);

        return ['id' => $id, 'action' => 'update', 'target_id' => self::intOf($target, 'id')];
    }

    /** @return array<int, object> catid => row */
    private function categoryTargetsByCatId(string $targetLanguage): array
    {
        $rows = Capsule::table(self::TABLE_CATS)
            ->where('language', $targetLanguage)
            ->select(['catid', 'name', 'description'])
            ->get();

        $byCat = [];
        foreach ($rows as $row) {
            $byCat[self::intOf($row, 'catid')] = $row;
        }

        return $byCat;
    }

    // -----------------------------------------------------------
    // Comum
    // -----------------------------------------------------------

    /**
     * @return array<string, mixed>|null erro estruturado, ou `null` se o
     *     idioma-alvo for um dos literais suportados.
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
