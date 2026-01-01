<?php
declare(strict_types=1);

namespace Glued\Lib\Classes\Sql2;

/**
 * Example transformer template.
 *
 * Goals:
 * - Works for 1->1, 1->N, N->1 (via multiple src[] in baked meta) without collisions.
 * - Enforces idempotency by providing either:
 *     a) 'uuid' (stable), OR
 *     b) ('kind' + 'key') so the pipeline derives a stable uuid.
 *
 * Notes:
 * - Prefer ('kind' + 'key') unless you already have a well-defined stable uuid scheme.
 * - 'kind' should be a small fixed vocabulary (e.g. "packset", "instrument", "customer", "order").
 * - 'key' should be stable business identity inside that kind (extId, compound key, etc.).
 */
final class ExampleTransformer implements TransformerInterface
{
    public function transform(array $rawDoc, array $rawMeta, ?string $extId = null): iterable
    {
        // Normalize inputs (be strict about nulls / types early)
        $extId = $extId ?? (string)($rawDoc['extId'] ?? $rawDoc['id'] ?? '');

        // Optional: you can pass through validity window if you already carry it in rawMeta
        $baseMeta = [];
        if (isset($rawMeta['nbf'])) { $baseMeta['nbf'] = $rawMeta['nbf']; }
        if (isset($rawMeta['exp'])) { $baseMeta['exp'] = $rawMeta['exp']; }

        /**
         * 1) 1->1 mapping example (raw "packset" -> internal "packset")
         *
         * Pipeline will derive uuid as:
         *   stableForSource("internal:packset", ifUuid, key)
         */
        yield [
            'kind' => 'packset',
            'key'  => $extId,               // stable per upstream record (can be compound, see below)
            'doc'  => [
                // uuid is optional; pipeline will set it from kind+key
                'type'        => 'packset',
                'externalId'  => $extId,
                'name'        => (string)($rawDoc['name'] ?? $rawDoc['NAZEV'] ?? ''),
                'status'      => (int)($rawDoc['status'] ?? 1),
                'lastUsed'    => $rawDoc['lastUsed'] ?? null,
            ],
            'meta' => $baseMeta,

            // Optional: override upstream stream name for provenance
            // 'stream' => 'acord.packset',
        ];

        /**
         * 2) 1->N mapping example
         * Suppose the raw doc contains items and you also want an internal "packset_item" per line.
         */
        $items = $rawDoc['items'] ?? null;
        if (is_array($items)) {
            foreach ($items as $i => $item) {
                if (!is_array($item)) { continue; }

                // Use a compound key to make each internal item stable and unique.
                // You MUST ensure the compound key is stable across runs.
                $itemKey = $extId . ':item:' . (string)($item['lineId'] ?? $i);

                yield [
                    'kind' => 'packset_item',
                    'key'  => $itemKey,
                    'doc'  => [
                        'type'        => 'packset_item',
                        'packsetExtId'=> $extId,
                        'lineId'      => $item['lineId'] ?? $i,
                        'ref'         => $item['ref'] ?? null,
                        'qty'         => isset($item['qty']) ? (int)$item['qty'] : null,
                    ],
                    'meta' => $baseMeta,
                ];
            }
        }

        /**
         * 3) Example of providing 'uuid' directly (only if you already have a stable scheme)
         *
         * yield [
         *   'uuid' => UuidTools::stableForSource('internal:packset', 'someNamespace', $extId),
         *   'doc'  => [...],
         * ];
         */
    }
}
