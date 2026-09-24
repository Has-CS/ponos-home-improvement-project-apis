<?php

namespace App\Services\Document;

use Illuminate\Support\Facades\DB;

/**
 * Collision-safe generator for human-readable document numbers
 * (request_no, po_number, …) per R-5.
 *
 * Uses the atomic UPDATE ... RETURNING pattern on document_sequences: the
 * UPDATE takes a row-level lock so two concurrent callers can never mint the
 * same value (which application-side MAX()+1 would under load, intermittently
 * violating the partial unique indexes on request_no / po_number).
 *
 * Global scope only for now (scope_type='global', scope_id=0) — per-project
 * counters can be added later by parameterizing the scope.
 */
class DocumentSequenceService
{
    private const SCOPE_TYPE = 'global';
    private const SCOPE_ID = 0;

    /**
     * Reserve and return the next formatted number for a document type,
     * e.g. next('material_request', 'MR') => "MR-000001".
     *
     * Must be called inside the caller's transaction so a rolled-back create
     * doesn't burn a number gap that matters (gaps are harmless, but keeping
     * it in-transaction is cleanest).
     */
    public function next(string $documentType, string $prefix): string
    {
        return sprintf('%s-%06d', $prefix, $this->nextValue($documentType, self::SCOPE_TYPE, self::SCOPE_ID, $prefix));
    }

    /**
     * Reserve and return the next raw counter value for a document type within a
     * scope, leaving the formatting to the caller.
     *
     * This is the scope parameterisation the class comment anticipated. Purchase
     * orders count per PROJECT — nextValue('purchase_order', 'project', 12) — so
     * each project's orders read 00001, 00002, … in their own series, while
     * material requests, RFQs and change orders keep their single global counter
     * through next() above.
     *
     * The atomicity is the whole point and lives only here: the UPDATE takes a
     * row-level lock, so two concurrent callers are serialised and can never be
     * handed the same value. A counter per scope means contention is limited to
     * documents of the same type in the same project.
     */
    public function nextValue(string $documentType, string $scopeType = self::SCOPE_TYPE, int $scopeId = self::SCOPE_ID, ?string $prefix = null): int
    {
        // Ensure the counter row exists (idempotent, safe under the unique index).
        DB::table('document_sequences')->insertOrIgnore([
            'document_type' => $documentType,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'prefix' => $prefix,
            'last_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::selectOne(
            "UPDATE document_sequences
                SET last_value = last_value + 1, updated_at = now()
              WHERE document_type = ? AND scope_type = ? AND scope_id = ?
          RETURNING last_value",
            [$documentType, $scopeType, $scopeId]
        );

        return (int) $row->last_value;
    }
}
