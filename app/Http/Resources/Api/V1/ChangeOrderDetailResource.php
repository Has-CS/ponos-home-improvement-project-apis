<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChangeOrderDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'change_order_no' => $this->change_order_no,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'description' => $this->description,
            'scope' => $this->scope,
            // The Scope of Work table, in print order. `printed_unit` and
            // `printed_quantity` are pre-formatted so the API and the PDF cannot
            // disagree about how a row reads.
            'scope_items' => $this->whenLoaded('scopeItems', fn () => $this->scopeItems->map(fn ($row) => [
                'id' => $row->id,
                'row_type' => $row->row_type,
                'label' => $row->label,
                'quantity' => $row->quantity,
                'printed_quantity' => $row->printedQuantity(),
                'unit_id' => $row->unit_id,
                'printed_unit' => $row->printedUnit(),
                'indent' => $row->indent,
                'sort_order' => $row->sort_order,
            ])->values()),
            'inclusions' => $this->inclusions,
            'exclusions' => $this->exclusions,
            // Pre-split so the API and the PDF break the text into entries by
            // identical rules — neither re-derives it.
            'inclusion_list' => $this->inclusionList(),
            'exclusion_list' => $this->exclusionList(),
            'location' => $this->location,

            // The narrative AS SIGNED on site, frozen at capture and never
            // touched again. Null on every Normal change order, and on
            // grandfathered emergency ones raised before the snapshot existed.
            // `signed_narrative_diverges` is what lets a reviewer see at a glance
            // that the billed scope is no longer word-for-word the authorised one.
            'signed' => $this->signedBlock(),
            'signed_narrative_diverges' => $this->signedNarrativeDiverges(),

            'value' => $this->value,
            'awaiting_pricing' => $this->isAwaitingPricing(),
            'general_contractor_id' => $this->general_contractor_id,
            // Snapshot-first, falling back to the live record before the
            // document is prepared. Once prepared this stops moving, even if the
            // underlying GC record is later edited.
            'general_contractor' => $this->gcBlock(),
            'gc_snapshot_taken' => $this->hasGcSnapshot(),
            // The standing contractual text as frozen onto this change order.
            // Null-ish until the document is prepared; never read through to the
            // live terms row, so it stops moving once issued.
            'terms_id' => $this->terms_id,
            'terms' => $this->termsParagraphs(),
            'type' => $this->whenLoaded('type', fn () => [
                'id' => $this->type->id,
                'code' => $this->type->code,
                'label' => $this->type->label,
            ]),
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'label' => $this->status->label,
                'is_terminal' => (bool) $this->status->is_terminal,
            ]),
            'gc_decision' => $this->whenLoaded('gcDecision', fn () => [
                'id' => $this->gcDecision->id,
                'code' => $this->gcDecision->code,
                'label' => $this->gcDecision->label,
            ]),
            'cost_code' => $this->whenLoaded('costCode', fn () => $this->costCode ? [
                'id' => $this->costCode->id,
                'code' => $this->costCode->code,
                'name' => $this->costCode->name,
            ] : null),
            'urgency' => $this->whenLoaded('urgency', fn () => $this->urgency ? [
                'id' => $this->urgency->id,
                'code' => $this->urgency->code,
                'label' => $this->urgency->label,
            ] : null),
            'originator' => $this->whenLoaded('originator', fn () => $this->originator ? [
                'id' => $this->originator->id,
                'name' => trim("{$this->originator->first_name} {$this->originator->last_name}"),
            ] : null),
            'counter_signed_by' => $this->whenLoaded('counterSignedBy', fn () => $this->counterSignedBy ? [
                'id' => $this->counterSignedBy->id,
                'name' => trim("{$this->counterSignedBy->first_name} {$this->counterSignedBy->last_name}"),
            ] : null),
            'counter_signed_at' => $this->counter_signed_at?->toIso8601String(),
            'gc_decision_by' => $this->whenLoaded('gcDecisionBy', fn () => $this->gcDecisionBy ? [
                'id' => $this->gcDecisionBy->id,
                'name' => trim("{$this->gcDecisionBy->first_name} {$this->gcDecisionBy->last_name}"),
            ] : null),
            'gc_decision_at' => $this->gc_decision_at?->toIso8601String(),
            'gc_decision_notes' => $this->gc_decision_notes,
            'became_active_at' => $this->became_active_at?->toIso8601String(),

            // The emergency lifecycle: work authorised on site, then priced, then
            // sent to the GC. work_authorized_at is SERVER time; the device's own
            // reading is signatures[].signed_at, which a device may backdate.
            'work_authorized_at' => $this->work_authorized_at?->toIso8601String(),
            'priced_by' => $this->whenLoaded('pricedBy', fn () => $this->pricedBy ? [
                'id' => $this->pricedBy->id,
                'name' => trim("{$this->pricedBy->first_name} {$this->pricedBy->last_name}"),
            ] : null),
            'priced_at' => $this->priced_at?->toIso8601String(),
            'sent_to_gc_by' => $this->whenLoaded('sentToGcBy', fn () => $this->sentToGcBy ? [
                'id' => $this->sentToGcBy->id,
                'name' => trim("{$this->sentToGcBy->first_name} {$this->sentToGcBy->last_name}"),
            ] : null),
            'sent_to_gc_at' => $this->sent_to_gc_at?->toIso8601String(),
            // 'pm_direct' means a project manager sent it without Admin review —
            // the bypass the send_change_request_to_gc permission governs.
            'gc_sent_via' => $this->gc_sent_via,

            'document_attachment_id' => $this->document_attachment_id,
            // The generated change-order document, filed at send-to-GC.
            // Null before that.
            'document' => $this->whenLoaded('documentAttachment', fn () => $this->documentAttachment ? [
                'id' => $this->documentAttachment->id,
                'file_name' => $this->documentAttachment->file_name,
                'size_bytes' => $this->documentAttachment->size_bytes,
                'download_url' => url("/api/v1/attachments/{$this->documentAttachment->id}"),
            ] : null),
            'authorization_chain' => ChangeOrderApprovalResource::collection($this->whenLoaded('approvals')),
            'signatures' => ChangeOrderSignatureResource::collection($this->whenLoaded('signatures')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
