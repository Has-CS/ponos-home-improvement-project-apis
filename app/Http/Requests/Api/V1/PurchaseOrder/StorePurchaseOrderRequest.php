<?php

namespace App\Http\Requests\Api\V1\PurchaseOrder;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\MaterialRequestStatus;
use App\Models\Vendor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'material_request_id' => ['required', 'integer', Rule::exists('material_requests', 'id')->whereNull('deleted_at')],
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->whereNull('deleted_at')],

            // Optional here, mandatory at issue (PurchaseOrderService::issue()).
            // Omitting it falls back to the project's primary address. The
            // "belongs to this project" check lives in the service, which is
            // where the material request — and therefore the project — is
            // resolved; this rule only proves the row exists.
            'ship_to_address_id' => ['nullable', 'integer', Rule::exists('project_delivery_addresses', 'id')->whereNull('deleted_at')],

            'expected_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],

            // One definition of a PO line, shared with the add-a-line endpoint —
            // same arrangement StoreMaterialRequestRequest has with
            // StoreMaterialRequestItemRequest::lineRules().
            ...StorePurchaseOrderItemRequest::lineRules('items.*.'),
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $this->filled('material_request_id')) {
                return;
            }

            $mr = MaterialRequest::with('status')->find($this->input('material_request_id'));
            $code = $mr?->status?->code
                ?? MaterialRequestStatus::whereKey($mr?->material_request_status_id)->value('code');

            if (! in_array($code, ['approved', 'ordered'], true)) {
                $v->errors()->add('material_request_id', 'A purchase order can only be created from an approved material request.');
            }

            // A retired vendor is never offered for NEW selection — the same rule
            // the catalog search applies to retired items. Checked here rather
            // than folded into the exists rule above so the buyer is told WHY,
            // instead of getting "the selected vendor id is invalid". Existing
            // orders against a vendor retired later are untouched.
            if ($this->filled('vendor_id')) {
                $vendor = Vendor::find($this->input('vendor_id'));

                if ($vendor && ! $vendor->is_active) {
                    $v->errors()->add('vendor_id', 'This vendor is inactive and cannot receive new purchase orders.');
                }
            }

            if (! $mr) {
                return;
            }

            // A PO line may name the requested line it fulfils, but only one of
            // THIS request's lines. Without this, an id from any other request —
            // any other project — was accepted and stored, silently attributing
            // ordered quantities to the wrong request. Same shape as
            // StoreDeliveryRequest, which validates purchase_order_item_id
            // against its own PO. Reuses the $mr already loaded above, and the
            // relation excludes soft-deleted lines, matching the exists rule.
            $requestLines = $mr->items()->get(['id', 'description', 'catalog_item_id', 'quantity']);
            $validItemIds = $requestLines->pluck('id')->all();
            $lines = (array) $this->input('items', []);

            // Quantities claimed against each requested line by THIS payload.
            // Summed, not checked line by line: two lines of 15 against a request
            // for 20 each pass alone but must fail together, and splitting one
            // requested line across two PO lines is legitimate.
            $claimed = [];

            foreach ($lines as $i => $line) {
                $itemId = $line['material_request_item_id'] ?? null;

                if ($itemId === null) {
                    // Optional only while the request carries no lines of its own
                    // — a prose request mapped straight onto PO lines has nothing
                    // to point at. Once it HAS lines, an unlinked PO line would
                    // escape the over-order check entirely.
                    if ($validItemIds !== []) {
                        $v->errors()->add(
                            "items.{$i}.material_request_item_id",
                            'This material request has line items, so every purchase order line must name the requested line it fulfils.',
                        );
                    }

                    continue;
                }

                if (! in_array((int) $itemId, $validItemIds, true)) {
                    $v->errors()->add(
                        "items.{$i}.material_request_item_id",
                        'This line does not belong to the material request this purchase order is for.',
                    );

                    continue;
                }

                $claimed[(int) $itemId] = bcadd(
                    $claimed[(int) $itemId] ?? '0',
                    (string) ($line['quantity_ordered'] ?? 0),
                    3,
                );
            }

            if ($claimed === []) {
                return;
            }

            // Cumulative across every PO already raised from this request — a
            // single PO's own quantity proves nothing when one request may be
            // split over several vendors.
            $alreadyOrdered = MaterialRequestItem::orderedQuantities(array_keys($claimed));

            foreach ($claimed as $itemId => $claiming) {
                $requestLine = $requestLines->firstWhere('id', $itemId);
                $already = $alreadyOrdered[$itemId] ?? '0';
                $requested = (string) $requestLine->quantity;

                if (bccomp(bcadd($already, $claiming, 3), $requested, 3) <= 0) {
                    continue;
                }

                $remaining = bccomp($requested, $already, 3) > 0
                    ? bcsub($requested, $already, 3)
                    : '0';

                $label = $requestLine->description
                    ?: ($requestLine->catalog_item_id
                        ? (CatalogItem::whereKey($requestLine->catalog_item_id)->value('sku') ?? "line #{$itemId}")
                        : "line #{$itemId}");

                $message = sprintf(
                    'Ordering %s exceeds what was requested for %s: requested %s, already ordered %s, remaining %s.',
                    $this->trimQty($claiming),
                    $label,
                    $this->trimQty($requested),
                    $this->trimQty($already),
                    $this->trimQty($remaining),
                );

                // Reported on EVERY payload line naming this requested line, so a
                // client can highlight each row that contributed to the overage.
                foreach ($lines as $i => $line) {
                    if ((int) ($line['material_request_item_id'] ?? 0) === $itemId) {
                        $v->errors()->add("items.{$i}.quantity_ordered", $message);
                    }
                }
            }
        });
    }

    /**
     * Quantities read back to a human: "20", not "20.000". Same trimming the PDF
     * templates and the API resources use for decimal(14,3) quantities.
     */
    private function trimQty(string $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.') ?: '0';
    }

    protected function failedValidation(Validator $v): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $v->errors(),
        ], 422));
    }
}
