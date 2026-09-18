<?php

declare(strict_types=1);

namespace NtMcp\Translation;

use NtMcp\Crm\CapsuleSchemaProbe;
use WHMCS\Database\Capsule;

/**
 * Fronteira ÚNICA entre as tools de tradução e `tblemailtemplates`.
 *
 * PREMISSA (a validar no desenv, ver plano — "Verificação", passo 0): o master
 * (idioma-fonte) é `language=''` (PT, o padrão de instalação do WHMCS) e o
 * idioma-alvo desta fase é `'english'`. Se a instalação real inverter isso
 * (admin traduziu criando linhas `portuguese-br` sobre um master já em
 * inglês), as DUAS constantes trocam de valor — o mecanismo de upsert por
 * `name` é o mesmo nos dois casos.
 *
 * Nenhum método aceita nome de tabela ou coluna vindo de fora: todo acesso
 * usa as constantes de `TranslationSchema`. Nenhuma consulta acontece antes
 * do schema guard.
 */
final class EmailTemplateRepository
{
    private const TABLE = TranslationSchema::TABLE_EMAIL_TEMPLATES;

    /** Idioma do template master (fonte). */
    public const SOURCE_LANGUAGE = '';

    /** Idioma-alvo da Fase 1. */
    public const TARGET_LANGUAGE = 'english';

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
     * Masters (idioma-fonte, `type<>'admin'`), com `has_en` calculado pela
     * existência de um irmão de mesmo `name` no idioma-alvo.
     *
     * @return array<string, mixed>
     */
    public function listMasters(?string $type, bool $onlyMissing, int $limit, int $offset): array
    {
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
        $enNames = $this->englishNames();

        $items = [];
        foreach ($masters as $row) {
            $name = self::text($row, 'name');
            $hasEn = in_array($name, $enNames, true);
            if ($onlyMissing && $hasEn) {
                continue;
            }
            $items[] = [
                'id' => self::intOf($row, 'id'),
                'type' => self::text($row, 'type'),
                'name' => $name,
                'subject' => self::text($row, 'subject'),
                'has_en' => $hasEn,
            ];
        }

        $total = count($items);
        $page = array_slice($items, $offset, $limit);

        return [
            'result' => 'success',
            'items' => $page,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + count($page)) < $total,
        ];
    }

    /**
     * PT completo + EN (ou null) + `en_hash`, por id (1–10). Id inexistente,
     * `admin` ou não-fonte devolve erro por item — sem interromper o lote.
     *
     * @param array<int, int> $ids
     * @return array<string, mixed>
     */
    public function getPairs(array $ids): array
    {
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

            $en = $this->findEnglishSibling(self::text($row, 'name'));
            $pairs[] = [
                'id' => $id,
                'source' => [
                    'id' => self::intOf($row, 'id'),
                    'type' => self::text($row, 'type'),
                    'name' => self::text($row, 'name'),
                    'subject' => self::text($row, 'subject'),
                    'message' => self::text($row, 'message'),
                ],
                'en' => $en === null ? null : [
                    'id' => self::intOf($en, 'id'),
                    'subject' => self::text($en, 'subject'),
                    'message' => self::text($en, 'message'),
                ],
                'en_hash' => $this->hashOf($en),
            ];
        }

        return ['result' => 'success', 'pairs' => $pairs];
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

        $rows = Capsule::table(self::TABLE)
            ->where('type', '<>', 'admin')
            ->select(['language', 'subject'])
            ->get();

        $byLanguage = [];
        $sampleSubjects = [];
        foreach ($rows as $row) {
            $language = self::text($row, 'language');
            $byLanguage[$language] = ($byLanguage[$language] ?? 0) + 1;
            if ($language === self::SOURCE_LANGUAGE && count($sampleSubjects) < 3) {
                $sampleSubjects[] = self::text($row, 'subject');
            }
        }
        ksort($byLanguage);

        return [
            'result' => 'success',
            'source_language' => self::SOURCE_LANGUAGE,
            'target_language' => self::TARGET_LANGUAGE,
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
    public function applyBatch(array $items, TranslationBackup $backup, bool $dryRun): array
    {
        try {
            $this->guard->assert();
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
                $outcome = $this->applyOne($item, $backup, $dryRun);
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

            return ['result' => 'success', 'dry_run' => $dryRun, 'items' => $results];
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * @param array{id?:mixed, subject?:mixed, message?:mixed, expected_hash?:mixed} $item
     * @return array<string, mixed>
     */
    private function applyOne(array $item, TranslationBackup $backup, bool $dryRun): array
    {
        $id = (int) ($item['id'] ?? 0);
        $subject = (string) ($item['subject'] ?? '');
        $message = (string) ($item['message'] ?? '');
        $expectedHash = (string) ($item['expected_hash'] ?? '');

        if ($id <= 0) {
            return ['result' => 'error', 'error_code' => 'missing_id', 'message' => 'id é obrigatório em cada item.'];
        }

        $master = Capsule::table(self::TABLE)
            ->where('id', $id)
            ->where('type', '<>', 'admin')
            ->where('language', self::SOURCE_LANGUAGE)
            ->select(array_merge(['id', 'subject', 'message'], self::COPIED_COLUMNS))
            ->lockForUpdate()
            ->first();

        if ($master === null) {
            return [
                'result' => 'error',
                'error_code' => 'master_not_found',
                'message' => "Master id {$id} inexistente, admin ou nao-fonte.",
                'id' => $id,
            ];
        }

        $en = Capsule::table(self::TABLE)
            ->where('language', self::TARGET_LANGUAGE)
            ->where('type', '<>', 'admin')
            ->where('name', self::text($master, 'name'))
            ->select(['id', 'subject', 'message'])
            ->lockForUpdate()
            ->first();

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
            'previous' => $en === null ? null : [
                'id' => self::intOf($en, 'id'),
                'subject' => self::text($en, 'subject'),
                'message' => self::text($en, 'message'),
            ],
        ]);

        if ($action === 'insert') {
            $data = ['language' => self::TARGET_LANGUAGE, 'subject' => $subject, 'message' => $message];
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

    /** @return array<int, string> */
    private function englishNames(): array
    {
        $rows = Capsule::table(self::TABLE)
            ->where('language', self::TARGET_LANGUAGE)
            ->where('type', '<>', 'admin')
            ->select(['name'])
            ->get();

        $names = [];
        foreach ($rows as $row) {
            $names[] = self::text($row, 'name');
        }

        return $names;
    }

    private function findEnglishSibling(string $name): mixed
    {
        return Capsule::table(self::TABLE)
            ->where('language', self::TARGET_LANGUAGE)
            ->where('type', '<>', 'admin')
            ->where('name', $name)
            ->select(['id', 'subject', 'message'])
            ->first();
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
