<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact_name' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => (bool) $this->is_active,
            // The trades this vendor supplies, in the taxonomy's own sort_order.
            // Eager-loaded by VendorService::paginate() for the whole page —
            // never fetched per row.
            'trade_categories' => $this->whenLoaded('tradeCategories', fn () => $this->tradeCategories
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])
                ->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
