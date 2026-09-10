<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CatalogItemListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            // Product photo, or null. Public-disk URL derived here rather than
            // stored, exactly as UserDetailResource does for an avatar.
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
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
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
