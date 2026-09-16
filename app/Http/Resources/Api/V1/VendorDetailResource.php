<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact_name' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'is_active' => (bool) $this->is_active,
            // Same {id, name} shape CatalogItem uses for its trade_category, so
            // the two read alike. Loaded via VendorService::DETAIL_WITH.
            'trade_categories' => $this->whenLoaded('tradeCategories', fn () => $this->tradeCategories
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])
                ->values()),
            'notes' => $this->notes,
            'vendor_rates_count' => $this->whenCounted('vendorRates'),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => trim("{$this->creator->first_name} {$this->creator->last_name}"),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
