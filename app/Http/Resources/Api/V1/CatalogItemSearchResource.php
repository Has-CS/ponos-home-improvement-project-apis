<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row in the material-request catalog type-ahead.
 *
 * Intentionally NOT a reuse of CatalogItemListResource. Two reasons:
 *
 *  - It carries `description`, which the picker displays and the list resource
 *    omits.
 *  - Safety. This endpoint is reachable by roles that do NOT hold view_pricing
 *    (Foreman, Site Engineer, Project Coordinator). Keeping a dedicated resource
 *    means that if pricing is ever added to the list resource — which serves the
 *    pricing-gated admin list, where that would be perfectly legitimate — it
 *    cannot silently leak out through here.
 *
 * Nothing price-bearing belongs in this shape: no rate, no vendor, no
 * current_vendor_rates.
 */
class CatalogItemSearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'trade_category' => $this->whenLoaded('tradeCategory', fn () => [
                'id' => $this->tradeCategory->id,
                'name' => $this->tradeCategory->name,
            ]),
            'catalog_item_type' => $this->whenLoaded('catalogItemType', fn () => [
                'id' => $this->catalogItemType->id,
                'code' => $this->catalogItemType->code,
                'label' => $this->catalogItemType->label,
            ]),
            'default_unit' => $this->whenLoaded('defaultUnit', fn () => [
                'id' => $this->defaultUnit->id,
                'code' => $this->defaultUnit->code,
                'label' => $this->defaultUnit->label,
            ]),
            'is_custom' => (bool) $this->is_custom,
        ];
    }
}
