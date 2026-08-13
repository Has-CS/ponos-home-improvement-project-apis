<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'project_id' => $this->project_id,
            'material_request_id' => $this->material_request_id,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->id,
                'name' => $this->vendor->name,
            ]),
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'label' => $this->status->label,
                'is_terminal' => (bool) $this->status->is_terminal,
            ]),
            'total_amount' => $this->total_amount,
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),

            // Ship To / Deliver To. Every value here is the SNAPSHOT taken when
            // the PO was created, not a live read of the address row — editing
            // or deleting that row later cannot change what this order says.
            //
            // `address_id` is traceability only (which site was picked, so a UI
            // can re-select it on a draft); `formatted_lines` is the block
            // exactly as it prints, formatted once in
            // PurchaseOrder::shipToLines() so the API and the eventual PDF can
            // never disagree about layout.
            // Gated by hasShipTo() rather than a column test, so this and the
            // printed block agree on what counts as "has a destination" — a
            // contact-led address has no street but is still a real address.
            'ship_to' => ! $this->hasShipTo() ? null : [
                'address_id' => $this->ship_to_address_id,
                'label' => $this->ship_to_label,
                'attention' => $this->ship_to_attention,
                'street_1' => $this->ship_to_street_1,
                'street_2' => $this->ship_to_street_2,
                'city' => $this->ship_to_city,
                'state' => $this->ship_to_state,
                'postal_code' => $this->ship_to_postal_code,
                'country' => $this->ship_to_country,
                'contact_phone' => $this->ship_to_contact_phone,
                'delivery_notes' => $this->ship_to_delivery_notes,
                'project_name' => $this->ship_to_project_name,
                'project_code' => $this->ship_to_project_code,
                'formatted_lines' => $this->shipToLines(),
            ],

            // Terms & Conditions, frozen at issue. Like ship_to above, every
            // value is the SNAPSHOT — revising the company's standard terms
            // cannot change what this order was issued under.
            //
            // `clauses` is the print-ready array; `title` may be null, in which
            // case the document falls back to a literal "TERMS & CONDITIONS".
            'terms' => $this->terms_body === null ? null : [
                'terms_id' => $this->terms_id,
                'title' => $this->terms_title,
                'body' => $this->terms_body,
                'clauses' => $this->termsClauses(),
            ],
            'issued_by' => $this->whenLoaded('issuedBy', fn () => $this->issuedBy ? [
                'id' => $this->issuedBy->id,
                'name' => trim("{$this->issuedBy->first_name} {$this->issuedBy->last_name}"),
            ] : null),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'notes' => $this->notes,

            // The originating request, including the requester's raw words and
            // photos. On a prose-only request this is the source the PO lines
            // were derived from, so it stays attached for reference and audit.
            'material_request' => $this->whenLoaded('materialRequest', fn () => $this->materialRequest ? [
                'id' => $this->materialRequest->id,
                'request_no' => $this->materialRequest->request_no,
                'request_text' => $this->materialRequest->request_text,
                'notes' => $this->materialRequest->notes,
                'photos' => $this->materialRequest->relationLoaded('photos')
                    ? $this->materialRequest->photos->map(fn ($p) => [
                        'id' => $p->id,
                        'file_name' => $p->file_name,
                        'mime_type' => $p->mime_type,
                        'url' => url("/api/v1/attachments/{$p->id}"),
                    ])
                    : null,
                'items' => MaterialRequestItemResource::collection(
                    $this->materialRequest->relationLoaded('items') ? $this->materialRequest->items : collect()
                ),
            ] : null),

            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'deliveries' => $this->whenLoaded('deliveries', fn () => $this->deliveries->map(fn ($d) => [
                'id' => $d->id,
                'received_at' => $d->received_at?->toIso8601String(),
                'has_discrepancy' => (bool) $d->has_discrepancy,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
