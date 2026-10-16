<?php

namespace App\Services\ChangeOrder;

use App\Models\ChangeOrder;
use App\Models\ChangeOrderScopeItem;
use App\Models\ChangeOrderSignature;
use App\Models\ChangeOrderStatus;
use App\Models\ChangeOrderType;
use App\Models\EmailLog;
use App\Models\GcDecision;
use App\Models\Project;
use App\Models\ProjectGeneralContractor;
use App\Models\User;
use App\Services\Attachment\AttachmentService;
use App\Services\ChangeOrderTerms\ChangeOrderTermsService;
use App\Jobs\SendChangeOrderEmailJob;
use App\Services\Document\DocumentSequenceService;
use App\Support\Concerns\ScopesProjectAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ChangeOrderService
{
    use ScopesProjectAccess;

    // change_order_statuses.code
    private const DRAFT = 'draft';
    private const PENDING_PM = 'pending_pm';
    private const PENDING_ADMIN = 'pending_admin';
    private const SENT_BACK = 'sent_back';
    /** Emergency only: signed on site, work may proceed, not yet priced. */
    private const WORK_AUTHORIZED = 'work_authorized';
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
        self::WORK_AUTHORIZED,
    ];

    private const LIST_WITH = ['type', 'status', 'gcDecision', 'originator'];
    private const DETAIL_WITH = [
        'type', 'status', 'gcDecision', 'costCode', 'urgency', 'originator',
        'counterSignedBy', 'gcDecisionBy', 'documentAttachment', 'generalContractor',
        'pricedBy', 'sentToGcBy',
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
        private readonly ChangeOrderAuthorizationPdfService $authorizations,
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
     * Emergency CO, step one of two: CAPTURE.
     *
     * A GC representative signed on site, so the WORK is authorised and may
     * proceed. Nothing is priced — the field user who takes the signature has no
     * business setting a figure, and StoreEmergencyChangeOrderRequest refuses the
     * key outright rather than dropping it silently.
     *
     * This is a deliberate reversal of the original behaviour, which drove the
     * change order straight to `active` with gc_decision = approved. Two defects
     * followed from that, and both are why this method was rewritten:
     *
     *  1. `active` is terminal, so assertEditable(), cancel() and every chain step
     *     refused the row forever. An emergency CO captured with no value could
     *     NEVER be priced — its document printed "To be determined" in perpetuity.
     *  2. It recorded a GC DECISION that no GC had made. The representative
     *     authorised the work; they did not agree the money, which nobody had yet
     *     calculated. gc_decision is about the change order, price included, so it
     *     starts `pending` here and is settled only when the GC answers.
     *
     * One transaction, and the ORDER matters:
     *
     *   create at work_authorized  ->  freeze the GC + terms + signed_* snapshots
     *   ->  store the signature image  ->  record the signature  ->  log `capture`
     *   ->  render and file the Work Authorization sheet
     *
     * The authorization sheet is filed LAST because it prints the signature and
     * the capture record, so both must exist first. Same ordering rationale as
     * prepareDocument()/counterSign() on the Normal flow.
     *
     * NOTHING files a `document`-typed attachment here, deliberately. At this
     * point the change order states no price, so a change-order document
     * generated now would read "To be determined" — and being the filed copy,
     * that is what /pdf would serve and what heal-on-read would keep propagating.
     * The change-order document comes into existence when the change order is
     * priced and sent, which is the first moment it can say anything true.
     *
     * The snapshots use forceFill for the reason given on prepareDocument(): the
     * gc_*, terms and signed_* columns are all absent from $fillable because they
     * are derived evidence, and a client able to set them could forge it.
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
                'change_order_status_id' => $this->statusId(self::WORK_AUTHORIZED),
                // PENDING, not approved. See the docblock: the signature
                // authorises work, not a price nobody has calculated yet.
                'gc_decision_id' => $this->gcDecisionId('pending'),
                'originator_id' => $user->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'scope' => $data['scope'],
                'location' => $data['location'],
                'urgency_id' => $data['urgency_id'] ?? null,
                'created_by' => $user->id,
            ]);

            $gc = $generalContractorId ? ProjectGeneralContractor::find($generalContractorId) : null;

            $co->forceFill([
                ...($gc ? $gc->toGcSnapshot() : []),
                ...$this->resolveTerms((int) $project->id),
                ...$this->signedSnapshot($co),
                // Server time, unlike the signature's own signed_at, which the
                // device supplies and may therefore backdate.
                'work_authorized_at' => now(),
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

            // `capture`, not `submit`. Submitting enters a request into a review
            // chain; capturing records that work was authorised outside one. A
            // reader of the chain has to be able to tell which happened without
            // inferring it from the status pair — the same argument
            // 2026_08_10_000001 makes for `finalize` over `approve`.
            $this->logApproval(
                $co,
                $user,
                'capture',
                null,
                $this->statusId(self::WORK_AUTHORIZED),
                'Work authorized on site by the GC representative. Not yet priced.',
            );

            $this->authorizations->storeFor($co->fresh(), $user->id);

            return $co->fresh(self::DETAIL_WITH);
        });
    }

    /**
     * Freeze what the GC's representative actually signed for.
     *
     * The fourth snapshot block on this row after gc_*, the terms bodies and the
     * purchase order's ship_to_*, taken for the same reason argued there: the
     * change order stays editable long after this moment — a PM prices it and may
     * revise the narrative, which is the whole point of splitting capture from
     * pricing — and without a frozen copy the record that was SIGNED silently
     * becomes the record that is BILLED. With one, both can be printed and a
     * General Contractor can see exactly what they authorised against what they
     * are being asked to pay.
     *
     * Mirrors material_requests.request_text, which holds a foreman's original
     * prose on the parent row while the office user's structured version lives in
     * a child table.
     *
     * Read back off the row rather than out of $data, so it captures exactly what
     * was persisted, casts and all, instead of a parallel reading of the payload.
     *
     * @return array<string,mixed>
     */
    private function signedSnapshot(ChangeOrder $co): array
    {
        return [
            'signed_title' => $co->title,
            'signed_description' => $co->description,
            'signed_scope' => $co->scope,
            'signed_location' => $co->location,
            'signed_inclusions' => $co->inclusions,
            'signed_exclusions' => $co->exclusions,
        ];
    }

    public function update(ChangeOrder $co, User $user, array $data): ChangeOrder
    {
        $this->assertEditable($co, $user);

        // An emergency change order's counterparty is fixed at capture, and this
        // is not a convenience guard. The gc_* snapshot — which is what the
        // document prints and what the outbound email is addressed to — is frozen
        // in createEmergency(). Re-pointing general_contractor_id afterwards would
        // leave the OLD general contractor printed on the sheet, the OLD one
        // emailed, and the NEW id stored: three sources of truth, two of them
        // wrong. And it is the more correct rule regardless, because the
        // representative who signed belongs to a specific general contractor, so
        // the signature itself identifies the party.
        if ($this->isEmergency($co) && array_key_exists('general_contractor_id', $data)
            && (int) $data['general_contractor_id'] !== (int) $co->general_contractor_id) {
            abort(422, 'The general contractor cannot be changed once the work has been authorized on site.');
        }

        // Re-resolve rather than trusting the id straight from the payload: the
        // FormRequest can only check the row exists, not that it belongs to this
        // change order's project. Same division of labour as
        // PurchaseOrderService::resolveShipTo().
        if (array_key_exists('general_contractor_id', $data)) {
            $data['general_contractor_id'] = $data['general_contractor_id'] === null
                ? null
                : $this->assertGcInProject($co->project_id, (int) $data['general_contractor_id']);
        }

        return DB::transaction(function () use ($co, $user, $data) {
            // An ABSENT key leaves the existing rows alone; a present one
            // replaces the whole set, so [] clears the table. Checked with
            // array_key_exists rather than isset() so an explicit null is treated
            // as "clear" rather than "not sent".
            if (array_key_exists('scope_items', $data)) {
                $this->syncScopeItems($co, $data['scope_items'] ?? []);
            }

            unset($data['scope_items']);

            $co->fill($data)->save();

            // Pricing an emergency change order is a recordable act, not a field
            // edit. The value is the one thing the field user who captured the
            // signature is structurally barred from setting, and the one thing an
            // Admin reviews — so who set it, when, and that it changed at all have
            // to be in the chain rather than inferred from a timestamp.
            //
            // Keyed off wasChanged() rather than array_key_exists(), the
            // MaterialRequestService idiom: re-sending the same figure is not a
            // repricing and must not litter the chain with identical rows.
            //
            // Emergency only. The Normal flow's value moves at pending_document as
            // part of preparing the document, its audit chain is asserted
            // test-by-test in ChangeOrderAuditTest, and adding a verb to it would
            // change behaviour this redesign is required to leave alone.
            if ($co->wasChanged('value') && $this->isEmergency($co)) {
                $co->forceFill(['priced_by' => $user->id, 'priced_at' => now()])->save();

                $statusId = $co->change_order_status_id;
                $this->logApproval(
                    $co,
                    $user,
                    'price',
                    $statusId,
                    $statusId,
                    $co->value === null
                        ? 'Price cleared.'
                        : 'Priced at '.number_format((float) $co->value, 2, '.', ',').'.',
                );
            }

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

        // work_authorized joins the EXISTING PM/Admin arm rather than getting one
        // of its own. It is the emergency flow's pricing desk: the PM adds the
        // value and may revise the narrative before it goes anywhere, which is the
        // same latitude a PM already has at pending_pm and pending_document. The
        // field user who captured the signature is deliberately excluded — their
        // job ended when the GC representative signed.
        if ($status === self::PENDING_PM || $status === self::PENDING_DOCUMENT || $status === self::WORK_AUTHORIZED) {
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

        // An emergency change order never enters the Normal review chain. Without
        // this it could: a send-back would put it somewhere submit() accepts, and
        // submit() routes by ORIGINATOR — so a foreman-raised emergency CO would
        // land at pending_pm, a status with no pricing precondition at all, on work
        // that has already been built.
        if ($this->isEmergency($co)) {
            abort(409, 'An emergency change order is authorized on site and does not go through submission.');
        }

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

        // Unreachable while nothing puts an emergency CO in pending_pm, and
        // asserted rather than assumed: this is the Normal flow's PM review step,
        // and the emergency path has its own.
        if ($this->isEmergency($co)) {
            abort(409, 'An emergency change order does not take the project-manager validation step.');
        }

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

        // The emergency path produces its document at the send-to-GC step instead,
        // so this Normal-flow step must refuse it outright rather than by status.
        if ($this->isEmergency($co)) {
            abort(409, 'An emergency change order document is produced when it is sent to the general contractor.');
        }

        if ($from !== self::PENDING_DOCUMENT) {
            abort(409, "A change order in '{$from}' status is not awaiting its document.");
        }
        if (! $this->isPmOrAdmin($user)) {
            abort(403, 'Only a project manager or administrator can prepare the change order document.');
        }

        $gc = $this->assertReadyForGc($co, 'the change order document can be prepared');

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

        // The counter-signature is the Normal flow's binding step, between a
        // prepared document and the GC. An emergency change order has neither —
        // the on-site signature and the send-to-GC step stand in for both.
        if ($this->isEmergency($co)) {
            abort(409, 'An emergency change order is not counter-signed; it is sent to the general contractor once priced.');
        }

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
     * document. NOTHING IS SENT on the Normal flow: mailing an external party
     * automatically is not a decision this endpoint should make. The PM copies
     * this into their own mail client and attaches the PDF.
     *
     * Addressed via ChangeOrder::gcBlock(), i.e. the frozen gc_* snapshot with
     * the live project_general_contractors row as a fallback — see the
     * "To — General Contractor" panel on the document.
     *
     * NOTE: an earlier version of this docblock claimed there was "no gc_email
     * column anywhere in the schema". That was wrong — 2026_08_17_000002 adds
     * gc_email as part of the snapshot block, ProjectGeneralContractor::
     * toGcSnapshot() populates it, and the `to` key below reads it. The claim is
     * corrected here rather than deleted because it was the stated reason this
     * method sends nothing, and that reason does not hold.
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

        // Ordinary cancellation is for a change order nobody has acted on yet. An
        // emergency one has had work built against it on a signature already
        // given, and cancel() explicitly permits the ORIGINATOR — which would let
        // the very foreman who took the GC representative's signature erase the
        // authorization for work that exists. Voiding is the correct route, it is
        // PM/Admin only, and it demands a reason.
        if ($this->isEmergency($co)) {
            abort(409, 'An emergency change order cannot be cancelled. Void the on-site authorization instead, stating a reason.');
        }

        if (in_array($from, [self::ACTIVE, self::GC_REJECTED, self::REJECTED_INTERNAL, self::CANCELLED], true)) {
            abort(409, "A change order in '{$from}' status can no longer be cancelled.");
        }
        if (! $this->isPmLevel($user) && $co->originator_id !== $user->id) {
            abort(403, 'You are not allowed to cancel this change order.');
        }

        return $this->transition($co, $user, 'cancel', $from, self::CANCELLED, $comments);
    }

    // ---- Emergency-only transitions ----
    //
    // Four methods with their own endpoints rather than branches inside submit(),
    // approve(), sendBack() and setGcDecision(). That is the single most important
    // structural decision in this redesign, and it is not stylistic: each of those
    // Normal-flow methods hardcodes its DESTINATION. approve() always lands at
    // pending_document; sendBack() always lands at sent_back, which submit() then
    // accepts and routes BY ORIGINATOR — so a foreman-raised emergency change order
    // sent back would re-enter at pending_pm, a status with no pricing precondition
    // at all, on work that has already been built.
    //
    // Branching inside them would put emergency logic on the Normal flow's code
    // paths. Separate methods leave those byte-identical.

    /**
     * Route a priced emergency change order to an Admin for review.
     *
     * The conservative half of the fork: the PM has priced it and wants a second
     * pair of eyes before it reaches the General Contractor. `forward` is the apt
     * verb and was already permitted by the approvals CHECK, unused, since before
     * this work.
     *
     * Requires a value, because what an Admin reviews IS the value. Forwarding an
     * unpriced change order would ask them to approve nothing. The general
     * contractor is NOT required here — that becomes mandatory only at the point the
     * document is addressed to them.
     */
    public function forwardToAdmin(ChangeOrder $co, User $user, ?string $comments): ChangeOrder
    {
        $from = $this->assertEmergencyAt($co, [self::WORK_AUTHORIZED], 'forwarded for review');

        if (! $this->isPmOrAdmin($user)) {
            abort(403, 'Only a project manager or administrator can forward a change order for review.');
        }
        if ($co->value === null) {
            abort(422, 'Price the change order before forwarding it for review.');
        }

        return $this->transition($co, $user, 'forward', $from, self::PENDING_ADMIN, $comments);
    }

    /**
     * Return a priced emergency change order to the PM's pricing desk.
     *
     * Lands at work_authorized, NOT at sent_back. sent_back is the Normal flow's
     * "back to the originator to revise" state, and submit() accepts it — routing an
     * emergency change order there would let it re-enter the Normal chain. It also
     * names the wrong person: the Admin disagrees with the PRICE, which is the PM's
     * work, not the foreman's.
     *
     * A reason is mandatory. "Sent back" with no comment tells the PM nothing.
     */
    public function sendBackToPricing(ChangeOrder $co, User $user, string $comments): ChangeOrder
    {
        $from = $this->assertEmergencyAt($co, [self::PENDING_ADMIN], 'sent back for re-pricing');

        if (! $this->isAdmin($user)) {
            abort(403, 'Only an administrator can send a change order back for re-pricing.');
        }

        return $this->transition($co, $user, 'send_back', $from, self::WORK_AUTHORIZED, $comments);
    }

    /**
     * Send the priced change order to the General Contractor.
     *
     * The convergence point of both routes, which is why the permission split lives
     * HERE rather than at the route: one endpoint serves both, so the gate depends
     * on which status it is called from. Same reasoning as
     * PurchaseOrderService::assertMayRaiseStandalone().
     *
     *   from work_authorized   the PM is skipping Admin review, so they must hold
     *                          send_change_request_to_gc. Recorded as `pm_direct`.
     *   from pending_admin     an Admin reviewed it. Recorded as `admin_reviewed`.
     *
     * "Counts as approved" means INTERNALLY approved, and nothing more: the change
     * order moves to pending_gc with gc_decision still `pending`. Sending is not the
     * General Contractor answering. Writing a GC decision here would recreate, one
     * step later, the exact defect createEmergency() used to commit — recording a
     * decision no GC had made.
     *
     * Every precondition is checked BEFORE the status moves, so a change order is
     * never left marked as sent with nothing deliverable behind it. Same ordering as
     * PurchaseOrderService::send().
     */
    public function sendToGc(ChangeOrder $co, User $user, ?string $comments): ChangeOrder
    {
        $from = $this->assertEmergencyAt(
            $co,
            [self::WORK_AUTHORIZED, self::PENDING_ADMIN],
            'sent to the general contractor',
        );

        $via = $from === self::WORK_AUTHORIZED ? 'pm_direct' : 'admin_reviewed';

        if ($via === 'pm_direct') {
            if (! $this->isPmOrAdmin($user)) {
                abort(403, 'Only a project manager or administrator can send a change order to the general contractor.');
            }

            // The bypass gate. Held by no role: granted per user, revocable without
            // touching ordinary approval rights. Exactly finalize_material_request.
            $user->unsetRelation('permissions');

            if (! $user->can('send_change_request_to_gc')) {
                abort(403, 'You cannot send a change order straight to the general contractor. Forward it for review instead.');
            }
        } elseif (! $this->isAdmin($user)) {
            abort(403, 'Only an administrator can send a change order that is awaiting their review.');
        }

        $gc = $this->assertReadyForGc($co, 'the change order can be sent to the general contractor');

        // Resolved through gcBlock() rather than $co->gc_email so a row whose
        // snapshot predates this column still resolves via the live relation.
        $email = $co->gcBlock()['email'] ?? $gc->email;

        if (blank($email)) {
            abort(422, 'The general contractor has no email address on file; add one before sending the change order.');
        }

        return DB::transaction(function () use ($co, $user, $from, $comments, $via, $email) {
            $co->forceFill([
                'sent_to_gc_by' => $user->id,
                'sent_to_gc_at' => now(),
                'gc_sent_via' => $via,
            ])->save();

            $updated = $this->transition($co, $user, 'send_to_gc', $from, self::PENDING_GC, $comments);

            // Filed AFTER the stamps and the status move, so the document a General
            // Contractor receives shows the state the change order is actually in
            // and names whoever authorised it. Same ordering as counterSign().
            //
            // This is the FIRST change-order document an emergency change order
            // ever has: capture files none, because before a price exists the sheet
            // could only say "To be determined".
            $document = $this->pdf->storeFor($updated, $user->id);
            $updated->forceFill(['document_attachment_id' => $document->id])->save();

            $this->dispatchGcEmail($updated, $user, $email);

            return $updated->fresh(self::DETAIL_WITH);
        });
    }

    /**
     * Void the on-site authorization, because the signed record is defective.
     *
     * The honest remedy when a GC representative signed the wrong thing. Nothing can
     * EDIT what was signed — that immutability is the record's whole value — so the
     * change order is abandoned and the work re-captured on site under a fresh
     * signature.
     *
     * Deliberately NOT cancel():
     *
     *  - cancel() permits the ORIGINATOR, which here is the very foreman who took
     *    the signature. Work has been built on the strength of it; erasing it is not
     *    theirs to do. This is PM/Admin only.
     *  - A reason is mandatory. A voided authorization with no stated cause is worse
     *    than none, because it looks like an accident.
     *
     * It lands in `cancelled` rather than a status of its own — the change order is
     * over either way — and the distinction is carried by the `void_authorization`
     * verb. "We dropped this change" and "the paperwork was defective and was done
     * again" are materially different in a dispute, and the chain has to say which.
     *
     * The signature row is marked voided, NOT deleted. change_order_signatures uses
     * SoftDeletes, so deleting would hide the evidence behind a global scope, which
     * is the opposite of what a disputed signature needs. It stays readable and
     * simply stops authorising anything: ChangeOrder::currentSignature() skips it.
     */
    public function voidAuthorization(ChangeOrder $co, User $user, string $reason): ChangeOrder
    {
        $from = $this->assertEmergencyAt(
            $co,
            [self::WORK_AUTHORIZED, self::PENDING_ADMIN],
            'voided',
        );

        if (! $this->isPmOrAdmin($user)) {
            abort(403, 'Only a project manager or administrator can void an on-site authorization.');
        }

        return DB::transaction(function () use ($co, $user, $from, $reason) {
            $co->loadMissing('signatures');

            $signature = $co->currentSignature();

            if ($signature) {
                $signature->forceFill([
                    'voided_at' => now(),
                    'void_reason' => $reason,
                ])->save();
            }

            return $this->transition($co, $user, 'void_authorization', $from, self::CANCELLED, $reason);
        });
    }

    /**
     * Queue the change order to the General Contractor.
     *
     * Follows the purchase order's pattern exactly: insert a `queued` EmailLog inside
     * the caller's transaction, then dispatch a job carrying IDS ONLY, ->afterCommit()
     * so nothing is queued unless the transition itself commits. A job that ran before
     * the commit would load a change order that is not yet marked as sent.
     *
     * The EmailLog row is the delivery record. Nothing else in this system knows
     * whether an email actually reached a General Contractor, and for a change order
     * billing completed work that is the difference between "they have not replied"
     * and "they never received it".
     */
    private function dispatchGcEmail(ChangeOrder $co, User $user, string $email): void
    {
        $emailLog = EmailLog::create([
            'to_email' => $email,
            'subject' => "Change Order {$co->change_order_no} from ".config('company.name'),
            'template' => 'emails.change-order.issued',
            'mailable_type' => ChangeOrder::class,
            'mailable_id' => $co->id,
            'status' => 'queued',
        ]);

        SendChangeOrderEmailJob::dispatch($emailLog->id, $co->id)->afterCommit();
    }

    /**
     * The Admin oversight queue: change orders that have gone to a General
     * Contractor, and above all the ones that went WITHOUT Admin review.
     *
     * This is what "the Admin can see that a PM sent it direct" means in practice.
     * There is no notification system in this application — the notifications table
     * exists and nothing has ever written to it — so a pull-based queue is the honest
     * answer rather than a half-built push one. The status, gc_sent_via and the
     * approvals chain remain the source of truth; this is a view over them.
     *
     * Top-level rather than project-nested, like purchase-orders/pending-requests,
     * because the question it answers spans projects. Admin sees everything; a PM sees
     * the projects they are staffed on, which is the same scoping every other
     * cross-project read in this system uses.
     *
     * @param  array<string,mixed>  $filters
     */
    public function gcQueue(User $user, array $filters): LengthAwarePaginator
    {
        $query = ChangeOrder::query()->with(self::LIST_WITH);

        if (! $this->isAdmin($user)) {
            $query->whereIn('project_id', $this->accessibleProjectIds($user));
        }

        // Default: everything that has reached a General Contractor at all. Without
        // this the queue would list drafts, which is not what it is for.
        $query->whereNotNull('sent_to_gc_at');

        if (! empty($filters['gc_sent_via'])) {
            $query->where('gc_sent_via', $filters['gc_sent_via']);
        }

        if (! empty($filters['status_id'])) {
            $query->where('change_order_status_id', $filters['status_id']);
        }

        // Work built, payment refused. The most financially urgent rows in the
        // system: an emergency change order the GC has rejected AFTER the work was
        // authorised on site and carried out. A terminal status would otherwise let
        // them disappear from view entirely.
        if (! empty($filters['disputed'])) {
            $query->whereNotNull('work_authorized_at')
                ->whereHas('status', fn ($q) => $q->where('code', self::GC_REJECTED));
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        return $query->orderByDesc('sent_to_gc_at')->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Shared precondition for every emergency-only transition: it IS an emergency
     * change order, and it is in one of the statuses this act is valid from.
     *
     * One place, so eight endpoints cannot drift apart on how they ask or on what
     * they say when they refuse.
     *
     * @param  array<int,string>  $allowedFrom
     * @return string the current status code
     */
    private function assertEmergencyAt(ChangeOrder $co, array $allowedFrom, string $act): string
    {
        if (! $this->isEmergency($co)) {
            abort(409, "Only an emergency change order can be {$act}.");
        }

        $from = $this->statusCode($co);

        if (! in_array($from, $allowedFrom, true)) {
            abort(409, "A change order in '{$from}' status cannot be {$act}.");
        }

        return $from;
    }

    // ---- Internals ----

    /**
     * The two things a change order must have before anything can go to the GC:
     * a figure, and a counterparty to address it to.
     *
     * Extracted from prepareDocument(), where both checks used to live inline,
     * because the emergency flow reaches the GC by a different route and must
     * enforce the identical pair. A change order that states no amount asks the
     * GC to agree to nothing, and one with no GC produces a sheet addressed to
     * nobody — neither is a document worth generating, let alone sending.
     *
     * `$clause` completes the sentence "... before {$clause}." so each caller can
     * name its own step without duplicating the rule.
     *
     * Returns the GC so the caller can snapshot it without a second lookup. The
     * row is re-read rather than taken from the relation because a soft-deleted
     * GC must count as absent.
     */
    private function assertReadyForGc(ChangeOrder $co, string $clause): ProjectGeneralContractor
    {
        if ($co->value === null) {
            abort(422, "A value must be set before {$clause}.");
        }

        $gc = $co->general_contractor_id
            ? ProjectGeneralContractor::find($co->general_contractor_id)
            : null;

        if (! $gc) {
            abort(422, "A general contractor must be selected before {$clause}.");
        }

        return $gc;
    }

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

    /**
     * Whether this change order took the on-site emergency path.
     *
     * The single place the type is resolved, so the emergency-only guards cannot
     * drift apart on how they ask. Falls back to a lookup when the relation is not
     * loaded, exactly as statusCode() does.
     */
    private function isEmergency(ChangeOrder $co): bool
    {
        $code = $co->type?->code ?? ChangeOrderType::whereKey($co->change_order_type_id)->value('code');

        return $code === 'emergency';
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
