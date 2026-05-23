<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Race-safe writes to the shared BrandAudit.audit_evidence JSON column.
 *
 * The Phase-1 gather batch (places / gmaps / instagram / website) and the
 * Phase-2 analyze batch (instagram analysis / service signals) run their
 * jobs CONCURRENTLY once more than one queue worker is active. Each job
 * owns a different key of audit_evidence. The pre-existing pattern —
 * read the whole blob into PHP, set one key, write the whole blob back —
 * loses updates when two jobs commit interleaved: the second writer's blob
 * (built from a stale read) silently overwrites the first writer's key.
 *
 * These helpers issue a SINGLE atomic UPDATE using SQLite json_set(), so
 * the read + merge happen inside one write statement under SQLite's write
 * lock. A sibling key written by a concurrent job is preserved.
 *
 * json_set() (NOT json_patch / Laravel's `audit_evidence->key` arrow
 * update) is deliberate: json_patch follows RFC 7396 merge-patch and
 * DELETES members whose value is null, whereas several jobs must persist a
 * present-but-null key (e.g. audit_evidence.places_api = null on a skipped
 * scrape — asserted by GatherEvidenceJobTest). json_set keeps null members.
 *
 * Implementing classes must expose `public readonly string $auditId`.
 */
trait WritesAuditEvidence
{
    /**
     * Coerce the current column to a JSON object so json_set() has an
     * object root: null, invalid JSON, and a legacy '[]' array all become
     * '{}'. Referenced inline by both helpers below.
     */
    private const EVIDENCE_OBJECT_BASE = "CASE
            WHEN audit_evidence IS NULL THEN json('{}')
            WHEN json_valid(audit_evidence) = 0 THEN json('{}')
            WHEN json_type(audit_evidence) <> 'object' THEN json('{}')
            ELSE json(audit_evidence)
        END";

    /**
     * Atomically set a top-level audit_evidence key without clobbering
     * sibling keys. A null $value is stored as a present JSON-null member.
     */
    protected function writeEvidenceKey(string $key, mixed $value): void
    {
        DB::statement(
            'update brand_audits set audit_evidence = json_set('
                . self::EVIDENCE_OBJECT_BASE
                . ', ?, json(?)) where id = ?',
            ['$.' . $key, $this->encodeEvidenceValue($value), $this->auditId],
        );
    }

    /**
     * Atomically set a nested audit_evidence key (parent.child). Creates
     * the parent object when absent and preserves both existing parent
     * siblings and top-level siblings written by concurrent jobs.
     */
    protected function writeEvidenceNestedKey(string $parent, string $child, mixed $value): void
    {
        $base = self::EVIDENCE_OBJECT_BASE;

        DB::statement(
            "update brand_audits set audit_evidence = json_set(
                json_set({$base}, ?, json(coalesce(json_extract({$base}, ?), '{}'))),
                ?, json(?)
            ) where id = ?",
            [
                '$.' . $parent,
                '$.' . $parent,
                '$.' . $parent . '.' . $child,
                $this->encodeEvidenceValue($value),
                $this->auditId,
            ],
        );
    }

    /**
     * Encode a PHP value to a JSON literal for json(?). Falls back to a
     * JSON null on encode failure (bad UTF-8) so the statement never binds
     * malformed JSON — parity with the previous Eloquent-cast behaviour.
     */
    private function encodeEvidenceValue(mixed $value): string
    {
        $json = json_encode($value);

        return $json === false ? 'null' : $json;
    }
}
