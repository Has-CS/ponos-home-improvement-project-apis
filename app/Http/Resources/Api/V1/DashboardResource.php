<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The dashboard is computed, not a model: DashboardService already returns the
 * final shape (money as 2-dp strings, sections omitted when they don't apply).
 * This resource keeps the controller → Resource → envelope convention and is
 * the place to reshape for clients without touching the aggregation code.
 */
class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
