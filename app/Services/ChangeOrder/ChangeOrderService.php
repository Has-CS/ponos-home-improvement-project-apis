<?php

namespace App\Services\ChangeOrder;

use App\Models\ChangeOrder;
use App\Models\ChangeOrderScopeItem;
use App\Models\ChangeOrderSignature;
use App\Models\ChangeOrderStatus;
use App\Models\ChangeOrderType;
use App\Models\GcDecision;
use App\Models\Project;
use App\Models\ProjectGeneralContractor;
use App\Models\User;
use App\Services\Attachment\AttachmentService;
use App\Services\ChangeOrderTerms\ChangeOrderTermsService;
use App\Services\Document\DocumentSequenceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ChangeOrderService
{
    // change_order_statuses.code
    private const DRAFT = 'draft';
    private const PENDING_PM = 'pending_pm';
    private const PENDING_ADMIN = 'pending_admin';
    private const SENT_BACK = 'sent_back';
    private const REJECTED_INTERNAL = 'rejected_internal';
    private const PENDING_DOCUMENT = 'pending_document';
    private const PENDING_COUNTER_SIGN = 'pending_counter_sign';
    private const PENDING_GC = 'pending_gc';
    private const ACTIVE = 'active';
    private const GC_REJECTED = 'gc_rejected';
    private const CANCELLED = 'cancelled';

    /** Statuses in which the CO's fields may still be edited, by SOMEONE — see assertEditable(). */
    private const EDITABLE_STATUSES = [
        self::DRAFT, self::SENT_BACK, self::PENDING_PM, self::PENDING_ADMIN, self::PENDING_DOCUMENT,
    ];

    private const LIST_WITH = ['type', 'status', 'gcDecision', 'originator'];
    private const DETAIL_WITH = [
        'type', 'status', 'gcDecision', 'costCode', 'urgency', 'originator',
        'counterSignedBy', 'gcDecisionBy', 'documentAttachment', 'generalContractor',
        'scopeItems.unit',
        'approvals.actor', 'approvals.fromStatus', 'approvals.toStatus',
        'signatures.capturedBy', 'signatures.signatureAttachment',
    ];

    /** @var array<string,int> */
    private array $statusIdCache = [];

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly AttachmentService $attachments,
        private readonly ChangeOrderPdfService $pdf,
        private readonly ChangeOrderTermsService $terms,
    ) {}

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(Project $project, array $filters): LengthAwarePaginator
    {
        $query = ChangeOrder::query()->where('project_id', $project->id)->with(self::LIST_WITH);

        if (! empty($filters['search'])) {
            $t = $filters['search'];
            $query->where(fn ($q) => $q->where('change_order_no', 'ilike', "%{$t}%")->orWhere('title', 'ilike', "%{$t}%"));
        }
        if (! empty($filters['status_id'])) {
            $query->where('change_order_status_id', $filters['status_id']);
        }
        if (! empty($filters['type_id'])) {
            $query->where('change_order_type_id', $filters['type_id']);
        }
        if (! empty($filters['gc_decision_id'])) {
            $query->where('gc_decision_id', $filters['gc_decision_id']);
        }

        return $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function findDetailed(ChangeOrder $co): ChangeOrder
    {
        return $co->load(self::DETAIL_WITH);
    }

    // ---- Creation ----

    public function createNormal(Project $project, array $data, int $userId): ChangeOrder
    {
        return DB::transaction(function () use ($project, $data, $userId) {
            $co = ChangeOrder::create([
                'change_order_no' => $this->sequences->next('change_order', 'CO'),
                'project_id' => $project->id,
                'general_contractor_id' => $this->resolveGeneralContractor($project, $data['general_contractor_id'] ?? null),
                'inclusions' => $data['inclusions'] ?? null,
                'exclusions' => $data['exclusions'] ?? null,
                'cost_code_id' => $data['cost_code_id'] ?? null,
                'change_order_type_id' => $this->typeId('normal'),
                'change_order_status_id' => $this->statusId(self::DRAFT),
                'gc_decision_id' => $this->gcDecisionId('pending'),
                'originator_id' => $userId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'scope' => $data['scope'] ?? null,
                'location' => $data['location'] ?? null,
                'urgency_id' => $data['urgency_id'] ?? null,
                'value' => $data['value'] ?? null,
                'created_by' => $userId,
            ]);

            $this->syncScopeItems($co, $data['scope_items'] ?? []);

            return $co->fresh(self::DETAIL_WITH);
        });
    }

    /**
     * Replace a change order's Scope of Work rows with the given set.
     *
     * REPLACE, not merge: the client owns the whole table and sends it entire,
     * which avoids per-row id juggling in a drag-to-reorder editor. The previous
     * rows are soft-deleted rather than destroyed, so a mistaken overwrite is
     * recoverable from the database.
     *
     * `sort_order` comes from the array index — array order IS print order. The
     * client never sends it; two rows claiming one position would leave the
     * document's sequence ambiguous.
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function syncScopeItems(ChangeOrder $co, array $rows): void
    {
        $co->scopeItems()->get()->each->delete();

        foreach (array_values($rows) as $i => $row) {
            $quantity = $row['quantity'] ?? null;

            $co->scopeItems()->create([
                'row_type' => $row['row_type'],
                // A spacer carries no text even if one is sent — it is a blank
                // row by definition, and honouring a stray label would print it.
                'label' => $row['row_type'] === ChangeOrderScopeItem::SPACER ? null : ($row['label'] ?? null),
                'quantity' => $quantity,
                // Dropped when there is no quantity, so a unit cannot linger on a
                // heading row and print "— SF".
                'unit_id' => $quantity === null ? null : ($row['unit_id'] ?? null),
                'indent' => $row['indent'] ?? 0,
                'sort_order' => $i,
            ]);
        }
    }

    /**
     * Emergency CO: the GC rep signed on-site, so the CO is authorized on
     * creation. One transaction: create the CO straight to active, freeze the
     * GC + standing-terms snapshot, store the signature image, record the
     * signature, log a single approval row, and file the document.
     *
     * The snapshot-freeze and document-filing steps mirror
     * prepareDocument()/counterSign() on the Normal flow — an emergency CO has
     * no separate document-prep step to hang them on, but needs the identical
     * guarantee: the filed PDF and the GC/terms it names must never drift after
     * the on-site signature, even if the GC record or standing terms are edited
     * later. Same reasoning as prepareDocument()'s forceFill: the gc_* and
     * terms columns are deliberately absent from $fillable.
     */
    public function createEmergency(Project $project, array $data, User $user): ChangeOrder
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $generalContractorId = $this->resolveGeneralContractor($project, $data['general_contractor_id'] ?? null);

            $co = ChangeOrder::create([
                'change_order_no' => $this->sequences->next('change_order', 'CO'),
                'project_id' => $project->id,
                'general_contractor_id' => $generalContractorId,
                'inclusions' => $data['inclusions'] ?? null,
                'exclusions' => $data['exclusions'] ?? null,
                'cost_code_id' => $data['cost_code_id'] ?? null,
                'change_order_type_id' => $this->typeId('emergency'),
                'change_order_status_id' => $this->statusId(self::ACTIVE),
                'gc_decision_id' => $this->gcDecisionId('approved'),
                'originator_id' => $user->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'scope' => $data['scope'],
                'location' => $data['location'],
                'urgency_id' => $data['urgency_id'] ?? null,
                'value' => $data['value'] ?? null,
                'gc_decision_by' => $user->id,
                'gc_decision_at' => now(),
                'gc_decision_notes' => 'On-site GC representative signature (emergency).',
                'became_active_at' => now(),
                'created_by' => $user->id,
            ]);

            $gc = $generalContractorId ? ProjectGeneralContractor::find($generalContractorId) : null;

            $co->forceFill([
                ...($gc ? $gc->toGcSnapshot() : []),
                ...$this->resolveTerms((int) $project->id),
            ])->save();

            $attachment = $this->attachments->storeBase64Image($data['signature_image'], [
                'attachable_type' => ChangeOrder::class,
                'attachable_id' => $co->id,
                'project_id' => $project->id,
                'attachment_type' => 'signature',
                'directory' => 'change-order-signatures',
                'uploaded_by' => $user->id,
                'captured_at' => now(),
            ]);

            ChangeOrderSignature::create([
                'change_order_id' => $co->id,
                'signer_name' => $data['signer_name'],
                'signer_title' => $data['signer_title'] ?? null,
                'signer_company' => $data['signer_company'] ?? null,
                'signer_contact' => $data['signer_contact'] ?? null,
                'signature_attachment_id' => $attachment->id,
                'signed_at' => $data['signed_at'] ?? now(),
                'signed_lat' => $data['signed_lat'] ?? null,
                'signed_lng' => $data['signed_lng'] ?? null,
                'location_note' => $data['location_note'] ?? null,
                'captured_by' => $user->id,
                'device_info' => $data['device_info'] ?? null,
            ]);

            $this->logApproval($co, $user, 'submit', null, $this->statusId(self::ACTIVE), 'Emergency change order authorized on-site.');

            // Filed AFTER the signature and approval row exist, so the document
            // carries both. Same ordering rationale as
            // prepareDocument()/counterSign(): the filed copy has to reflect the
            // state the change order is actually in.
            $document = $this->pdf->storeFor($co, $user->id);
            $co->forceFill(['document_attachment_id' => $document->id])->save();

            return $co->fresh(self::DETAIL_WITH);
        });
    }

    public function update(ChangeOrder $co, User $user, array $data): ChangeOrder
    {
        $this->assertEditable($co, $user);

        // Re-resolve rather than trusting the id straight from the payload: the
        // FormRequest can only check the row exists, not that it belongs to this
        // change order's project. Same division of labour as
        // PurchaseOrderService::resolveShipTo().
        if (array_key_exists('general_contractor_id', $data)) {
            $data['general_contractor_id'] = $data['general_contractor_id'] === null
                ? null
                : $this->assertGcInProject($co->project_id, (int) $data['general_contractor_id']);
        }

        return DB::transaction(function () use ($co, $data) {
            // An ABSENT key leaves the existing rows alone; a present one
            // replaces the whole set, so [] clears the table. Checked with
            // array_key_exists rather than isset() so an explicit null is treated
            // as "clear" rather than "not sent".
            if (array_key_exists('scope_items', $data)) {
                $this->syncScopeItems($co, $data['scope_items'] ?? []);
            }

            unset($data['scope_items']);

            $co->fill($data)->save();

            return $co->fresh(self::DETAIL_WITH);
        });
    }

    /**
     * The GC a change order is raised against.
     *
     * An explicit choice is validated against the project; with none given the
     * project's primary GC is used, which is what the is_primary flag exists
     * for. Null is a legitimate outcome — a project may have no GC on file yet,
     * and a change order can be drafted before one is added. It becomes
     * mandatory at prepareDocument(), not here.
     */
    private function resolveGeneralContractor(Project $project, mixed $requestedId): ?int
    {
        if ($requestedId !== null) {
            return $this->assertGcInProject($project->id, (int) $requestedId);
        }

        return $project->primaryGeneralContractor()->value('id');
    }

    /**
     * Resolve the Payment Terms / Changes / Acceptance text this change order is
     * issued under into the columns that store them: the FK for traceability,
     * plus the snapshot that prints.
     *
     * All-nulls when nothing is configured, which is a legitimate outcome:
     * unlike the general contractor — which prepareDocument() refuses to proceed
     * without, because the sheet is addressed to them — missing terms never block
     * preparation. The sections simply do not print.
     *
     * Mirrors PurchaseOrderService::resolveTerms().
     *
     * @return array<string,mixed>
     */
    private function resolveTerms(int $projectId): array
    {
        $terms = $this->terms->resolveFor($projectId);

        if (! $terms) {
            return [
                'terms_id' => null,
                'payment_terms_body' => null,
                'changes_body' => null,
                'acceptance_body' => null,
            ];
        }

        return ['terms_id' => $terms->id, ...$terms->toTermsSnapshot()];
    }

    /** @return int the validated GC id */
    private function assertGcInProject(int $projectId, int $gcId): int
    {
        $belongs = ProjectGeneralContractor::whereKey($gcId)
            ->where('project_id', $projectId)
            ->exists();

        // Belt-and-braces against one project's GC being attached to another's
        // change order — and, since the document is addressed to whoever this
        // resolves to, a leak of one client's counterparty onto another's paperwork.
        abort_unless($belongs, 422, 'The general contractor does not belong to this change order\'s project.');

        return $gcId;
    }

    /**
     * Who may edit a change order's fields, and when.
     *
     *   draft | sent_back      the originator (or an Admin acting for them)
     *   pending_pm             a PM or Admin — the reviewer asked to "validate
     *                          scope & cost" must be able to correct the cost in
     *                          place, not only bounce it back
     *   pending_admin          an Admin, likewise at their own review step
     *   pending_document       a PM or Admin, filling in the final `value`
     *                          immediately before the document is generated
     *   anything later         NOBODY
     *
     * That last rule is the important one, and it is a deliberate reversal of
     * the original behaviour, which allowed edits at pending_counter_sign so a
     * late `value` could be entered. From pending_counter_sign onward a rendered
     * PDF exists and has been filed against the CO; editing the record behind it
     * would leave the filed document contradicting the row it describes. The
     * late-value window moves earlier, to pending_document.
     *
     * Excluding the Assistant PM from the two review windows is deliberate and
     * mirrors MaterialRequestService::assertItemsEditable(): an Assistant PM may
     * validate, send back or reject, but may not rewrite the scope or value of
     * someone else's change order. Their route to a correction is send_back.
     */
    private function assertEditable(ChangeOrder $co, User $user): void
    {
        $status = $this->statusCode($co);

        if (! in_array($status, self::EDITABLE_STATUSES, true)) {
            abort(409, 'This change order can no longer be edited in its current status.');
        }

        if ($status === self::PENDING_PM || $status === self::PENDING_DOCUMENT) {
            if (! $this->isPmOrAdmin($user)) {
                abort(403, 'Only a project manager or administrator can edit a change order at this step.');
            }

            return;
        }

        if ($status === self::PENDING_ADMIN) {
            if (! $this->isAdmin($user)) {
                abort(403, 'Only an administrator can edit a change order awaiting their review.');
            }

            return;
        }

        // draft | sent_back — the originator's own working copy.
        if ($co->originator_id !== $user->id && ! $this->isAdmin($user)) {
            abort(403, 'You are not allowed to edit this change order.');
        }
    }

    // ---- Workflow transitions ----

    /**
     * Advance a draft/sent-back change order into the approval chain.
     *
     * The target step depends on WHO RAISED the change order, not who is
     * submitting it: a PM (or Admin) has no PM review left to perform on their
     * own request, and routing it to pending_pm would only invite them to
     * validate themselves — so it goes straight to Admin. A foreman's change
     * order still takes the full PM → Admin path.
     *
     * Keyed off the originator, never the actor, for two reasons: an Admin may
     * submit someone else's draft on their behalf (permitted by the guard
     * above) and that must not skip the foreman's PM review; and resubmission
     * from `sent_back` then re-routes correctly with no extra code.
     *
     * Narrower than the material-request equivalent
     * (MaterialRequestService::submit(), which uses isPmLevel()): an Assistant
     * PM's change order still gets a full PM review, because an Assistant PM is
     * not the person who signs off scope and cost on a contractual document.
     */
    public function submit(ChangeOrder $co, User $user): ChangeOrder
    {
        $from = $this->statusCode($co);
        if (! in_array($from, [self::DRAFT, self::SENT_BACK], true)) {
            abort(409, "A change order in '{$from}' status cannot be submitted.");
        }
        if ($co->originator_id !== $user->id && ! $this->isAdmin($user)) {
            abort(403, 'Only the originator can submit this change order.');
        }

        $to = $co->originator && $this->isPmOrAdmin($co->originator)
            ? self::PENDING_ADMIN
            : self::PENDING_PM;

        return $this->transition($co, $user, 'submit', $from, $to, null);
    }

    public function validateCo(ChangeOrder $co, User $user, ?string $comments): ChangeOrder
    {
        $from = $this->statusCode($co);
        if ($from !== self::PENDING_PM) {
            abort(409, "A change order in '{$from}' status cannot be validated.");
        }
        if (! $this->isPmLevel($user)) {
            abort(403, 'This step must be actioned by a project manager.');
        }

        return $this->transition($co, $user, 'validate', $from, self::PENDING_ADMIN, $comments);
    }

    /**
     * Admin review. Lands in pending_document, NOT pending_counter_sign: the
     * approval settles that the change is warranted, but the formal document
     * does not exist yet and the PM has to prepare it before there is anything
     * for an Admin to counter-sign.
     */
    public function approve(ChangeOrder $co, User $user, ?string $comments): ChangeOrder
    {
        $from = $this->statusCode($co);
        if ($from !== self::PENDING_ADMIN) {
            abort(409, "A change order in '{$from}' status cannot be approved.");
        }
        if (! $this->isAdmin($user)) {
            abort(403, 'This step must be actioned by an administrator.');
        }

        return $this->transition($co, $user, 'approve', $from, self::PENDING_DOCUMENT, $comments);
    }

    /**
     * Prepare the formal change-order document.
     *
     * The PM's step, and the point at which the change order stops being a
     * request and becomes a document. Renders the PDF, files it against the CO,
     * and hands back a filed copy the PM can send to the GC.
     *
     * `value` is required HERE rather than at counter-sign, where it used to be
     * checked. The document states the change to the contract sum; generating
     * one that says "to be determined" and then asking an Admin to sign it would
     * put the guard after the thing it is meant to protect.
     *
     * Everything happens inside one transaction, so a failed render cannot leave
     * the change order advanced past a document that does not exist.
     */
    public function prepareDocument(ChangeOrder $co, User $user, ?string $comments): ChangeOrder
    {
        $from = $this->statusCode($co);
        if ($from !== self::PENDING_DOCUMENT) {
            abort(409, "A change order in '{$from}' status is not awaiting its document.");
        }
        if (! $this->isPmOrAdmin($user)) {
            abort(403, 'Only a project manager or administrator can prepare the change order document.');
        }
        if ($co->value === null) {
            abort(422, 'A value must be set before the change order document can be prepared.');
        }

        // The document is ADDRESSED to the GC — its "To" block, its acceptance
        // signature and the email draft all resolve from it. Generating one with
        // no GC would produce a sheet addressed to nobody, so this is the same
        // class of guard as the value check above.
        $gc = $co->general_contractor_id
            ? ProjectGeneralContractor::find($co->general_contractor_id)
            : null;

        if (! $gc) {
            abort(422, 'A general contractor must be selected before the change order document can be prepared.');
        }

        return DB::transaction(function () use ($co, $user, $from, $comments, $gc) {
            // Freeze the GC details AND the standing contractual text onto the
            // change order BEFORE rendering, so the PDF and the stored snapshot
            // state the same counterparty and the same terms. From
            // pending_counter_sign onward assertEditable() blocks every edit, so
            // nothing can drift after this point — the same guarantee
            // purchase_orders gets from assertDraft() for its ship-to block.
            //
            // forceFill because the gc_* and terms columns are deliberately
            // absent from $fillable: they are derived, and must never be settable
            // from request input.
            $co->forceFill([
                ...$gc->toGcSnapshot(),
                ...$this->resolveTerms((int) $co->project_id),
            ])->save();

            $updated = $this->transition($co, $user, 'prepare_document', $from, self::PENDING_COUNTER_SIGN, $comments);

            // Rendered AFTER the status move, so the filed PDF shows the state
            // the change order is actually in and carries the prepare step in its
            // printed authorization chain. Same ordering as
            // PurchaseOrderService::issue().
            $attachment = $this->pdf->storeFor($updated, $user->id);

            $updated->forceFill(['document_attachment_id' => $attachment->id])->save();

            return $updated->fresh(self::DETAIL_WITH);
        });
    }

    /**
     * Admin counter-signature — the point the document becomes binding and is
     * ready to go to the GC.
     *
     * Regenerates the filed document rather than merely flipping the status: the
     * copy stored at prepareDocument() shows an unsigned signature block, and
     * that is not what should reach a GC. storeFor() soft-deletes the superseded
     * copy, so the audit trail keeps both and exactly one current document
     * remains.
     *
     * The `value` check that used to live here has moved earlier, to
     * prepareDocument() — by this point a document already exists and quoting a
     * figure, so a null value is no longer reachable.
     */
    public function counterSign(ChangeOrder $co, User $user, ?string $comments): ChangeOrder
    {
        $from = $this->statusCode($co);
        if ($from !== self::PENDING_COUNTER_SIGN) {
            abort(409, "A change order in '{$from}' status cannot be counter-signed.");
        }
        if (! $this->isAdmin($user)) {
            abort(403, 'Only an administrator can counter-sign a change order.');
        }

        return DB::transaction(function () use ($co, $user, $from, $comments) {
            $co->forceFill(['counter_signed_by' => $user->id, 'counter_signed_at' => now()])->save();

            // Re-file AFTER the stamps and the status move, so the stored PDF
            // carries the counter-signature and reads "Pending GC" rather than
            // the state it was prepared in.
            $updated = $this->transition($co, $user, 'counter_sign', $from, self::PENDING_GC, $comments);

            $attachment = $this->pdf->storeFor($updated, $user->id);

            $updated->forceFill(['document_attachment_id' => $attachment->id])->save();

            return $updated->fresh(self::DETAIL_WITH);
        });
    }

    /**
     * A ready-to-send email addressed to the GC, returned alongside the prepared
     * document. NOTHING IS SENT: the GC is a third party who does not use this
     * system, there is no gc_email column anywhere in the schema, and mailing an
     * external party automatically is not a decision this endpoint should make.
     * The PM copies this into their own mail client and attaches the PDF.
     *
     * Addressed to the project's client record, which is what the GC is
     * represented by — see the "To — General Contractor" panel on the document.
     *
     * @return array<string,mixed>
     */
    public function emailDraft(ChangeOrder $co): array
    {
        $co->loadMissing(['project', 'generalContractor']);

        // The GC block, snapshot-first — so a draft re-sent after the document
        // was prepared quotes the same counterparty the document names, even if
        // the GC record has been edited since.
        $gc = $co->gcBlock();
        $project = $co->project;

        $value = $co->value === null
            ? 'To be determined'
            : number_format((float) $co->value, 2, '.', ',');

        $greeting = $gc && $gc['contact_name'] ? "Dear {$gc['contact_name']}," : 'Dear Sir or Madam,';

        $projectLine = $project
            ? trim(($project->code ? "{$project->code} — " : '').$project->name)
            : null;

        $body = implode("\n\n", array_filter([
            $greeting,
            'Please find attached change order '.$co->change_order_no
                .($projectLine ? ' for '.$projectLine : '').'.',
            trim("Description: {$co->title}"),
            $co->scope ? "Scope of work:\n{$co->scope}" : null,
            'Change to contract sum: '.$value,
            'This change order requires your written authorisation before the work described can proceed. '
                .'Please return one signed copy quoting '.$co->change_order_no.'.',
            'Kind regards,',
            config('company.name'),
        ]));

        return [
            'to' => $gc['email'] ?? null,
            'to_name' => $gc ? ($gc['contact_name'] ?? $gc['name']) : null,
            'subject' => trim(
                'Change Order '.$co->change_order_no
                .($projectLine ? ' — '.$projectLine : '')
                .' — '.$co->title
            ),
            'body' => $body,
        ];
    }

    public function setGcDecision(ChangeOrder $co, User $user, string $decision, ?string $notes): ChangeOrder
    {
        $from = $this->statusCode($co);
        if ($from !== self::PENDING_GC) {
            abort(409, 'The GC decision can only be recorded while the change order is pending GC.');
        }
        if (! $this->isPmLevel($user)) {
            abort(403, 'Only a project manager or administrator can record the GC decision.');
        }

        $to = $decision === 'approved' ? self::ACTIVE : self::GC_REJECTED;

        $co->forceFill([
            'gc_decision_id' => $this->gcDecisionId($decision),
            'gc_decision_by' => $user->id,
            'gc_decision_at' => now(),
            'gc_decision_notes' => $notes,
            'became_active_at' => $decision === 'approved' ? now() : null,
        ])->save();

        return $this->transition($co, $user, 'set_gc_status', $from, $to, $notes);
    }

    /**
     * Return the change order to its originator for revision.
     *
     * Logged as 'send_back', not 'reject': a revision request and a refusal are
     * different acts on a contractual document, and the chain has to say which
     * one happened rather than leaving a reader to infer it from to_status_id.
     */
    public function sendBack(ChangeOrder $co, User $user, string $comments): ChangeOrder
    {
        $from = $this->statusCode($co);
        $this->assertInternalReviewer($co, $user, $from);

        return $this->transition($co, $user, 'send_back', $from, self::SENT_BACK, $comments);
    }

    public function reject(ChangeOrder $co, User $user, string $comments): ChangeOrder
    {
        $from = $this->statusCode($co);
        $this->assertInternalReviewer($co, $user, $from);

        return $this->transition($co, $user, 'reject', $from, self::REJECTED_INTERNAL, $comments);
    }

    public function cancel(ChangeOrder $co, User $user, ?string $comments): ChangeOrder
    {
        $from = $this->statusCode($co);
        if (in_array($from, [self::ACTIVE, self::GC_REJECTED, self::REJECTED_INTERNAL, self::CANCELLED], true)) {
            abort(409, "A change order in '{$from}' status can no longer be cancelled.");
        }
        if (! $this->isPmLevel($user) && $co->originator_id !== $user->id) {
            abort(403, 'You are not allowed to cancel this change order.');
        }

        return $this->transition($co, $user, 'cancel', $from, self::CANCELLED, $comments);
    }

    // ---- Internals ----

    /** Only a PM/Admin may send back or reject, and only at a pending internal step. */
    private function assertInternalReviewer(ChangeOrder $co, User $user, string $from): void
    {
        if (! in_array($from, [self::PENDING_PM, self::PENDING_ADMIN], true)) {
            abort(409, "A change order in '{$from}' status cannot be sent back or rejected.");
        }
        if ($from === self::PENDING_PM && ! $this->isPmLevel($user)) {
            abort(403, 'This step must be actioned by a project manager.');
        }
        if ($from === self::PENDING_ADMIN && ! $this->isAdmin($user)) {
            abort(403, 'This step must be actioned by an administrator.');
        }
    }

    private function transition(ChangeOrder $co, User $user, string $action, ?string $fromCode, string $toCode, ?string $comments): ChangeOrder
    {
        return DB::transaction(function () use ($co, $user, $action, $fromCode, $toCode, $comments) {
            $fromId = $fromCode ? $this->statusId($fromCode) : null;
            $toId = $this->statusId($toCode);

            $co->update(['change_order_status_id' => $toId]);
            $this->logApproval($co, $user, $action, $fromId, $toId, $comments);

            return $co->fresh(self::DETAIL_WITH);
        });
    }

    /** Overloaded so callers can pass a status code OR a resolved id for from/to. */
    private function logApproval(ChangeOrder $co, User $user, string $action, ?int $fromId, ?int $toId, ?string $comments): void
    {
        $nextStep = (int) $co->approvals()->max('step_no') + 1;
        $co->approvals()->create([
            'step_no' => $nextStep,
            'actor_id' => $user->id,
            'actor_role' => $this->actorRole($user),
            'action' => $action,
            'comments' => $comments,
            'from_status_id' => $fromId,
            'to_status_id' => $toId,
            'acted_at' => now(),
        ]);
    }

    private function isAdmin(User $user): bool
    {
        $user->unsetRelation('roles');
        return $user->hasRole('Admin');
    }

    private function isPmLevel(User $user): bool
    {
        $user->unsetRelation('roles');
        return $user->hasRole(['Admin', 'Project Manager', 'Assistant Project Manager']);
    }

    /**
     * Narrower than isPmLevel(): excludes the Assistant PM.
     *
     * Used where the act carries commercial weight rather than merely moving
     * the request along — deciding a change order needs no PM review at all
     * (submit), and editing scope/value in someone else's request while it sits
     * at review. An Assistant PM may still validate, send back and reject; their
     * route to a correction is send_back.
     */
    private function isPmOrAdmin(User $user): bool
    {
        $user->unsetRelation('roles');
        return $user->hasRole(['Admin', 'Project Manager']);
    }

    private function actorRole(User $user): string
    {
        $user->unsetRelation('roles');
        foreach (['Admin', 'Project Manager', 'Assistant Project Manager'] as $role) {
            if ($user->hasRole($role)) {
                return $role;
            }
        }
        return $user->getRoleNames()->first() ?? 'User';
    }

    private function statusCode(ChangeOrder $co): string
    {
        return $co->status?->code ?? ChangeOrderStatus::whereKey($co->change_order_status_id)->value('code');
    }

    private function statusId(string $code): int
    {
        return $this->statusIdCache[$code] ??= ChangeOrderStatus::where('code', $code)->value('id')
            ?? abort(500, "Change order status '{$code}' is not seeded.");
    }

    private function typeId(string $code): int
    {
        return ChangeOrderType::where('code', $code)->value('id')
            ?? abort(500, "Change order type '{$code}' is not seeded.");
    }

    private function gcDecisionId(string $code): int
    {
        return GcDecision::where('code', $code)->value('id')
            ?? abort(500, "GC decision '{$code}' is not seeded.");
    }
}
