<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialRequestDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_no' => $this->request_no,
            'title' => $this->title,
            'project_id' => $this->project_id,
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'label' => $this->status->label,
                'is_terminal' => (bool) $this->status->is_terminal,
            ]),
            'urgency' => $this->whenLoaded('urgency', fn () => [
                'id' => $this->urgency->id,
                'code' => $this->urgency->code,
                'label' => $this->urgency->label,
            ]),
            'requester' => $this->whenLoaded('requester', fn () => $this->requester ? [
                'id' => $this->requester->id,
                'name' => trim("{$this->requester->first_name} {$this->requester->last_name}"),
            ] : null),
            'needed_by_date' => $this->needed_by_date?->toDateString(),
            'notes' => $this->notes,

            // The foreman's own words, frozen at submit. On a prose-only request
            // this — plus the photos — is what Procurement maps to catalog items
            // when they cut the PO.
            'request_text' => $this->request_text,
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($p) => [
                'id' => $p->id,
                'file_name' => $p->file_name,
                'mime_type' => $p->mime_type,
                'size_bytes' => $p->size_bytes,
                'url' => url("/api/v1/attachments/{$p->id}"),
            ])),
            'structured_by' => $this->whenLoaded('structuredBy', fn () => $this->structuredBy ? [
                'id' => $this->structuredBy->id,
                'name' => trim("{$this->structuredBy->first_name} {$this->structuredBy->last_name}"),
            ] : null),
            'structured_at' => $this->structured_at?->toIso8601String(),
            // Derived, never stored — see MaterialRequest::needsStructuring().
            'needs_structuring' => $this->whenLoaded('items', fn () => filled($this->request_text) && $this->items->isEmpty()),

            'items' => MaterialRequestItemResource::collection($this->whenLoaded('items')),
            'approvals' => MaterialRequestApprovalResource::collection($this->whenLoaded('approvals')),

            // Line-item edits made after the request left draft. Separate from
            // `approvals`, which stays purely about status transitions: this is
            // "a reviewer changed someone else's request, here is exactly what".
            'change_log' => $this->whenLoaded('activityLogs', fn () => $this->activityLogs->map(fn ($a) => [
                'id' => $a->id,
                'event' => $a->event,
                'description' => $a->description,
                'item_id' => $a->properties['item_id'] ?? null,
                'old' => $a->properties['old'] ?? null,
                'new' => $a->properties['new'] ?? null,
                'causer' => $a->relationLoaded('causer') && $a->causer ? [
                    'id' => $a->causer->id,
                    'name' => trim("{$a->causer->first_name} {$a->causer->last_name}"),
                ] : null,
                'created_at' => $a->created_at?->toIso8601String(),
            ])),
            'purchase_orders' => $this->whenLoaded('purchaseOrders', fn () => $this->purchaseOrders->map(fn ($po) => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'total_amount' => $po->total_amount,
                'purchase_order_status_id' => $po->purchase_order_status_id,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
