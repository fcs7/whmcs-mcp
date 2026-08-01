<?php

declare(strict_types=1);

namespace NtMcp\Whmcs;

/** Fronteira única e fechada para `logActivity()`. */
final class ActivityLog
{
    public static function record(
        ActivityEvent $event,
        ?AuditMetadata $metadata = null,
        ?string $correlationId = null,
        ?string $command = null,
    ): string {
        $correlationId = self::validCorrelation($correlationId)
            ? $correlationId
            : Diagnostics::newCorrelationId();

        $parts = [
            '[NT-MCP]',
            "[corr:{$correlationId}]",
            $event->value,
        ];

        // D7: o comando só pode aparecer depois de pertencer à allowlist
        // canônica. Token desconhecido é simplesmente omitido.
        if ($command !== null && LocalApiClient::isAllowedCommand($command)) {
            $parts[] = 'command=' . $command;
        }

        $summary = json_encode(
            (object) self::renderMetadata($metadata?->toArray() ?? []),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $parts[] = 'meta: ' . ($summary === false ? '{}' : $summary);

        try {
            if (function_exists('logActivity')) {
                logActivity(implode(' ', $parts));
            }
        } catch (\Throwable $e) {
            // Não repassa evento, metadata nem payload para o sink alternativo.
            Diagnostics::log($correlationId, Diagnostics::CATEGORY_AUDIT_SINK, 'activity_log', $e);
        }

        return $correlationId;
    }

    private static function validCorrelation(?string $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}\z/', $value) === 1;
    }

    /** Segunda barreira contra value object forjado por reflection/unserialize. */
    private static function renderMetadata(array $shape, int $depth = 0): array
    {
        if ($depth > 1) {
            return [];
        }

        $safe = [];
        foreach ($shape as $key => $value) {
            if ($key === 'ids' && is_array($value)) {
                $ids = [];
                foreach ($value as $field => $id) {
                    if (AuditMetadata::isIdField((string) $field) && is_int($id)) {
                        $ids[(string) $field] = $id;
                    }
                }
                if ($ids !== []) $safe['ids'] = $ids;
            } elseif ($key === 'flags' && is_array($value)) {
                $flags = [];
                foreach ($value as $field => $flag) {
                    if (AuditMetadata::isFlagField((string) $field) && is_bool($flag)) {
                        $flags[(string) $field] = $flag;
                    }
                }
                if ($flags !== []) $safe['flags'] = $flags;
            } elseif ($key === 'fields' && is_array($value)) {
                $fields = array_values(array_filter(
                    $value,
                    static fn(mixed $field): bool => is_string($field) && AuditMetadata::isKnownField($field)
                ));
                if ($fields !== []) $safe['fields'] = $fields;
            } elseif ($key === 'counts' && is_array($value)) {
                $counts = [];
                foreach ($value as $field => $count) {
                    if (AuditMetadata::isKnownField((string) $field) && is_int($count)) {
                        $counts[(string) $field] = $count;
                    }
                }
                if ($counts !== []) $safe['counts'] = $counts;
            } elseif (in_array($key, ['unknown_fields', 'limit', 'offset'], true) && is_int($value)) {
                $safe[$key] = $value;
            } elseif (in_array($key, ['where', 'data'], true) && is_array($value)) {
                $nested = self::renderMetadata($value, $depth + 1);
                if ($nested !== []) $safe[$key] = $nested;
            }
        }

        return $safe;
    }
}
