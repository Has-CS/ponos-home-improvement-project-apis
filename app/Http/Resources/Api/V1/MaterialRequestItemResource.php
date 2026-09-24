<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One material-request line.
 *
 * Three text fields with three distinct jobs — do not conflate them:
 *
 *  - `description`            WHAT the line is, but only when no catalog item
 *                             says so. It is the item's identity on a free-text
 *                             line ("2x 8ft pressure-treated 4x4"), which is why
 *                             a DB CHECK requires either it or catalog_item_id.
 *                             NULL on a catalog line is correct, not missing data.
 *  - `notes`                  WHY/HOW, for this line on this request
 *                             ("For Electricity").
 *  - `catalog_item.description` The catalog's own standard blurb for the product.
 *
 * Clients wanting one line of prose should render
 * `description ?? catalog_item.description`, and can still tell which they got.
 * The catalog description is deliberately NOT merged into `description` server-
 * side: that would erase the free-text-vs-catalog distinction the line model
 * rests on.
 */
class MaterialRequestItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            // Cast so JSON emits a number and drops the stored trailing zeros:
            // decimal(14,3) reaches PHP as the string "50.000". Quantities are
            // genuinely fractional (2.5 CY, 0.75 hr), so the third decimal is
            // kept — unlike money, which stays fixed at 2dp. Cast HERE and not
            // in $casts: the model attribute feeds ActivityLogger snapshots,
            // which must keep recording the exact stored value.
            'quantity' => (float) $this->quantity,

            // Present only on the buyer-facing reads, where
            // MaterialRequestItem::attachOrderedQuantities() has stamped them:
            // how much of this line is already on order across every purchase
            // order, and how much may still be ordered. The material-request
            // module's own payloads are unchanged.
            $this->mergeWhen($this->ordered_quantity !== null, fn () => [
                'ordered_quantity' => (float) $this->ordered_quantity,
                'remaining_quantity' => (float) $this->remaining_quantity,
            ]),
            'notes' => $this->notes,
            'sort_order' => $this->sort_order,
            'cost_code' => $this->whenLoaded('costCode', fn () => [
                'id' => $this->costCode->id,
                'code' => $this->costCode->code,
                'name' => $this->costCode->name,
            ]),
            'catalog_item' => $this->whenLoaded('catalogItem', fn () => $this->catalogItem ? [
                'id' => $this->catalogItem->id,
                'name' => $this->catalogItem->name,
                'sku' => $this->catalogItem->sku,
                // The relation is already eager-loaded everywhere this resource
                // is used (MaterialRequestService::DETAIL_WITH and the add/update
                // item loads), so surfacing this costs no extra query.
                'description' => $this->catalogItem->description,
            ] : null),
            'trade_category' => $this->whenLoaded('tradeCategory', fn () => $this->tradeCategory ? [
                'id' => $this->tradeCategory->id,
                'name' => $this->tradeCategory->name,
            ] : null),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'code' => $this->unit->code,
                'label' => $this->unit->label,
            ]),
        ];
    }
}
