<?php
declare(strict_types=1);

namespace Glued\Lib\Classes\Sql2;

/**
 * Transform one RAW upstream document (+ meta) into one or more INTERNAL records.
 *
 * Contract:
 * - Each yielded item MUST contain:
 *     - 'doc' (array)
 *
 * - It MAY contain:
 *     - 'uuid' (string): stable INTERNAL UUID (preferred)
 *       OR:
 *     - 'kind' (string) + 'key' (string): used to derive a stable INTERNAL UUID if 'uuid' missing
 *     - 'meta' (array): additional INTERNAL meta to merge/bake (no actor keys are invented by the pipeline)
 *     - 'sat' (?string): effective "source at time" override for that internal item
 *     - 'stream' (string): upstream entity/table name for provenance (stored as meta.src[].stream)
 *
 * Notes:
 * - "stream" in this file always means the UPSTREAM entity/table name (semantic provenance).
 * - Identity (UUID) concerns are handled separately by the ingest pipeline via UUID namespacing.
 *
 * @return iterable<int, array{
 *   doc: array,
 *   meta?: array,
 *   uuid?: string,
 *   kind?: string,
 *   key?: string,
 *   sat?: ?string,
 *   stream?: string
 * }>
 */
interface UpstreamTransformer
{
    public function transform(array $rawDoc, array $rawMeta, ?string $extId = null): iterable;
}
