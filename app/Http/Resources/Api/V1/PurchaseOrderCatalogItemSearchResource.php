<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;

/**
 * One row in the purchase-order catalog type-ahead.
 *
 * Extends the material-request picker's row and adds pricing, rather than
 * copying the shape — so a field added there appears here automatically and the
 * two pickers cannot drift.
 *
 * The pricing is the whole point of the subclass. Without it a buyer picks items
 * blind and only discovers a vendor has no rate when
 * PurchaseOrderService::persistLine() rejects the submission with
 * "No current vendor rate for catalog item #X; provide a unit_price."
 *
 * SAFETY: this class must only ever be used on routes gated by view_pricing.
 * The parent stays deliberately price-free because Foremen and Site Engineers
 * reach the material-request picker without that permission — see the note on
 * CatalogItemSearchResource.
 */
class PurchaseOrderCatalogItemSearchResource extends CatalogItemSearchResource
{
    public function toArray(Request $request): array
    {
        // Loaded only when the caller named a vendor, and constrained to that
        // vendor in CatalogItemService::search(). At most one row: an item has a
        // single open rate per vendor (vendor_rates_open_unique).
        $rate = $this->whenLoaded('currentVendorRates', fn () => $this->currentVendorRates->first(), null);

        return [
            ...parent::toArray($request),

            // Unpriced items are RETURNED and flagged, never hidden: buying
            // off-rate-card by supplying an explicit unit_price is a supported
            // path in persistLine(), and hiding those items would block it.
            'has_rate' => $rate !== null,
            'current_rate' => $rate ? [
                'vendor_rate_id' => $rate->id,
                'rate' => $rate->rate,
                'currency' => $rate->currency,
                'effective_from' => $rate->effective_from?->toDateString(),
            ] : null,
        ];
    }
}
