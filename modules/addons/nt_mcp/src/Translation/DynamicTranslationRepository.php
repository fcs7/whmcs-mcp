<?php

declare(strict_types=1);

namespace NtMcp\Translation;

use NtMcp\Crm\CapsuleSchemaProbe;
use NtMcp\Crm\CrmSchemaProbe;
use WHMCS\Database\Capsule;

/**
 * Fronteira ÚNICA entre as tools de tradução e `tbldynamic_translations`
 * (Fase 2: produto e grupo de produto — `DynamicTranslationMap` é o mapa
 * fechado de kind/field; Fase 3 só acrescenta entradas lá, não aqui).
 *
 * Mesmo espírito de `EmailTemplateRepository`: nenhum método aceita nome de
 * tabela/coluna vindo de fora, `target_language` só aceita os literais de
 * `SUPPORTED_TARGET_LANGUAGES` (recusado ANTES de qualquer consulta, inclusive
 * antes do schema guard), e toda escrita passa por transação única + hash
 * otimista com `lockForUpdate()` + backup ANTES do insert/update.
 *
 * Diferença de desenho: aqui a linha fonte (`tblproducts`/`tblproductgroups`)
 * NUNCA é escrita — só lida (inclusive sob lock, para o check-then-insert) —,
 * e o alvo é sempre uma linha NOVA ou existente em `tbldynamic_translations`,
 * nunca uma linha irmã na mesma tabela fonte.
 */
final class DynamicTranslationRepository
{
    private const TABLE = TranslationSchema::TABLE_DYNAMIC_TRANSLATIONS;

    /** Nesta fase, só inglês é aceito como idioma-alvo. */
    public const SUPPORTED_TARGET_LANGUAGES = ['english'];

    public const DEFAULT_TARGET_LANGUAGE = 'english';

    private const MAX_LIST_LIMIT = 100;

    private const MAX_GET_IDS = 10;

    private const MAX_BATCH_ITEMS = 20;

    /** Truncamento de `description` (textarea) na LISTAGEM — não no `get`. */
    private const LIST_TEXTAREA_EXCERPT = 200;

    /** Truncamento de before/after no preview de dry-run. */
    private const DRY_RUN_EXCERPT = 200;

    private TranslationSchemaGuard $guard;

    private TranslationValidator $validator;

    private CrmSchemaProbe $probe;

    public function __construct(
        ?TranslationSchemaGuard $guard = null,
        ?TranslationValidator $validator = null,
        ?CrmSchemaProbe $probe = null
    ) {
        $this->probe = $probe ?? new CapsuleSchemaProbe();
        $this->guard = $guard ?? new TranslationSchemaGuard($this->probe);
        $this->validator = $validator ?? new TranslationValidator();
    }

    /**
     * Lista entidades de `$kind` com o texto-fonte por campo, `has_target` e
     * `target_hash` ('absent' quando a tradução ainda não existe). `$gid`
     * filtra por grupo — só se aplica a `KIND_PRODUCT` (`null`/`<=0` ignora o
     * filtro; para `KIND_PRODUCT_GROUP` o parâmetro é sempre ignorado).
     * `description` (textarea) vem TRUNCADA a 200 chars nesta listagem — texto
     * completo é responsabilidade de `getEntities()`.
     *
     * `$customFieldType` filtra `tblcustomfields.type` — só se aplica a
     * `KIND_CUSTOM_FIELD` (ignorado para os demais kinds); `null`/`''` não
     * filtra. `KIND_CUSTOM_FIELD` também exclui, SEMPRE, campos admin-only
     * (`adminonly` não vazio) — não aparecem para o cliente, não fazem
     * sentido traduzir.
     *
     * @return array<string, mixed>
     */
    public function listEntities(
        string $kind,
        ?int $gid,
        bool $onlyMissing,
        int $limit,
        int $offset,
        string $targetLanguage,
        ?string $customFieldType = null
    ): array {
        if (!DynamicTranslationMap::isValidKind($kind)) {
            return self::invalidKind($kind);
        }

        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert(DynamicTranslationMap::capabilityFor($kind));
        } catch (TranslationException $e) {
            return $e->toPublicArray();
        }

        $limit = max(1, min(self::MAX_LIST_LIMIT, $limit));
        $offset = max(0, $offset);

        $fields = DynamicTranslationMap::fields($kind);
        $isProduct = $kind === DynamicTranslationMap::KIND_PRODUCT;
        $isCustomField = $kind === DynamicTranslationMap::KIND_CUSTOM_FIELD;

        $sourceColumns = array_map(
            static fn(string $field): string => DynamicTranslationMap::sourceColumn($kind, $field),
            array_keys($fields)
        );
        $columns = array_values(array_unique(array_merge(['id'], self::extraSelectColumns($kind), $sourceColumns)));

        $query = Capsule::table(DynamicTranslationMap::sourceTable($kind));
        if ($isProduct && $gid !== null && $gid > 0) {
            $query = $query->where('gid', $gid);
        }
        if ($isCustomField) {
            // NULL não bate em `= ''` no MySQL real (a comparação com NULL
            // nunca é verdadeira) — o fake aceitava por acidente (`==` solto).
            // `orWhereNull()` cobre explicitamente as duas formas de "sem
            // admin-only": string vazia OU coluna nula.
            $query = $query->where(function ($subquery) {
                $subquery->where('adminonly', '')->orWhereNull('adminonly');
            });
            if ($customFieldType !== null && $customFieldType !== '') {
                $query = $query->where('type', $customFieldType);
            }
        }
        $sourceRows = $query->select($columns)->orderBy('id')->get();

        $targetByFieldId = $this->targetsByFieldAndId($kind, $fields, $targetLanguage);

        $items = [];
        foreach ($sourceRows as $row) {
            $id = self::intOf($row, 'id');
            $fieldsSummary = [];
            $hasMissing = false;

            foreach ($fields as $field => $inputType) {
                $sourceText = self::text($row, DynamicTranslationMap::sourceColumn($kind, $field));
                $translation = $targetByFieldId[$field][$id] ?? null;
                $hasTarget = $translation !== null;
                if ($sourceText !== '' && !$hasTarget) {
                    $hasMissing = true;
                }

                $fieldsSummary[$field] = [
                    'source' => $inputType === 'textarea' ? mb_substr($sourceText, 0, self::LIST_TEXTAREA_EXCERPT) : $sourceText,
                    'has_target' => $hasTarget,
                    'target_hash' => $hasTarget ? hash('sha256', (string) $translation) : 'absent',
                ];
            }

            if ($onlyMissing && !$hasMissing) {
                continue;
            }

            $items[] = ['id' => $id, 'fields' => $fieldsSummary] + self::extraListPayload($kind, $row);
        }

        $total = count($items);
        $page = array_slice($items, $offset, $limit);

        return [
            'result' => 'success',
            'kind' => $kind,
            'target_language' => $targetLanguage,
            'items' => $page,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + count($page)) < $total,
        ];
    }

    /**
     * Texto-fonte COMPLETO por campo + tradução atual (ou `null`) + hash, por
     * id (1–10). Id inexistente devolve erro por item, sem interromper o lote.
     *
     * @param array<int, int> $ids
     * @return array<string, mixed>
     */
    public function getEntities(string $kind, array $ids, string $targetLanguage): array
    {
        if (!DynamicTranslationMap::isValidKind($kind)) {
            return self::invalidKind($kind);
        }

        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert(DynamicTranslationMap::capabilityFor($kind));
        } catch (TranslationException $e) {
            return $e->toPublicArray();
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > self::MAX_GET_IDS) {
            return [
                'result' => 'error',
                'error_code' => 'invalid_ids',
                'message' => sprintf('ids deve conter de 1 a %d inteiros.', self::MAX_GET_IDS),
            ];
        }

        $fields = DynamicTranslationMap::fields($kind);
        $isProduct = $kind === DynamicTranslationMap::KIND_PRODUCT;
        $sourceColumns = array_map(
            static fn(string $field): string => DynamicTranslationMap::sourceColumn($kind, $field),
            array_keys($fields)
        );
        $columns = array_values(array_unique(array_merge(['id'], $isProduct ? ['gid'] : [], $sourceColumns)));

        $rows = Capsule::table(DynamicTranslationMap::sourceTable($kind))
            ->whereIn('id', $ids)
            ->select($columns)
            ->get();

        $byId = [];
        foreach ($rows as $row) {
            $byId[self::intOf($row, 'id')] = $row;
        }

        $targetByFieldId = $this->targetsByFieldAndId($kind, $fields, $targetLanguage);

        $entities = [];
        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;
            if ($row === null) {
                $entities[] = ['id' => $id, 'error' => 'not_found'];
                continue;
            }

            $fieldsPayload = [];
            foreach ($fields as $field => $inputType) {
                $translation = $targetByFieldId[$field][$id] ?? null;
                $fieldsPayload[$field] = [
                    'source' => self::text($row, DynamicTranslationMap::sourceColumn($kind, $field)),
                    'target' => $translation,
                    'target_hash' => $translation === null ? 'absent' : hash('sha256', (string) $translation),
                ];
            }

            $entity = ['id' => $id, 'fields' => $fieldsPayload];
            if ($isProduct) {
                $entity['gid'] = self::intOf($row, 'gid');
            }
            $entities[] = $entity;
        }

        return ['result' => 'success', 'kind' => $kind, 'target_language' => $targetLanguage, 'items' => $entities];
    }

    /**
     * Lote de upserts em `tbldynamic_translations`, tudo-ou-nada.
     *
     * `$dryRun=true` monta o preview e nunca grava (rollback ao final, mesmo
     * sem erro). `$dryRun=false` grava o backup ANTES do insert/update de cada
     * item, dentro da MESMA transação — se o backup lançar, a transação
     * inteira é revertida e a exceção sobe (não é erro de domínio).
     *
     * @param array<int, array{id?:mixed, field?:mixed, text?:mixed, expected_hash?:mixed}> $items
     * @return array<string, mixed>
     */
    public function applyBatch(string $kind, array $items, TranslationBackup $backup, bool $dryRun, string $targetLanguage): array
    {
        if (!DynamicTranslationMap::isValidKind($kind)) {
            return self::invalidKind($kind);
        }

        $invalidTarget = $this->invalidTargetLanguage($targetLanguage);
        if ($invalidTarget !== null) {
            return $invalidTarget;
        }

        try {
            $this->guard->assert(DynamicTranslationMap::capabilityFor($kind));
            $this->guard->assertInnoDb(self::TABLE);
        } catch (TranslationException $e) {
            return $e->toPublicArray();
        }

        if ($items === [] || count($items) > self::MAX_BATCH_ITEMS) {
            return [
                'result' => 'error',
                'error_code' => 'invalid_items',
                'message' => sprintf('items deve conter de 1 a %d elementos.', self::MAX_BATCH_ITEMS),
            ];
        }

        $duplicate = self::firstDuplicate($items);
        if ($duplicate !== null) {
            return [
                'result' => 'error',
                'error_code' => 'duplicate_item',
                'message' => "Item duplicado no lote: id {$duplicate['id']}, field {$duplicate['field']}.",
            ];
        }

        $connection = Capsule::connection();
        $connection->beginTransaction();

        try {
            $results = [];

            foreach ($items as $item) {
                $outcome = $this->applyOne($kind, $item, $backup, $dryRun, $targetLanguage);
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

            return ['result' => 'success', 'dry_run' => $dryRun, 'kind' => $kind, 'target_language' => $targetLanguage, 'items' => $results];
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * @param array{id?:mixed, field?:mixed, text?:mixed, expected_hash?:mixed} $item
     * @return array<string, mixed>
     */
    private function applyOne(string $kind, array $item, TranslationBackup $backup, bool $dryRun, string $targetLanguage): array
    {
        $id = (int) ($item['id'] ?? 0);
        $field = (string) ($item['field'] ?? '');
        $text = (string) ($item['text'] ?? '');
        $expectedHash = (string) ($item['expected_hash'] ?? '');

        if ($id <= 0) {
            return ['result' => 'error', 'error_code' => 'missing_id', 'message' => 'id é obrigatório em cada item.'];
        }

        if (!DynamicTranslationMap::isValidField($kind, $field)) {
            return [
                'result' => 'error',
                'error_code' => 'invalid_field',
                'message' => "Field '{$field}' invalido para o kind '{$kind}'.",
                'id' => $id,
            ];
        }

        $sourceColumn = DynamicTranslationMap::sourceColumn($kind, $field);
        $sourceQuery = Capsule::table(DynamicTranslationMap::sourceTable($kind))
            ->where('id', $id)
            ->select(['id', $sourceColumn]);
        // Dry-run nunca segura lock de linha — só leitura, sem tudo-ou-nada real.
        if (!$dryRun) {
            $sourceQuery = $sourceQuery->lockForUpdate();
        }
        $sourceRow = $sourceQuery->first();

        if ($sourceRow === null) {
            return [
                'result' => 'error',
                'error_code' => 'source_not_found',
                'message' => "Fonte id {$id} inexistente para o kind '{$kind}'.",
                'id' => $id,
            ];
        }

        $sourceText = self::text($sourceRow, $sourceColumn);
        if (trim($sourceText) === '') {
            return [
                'result' => 'error',
                'error_code' => 'source_empty',
                'message' => "Campo fonte '{$field}' vazio no id {$id}; nada para traduzir.",
                'id' => $id,
                'field' => $field,
            ];
        }

        $relatedType = DynamicTranslationMap::relatedType($kind, $field);
        $existingQuery = Capsule::table(self::TABLE)
            ->where('related_type', $relatedType)
            ->where('related_id', $id)
            ->where('language', $targetLanguage)
            ->select(['id', 'translation'])
            ->orderBy('id');
        if (!$dryRun) {
            $existingQuery = $existingQuery->lockForUpdate();
        }
        $existing = $existingQuery->first();

        $currentHash = $existing === null ? 'absent' : hash('sha256', self::text($existing, 'translation'));
        if ($expectedHash !== $currentHash) {
            return [
                'result' => 'error',
                'error_code' => 'hash_conflict',
                'message' => "Conflito de edicao concorrente no id {$id}, field {$field}.",
                'id' => $id,
                'field' => $field,
                'current_hash' => $currentHash,
            ];
        }

        $inputType = DynamicTranslationMap::inputType($kind, $field);
        $errors = $this->validator->validateField($sourceText, $text, $inputType);
        if ($errors !== []) {
            return [
                'result' => 'error',
                'error_code' => 'validation_failed',
                'message' => "Validacao de conteudo falhou no id {$id}, field {$field}.",
                'id' => $id,
                'field' => $field,
                'errors' => $errors,
            ];
        }

        $action = $existing === null ? 'insert' : 'update';

        if ($dryRun) {
            return [
                'id' => $id,
                'field' => $field,
                'action' => $action,
                'current_hash' => $currentHash,
                'before' => $existing === null ? null : mb_substr(self::text($existing, 'translation'), 0, self::DRY_RUN_EXCERPT),
                'after' => mb_substr($text, 0, self::DRY_RUN_EXCERPT),
            ];
        }

        // Backup do estado ANTERIOR gravado ANTES da escrita — se lançar,
        // `applyBatch()` reverte a transação inteira.
        $backup->append([
            'ts' => gmdate('c'),
            'admin' => null,
            'kind' => $kind,
            'id' => $id,
            'field' => $field,
            'related_type' => $relatedType,
            'action' => $action,
            'target_language' => $targetLanguage,
            'previous' => $existing === null ? null : self::text($existing, 'translation'),
        ], 'dynamic-' . $kind);

        if ($action === 'insert') {
            $data = [
                'related_type' => $relatedType,
                'related_id' => $id,
                'language' => $targetLanguage,
                'translation' => $text,
                'input_type' => $inputType,
            ];
            if ($this->hasTimestampColumn('created_at')) {
                $data['created_at'] = self::now();
            }
            if ($this->hasTimestampColumn('updated_at')) {
                $data['updated_at'] = self::now();
            }
            $newId = Capsule::table(self::TABLE)->insertGetId($data);

            return ['id' => $id, 'field' => $field, 'action' => 'insert', 'translation_id' => $newId];
        }

        $updateData = ['translation' => $text];
        if ($this->hasTimestampColumn('updated_at')) {
            $updateData['updated_at'] = self::now();
        }
        Capsule::table(self::TABLE)->where('id', self::intOf($existing, 'id'))->update($updateData);

        return ['id' => $id, 'field' => $field, 'action' => 'update', 'translation_id' => self::intOf($existing, 'id')];
    }

    /**
     * Todas as traduções já gravadas para os campos de `$kind` no idioma
     * `$targetLanguage`, indexadas por campo e `related_id` — UMA consulta
     * para toda a listagem/lote, em vez de N consultas por entidade.
     *
     * @param array<string, string> $fields field => input_type
     * @return array<string, array<int, string>> field => [related_id => translation]
     */
    private function targetsByFieldAndId(string $kind, array $fields, string $targetLanguage): array
    {
        $literalToField = [];
        foreach (array_keys($fields) as $field) {
            $literalToField[DynamicTranslationMap::relatedType($kind, $field)] = $field;
        }

        if ($literalToField === []) {
            return [];
        }

        $rows = Capsule::table(self::TABLE)
            ->whereIn('related_type', array_keys($literalToField))
            ->where('language', $targetLanguage)
            ->select(['related_type', 'related_id', 'translation'])
            ->orderBy('id')
            ->get();

        $byFieldId = [];
        foreach ($rows as $row) {
            $field = $literalToField[self::text($row, 'related_type')] ?? null;
            if ($field === null) {
                continue;
            }
            $relatedId = self::intOf($row, 'related_id');
            // Duplicata (não deveria existir, mas se existir): mantém a de
            // menor id, já garantida pelo `orderBy('id')` acima — determinístico
            // em vez de depender da ordem natural de retorno do driver.
            if (isset($byFieldId[$field][$relatedId])) {
                continue;
            }
            $byFieldId[$field][$relatedId] = self::text($row, 'translation');
        }

        return $byFieldId;
    }

    private function hasTimestampColumn(string $column): bool
    {
        return $this->probe->hasColumn(self::TABLE, $column)->isPresent();
    }

    /**
     * Colunas RAW da tabela fonte, além de `id` e dos campos traduzíveis,
     * necessárias para filtrar (`custom_field`) e/ou compor o item de lista.
     *
     * @return array<int, string>
     */
    private static function extraSelectColumns(string $kind): array
    {
        return match ($kind) {
            DynamicTranslationMap::KIND_PRODUCT => ['gid', 'hidden', 'retired'],
            DynamicTranslationMap::KIND_CUSTOM_FIELD => ['type', 'relid', 'adminonly'],
            default => ['hidden'],
        };
    }

    /**
     * Campos extras do item de listagem (além de `id`/`fields`), específicos
     * por kind.
     *
     * @return array<string, mixed>
     */
    private static function extraListPayload(string $kind, mixed $row): array
    {
        return match ($kind) {
            DynamicTranslationMap::KIND_PRODUCT => [
                'gid' => self::intOf($row, 'gid'),
                'hidden' => self::text($row, 'hidden'),
                'retired' => self::text($row, 'retired'),
            ],
            DynamicTranslationMap::KIND_CUSTOM_FIELD => [
                'type' => self::text($row, 'type'),
                'relid' => self::intOf($row, 'relid'),
            ],
            default => ['hidden' => self::text($row, 'hidden')],
        };
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * @param array<int, array{id?:mixed, field?:mixed}> $items
     * @return array{id:mixed, field:mixed}|null
     */
    private static function firstDuplicate(array $items): ?array
    {
        $seen = [];
        foreach ($items as $item) {
            $key = (string) ($item['id'] ?? '') . "\0" . (string) ($item['field'] ?? '');
            if (isset($seen[$key])) {
                return ['id' => $item['id'] ?? null, 'field' => $item['field'] ?? null];
            }
            $seen[$key] = true;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function invalidKind(string $kind): array
    {
        return [
            'result' => 'error',
            'error_code' => 'invalid_kind',
            'message' => sprintf('kind deve ser um de: %s.', implode(', ', DynamicTranslationMap::kinds())),
        ];
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
