<?php

declare(strict_types=1);

namespace NtMcp\Translation;

use NtMcp\Crm\CapsuleSchemaProbe;
use WHMCS\Database\Capsule;

/**
 * Fronteira ÚNICA entre as tools de tradução e `tblannouncements` (Fase 4).
 *
 * Modelo de linha-filha, igual `EmailTemplateRepository`: a linha original é
 * `parentid=0`, `language=''`; a variante EN é uma linha FILHA com
 * `parentid=<id original>` e `language=target_language`, com `date` e
 * `published` COPIADOS do original no insert (nunca no update).
 *
 * NOTA (desvio do spec original — ver
 * `docs/superpowers/specs/2026-09-18-translation-tools-design.md` §2/§3): o
 * plano original previa esta tool via LocalAPI (`AddAnnouncement`/
 * `UpdateAnnouncement`). Decisão revista nesta entrega: os comandos LocalAPI
 * de anúncio NÃO expõem `parentid`/`language` — não há como criar uma
 * variante de idioma através deles. Por isso a Fase 4 usa Capsule direto,
 * igual aos demais domínios deste addon.
 *
 * MODELO DE ARMAZENAMENTO A CONFIRMAR AO VIVO no desenv — ver
 * `whmcs_translation_status` (`content_variants`) para a confirmação real
 * contra o banco.
 *
 * Nenhum método aceita nome de tabela/coluna vindo de fora; `target_language`
 * só aceita os literais de `SUPPORTED_TARGET_LANGUAGES`, recusado ANTES de
 * qualquer consulta (inclusive antes do schema guard).
 */
final class AnnouncementRepository
{
    private const TABLE = TranslationSchema::TABLE_ANNOUNCEMENTS;

    /** Linha original (fonte). */
    public const SOURCE_PARENT_ID = 0;

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

    /**
     * Anúncios originais (`parentid=0`, `language=''`), com `title`, `date`,
     * `published`, um excerto de `announcement` e `has_target` para
     * `target_language`.
     *
     * @return array<string, mixed>
     */
    public function listAnnouncements(bool $onlyMissing, int $limit, int $offset, string $targetLanguage): array
    {
        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert(TranslationSchema::CAPABILITY_ANNOUNCEMENT);
        } catch (TranslationException $e) {
            return $e->toPublicArray();
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $originals = Capsule::table(self::TABLE)
            ->where('parentid', self::SOURCE_PARENT_ID)
            ->where('language', self::SOURCE_LANGUAGE)
            ->select(['id', 'title', 'announcement', 'date', 'published'])
            ->orderBy('id')
            ->get();

        $targetsByParent = $this->targetsByParentId($targetLanguage);

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
                'date' => self::text($row, 'date'),
                'published' => self::text($row, 'published'),
                'excerpt' => mb_substr(self::text($row, 'announcement'), 0, self::LIST_EXCERPT),
                'has_target' => $hasTarget,
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
     * Anúncios COMPLETOS por id (1–10), com a variante-alvo (ou `null`) e o
     * hash correspondente.
     *
     * @param array<int, int> $ids
     * @return array<string, mixed>
     */
    public function getAnnouncements(array $ids, string $targetLanguage): array
    {
        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert(TranslationSchema::CAPABILITY_ANNOUNCEMENT);
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
            ->select(['id', 'title', 'announcement', 'date', 'published', 'parentid', 'language'])
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
            if (self::intOf($row, 'parentid') !== self::SOURCE_PARENT_ID || self::text($row, 'language') !== self::SOURCE_LANGUAGE) {
                $items[] = ['id' => $id, 'error' => 'not_translatable'];
                continue;
            }

            $target = $this->findTarget($id, $targetLanguage);
            $items[] = [
                'id' => $id,
                'source' => [
                    'id' => $id,
                    'title' => self::text($row, 'title'),
                    'announcement' => self::text($row, 'announcement'),
                    'date' => self::text($row, 'date'),
                    'published' => self::text($row, 'published'),
                ],
                'target' => $target === null ? null : [
                    'id' => self::intOf($target, 'id'),
                    'title' => self::text($target, 'title'),
                    'announcement' => self::text($target, 'announcement'),
                ],
                'target_hash' => $this->hashOf($target),
            ];
        }

        return ['result' => 'success', 'target_language' => $targetLanguage, 'items' => $items];
    }

    public function hashOf(mixed $row): string
    {
        if ($row === null) {
            return 'absent';
        }

        return hash('sha256', self::text($row, 'title') . "\0" . self::text($row, 'announcement'));
    }

    /**
     * Lote de upserts, tudo-ou-nada, com hash otimista e `lockForUpdate()`.
     *
     * @param array<int, array{id?:mixed, title?:mixed, announcement?:mixed, expected_hash?:mixed}> $items
     * @return array<string, mixed>
     */
    public function applyBatch(array $items, TranslationBackup $backup, bool $dryRun, string $targetLanguage): array
    {
        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert(TranslationSchema::CAPABILITY_ANNOUNCEMENT);
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
     * @param array{id?:mixed, title?:mixed, announcement?:mixed, expected_hash?:mixed} $item
     * @return array<string, mixed>
     */
    private function applyOne(array $item, TranslationBackup $backup, bool $dryRun, string $targetLanguage): array
    {
        $id = (int) ($item['id'] ?? 0);
        $title = (string) ($item['title'] ?? '');
        $announcement = (string) ($item['announcement'] ?? '');
        $expectedHash = (string) ($item['expected_hash'] ?? '');

        if ($id <= 0) {
            return ['result' => 'error', 'error_code' => 'missing_id', 'message' => 'id é obrigatório em cada item.'];
        }

        $original = Capsule::table(self::TABLE)
            ->where('id', $id)
            ->where('parentid', self::SOURCE_PARENT_ID)
            ->where('language', self::SOURCE_LANGUAGE)
            ->select(['id', 'title', 'announcement', 'date', 'published'])
            ->lockForUpdate()
            ->first();

        if ($original === null) {
            return [
                'result' => 'error',
                'error_code' => 'source_not_found',
                'message' => "Anuncio original id {$id} inexistente ou nao-fonte.",
                'id' => $id,
            ];
        }

        $target = Capsule::table(self::TABLE)
            ->where('parentid', $id)
            ->where('language', $targetLanguage)
            ->select(['id', 'title', 'announcement'])
            ->lockForUpdate()
            ->first();

        $currentHash = $this->hashOf($target);
        if ($expectedHash !== $currentHash) {
            return [
                'result' => 'error',
                'error_code' => 'hash_conflict',
                'message' => "Conflito de edicao concorrente no id {$id}.",
                'id' => $id,
                'current_hash' => $currentHash,
            ];
        }

        $errors = $this->validator->validate(self::text($original, 'title'), self::text($original, 'announcement'), $title, $announcement);
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
            ? ['title', 'announcement']
            : array_values(array_filter([
                self::text($target, 'title') !== $title ? 'title' : null,
                self::text($target, 'announcement') !== $announcement ? 'announcement' : null,
            ]));

        if ($dryRun) {
            return [
                'id' => $id,
                'action' => $action,
                'current_hash' => $currentHash,
                'changed_fields' => $changedFields,
                'title_before' => $target === null ? null : self::text($target, 'title'),
                'title_after' => $title,
                'announcement_excerpt' => mb_substr($announcement, 0, self::DRY_RUN_EXCERPT),
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
                'announcement' => self::text($target, 'announcement'),
            ],
        ], 'announcement');

        if ($action === 'insert') {
            $newId = Capsule::table(self::TABLE)->insertGetId([
                'parentid' => $id,
                'language' => $targetLanguage,
                'title' => $title,
                'announcement' => $announcement,
                'date' => self::rawValue($original, 'date'),
                'published' => self::rawValue($original, 'published'),
            ]);

            return ['id' => $id, 'action' => 'insert', 'target_id' => $newId];
        }

        Capsule::table(self::TABLE)->where('id', self::intOf($target, 'id'))->update([
            'title' => $title,
            'announcement' => $announcement,
        ]);

        return ['id' => $id, 'action' => 'update', 'target_id' => self::intOf($target, 'id')];
    }

    /**
     * Ids de linha original que já têm variante em `$targetLanguage`.
     *
     * @return array<int, true> parentid => true
     */
    private function targetsByParentId(string $targetLanguage): array
    {
        $rows = Capsule::table(self::TABLE)
            ->where('language', $targetLanguage)
            ->select(['parentid'])
            ->get();

        $byParent = [];
        foreach ($rows as $row) {
            $byParent[self::intOf($row, 'parentid')] = true;
        }

        return $byParent;
    }

    private function findTarget(int $id, string $targetLanguage): mixed
    {
        return Capsule::table(self::TABLE)
            ->where('parentid', $id)
            ->where('language', $targetLanguage)
            ->select(['id', 'title', 'announcement'])
            ->first();
    }

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
