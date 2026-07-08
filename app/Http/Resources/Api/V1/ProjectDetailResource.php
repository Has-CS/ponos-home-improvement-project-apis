<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'code'         => $this->code,
            'name'         => $this->name,
            'site_address' => $this->site_address,
            'budget'       => $this->budget,
            'start_date'   => $this->start_date?->toDateString(),
            'end_date'     => $this->end_date?->toDateString(),
            'client' => $this->whenLoaded('client', fn() => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ] : null),
            'type'   => $this->whenLoaded('type', fn() => $this->type ? [
                'id' => $this->type->id,
                'code' => $this->type->code,
                'label' => $this->type->label,
            ] : null),
            'status' => $this->whenLoaded('status', fn() => $this->status ? [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'label' => $this->status->label,
                'is_terminal' => (bool) $this->status->is_terminal,
            ] : null),
            'members_count'   => $this->whenCounted('members'),
            'milestones_count' => $this->whenCounted('milestones'),
            // Cross-module counts (linked by project_id) — cheap situational awareness.
            'material_requests_count' => $this->whenCounted('materialRequests'),
            'daily_logs_count'        => $this->whenCounted('dailyLogs'),
            'issues_count'            => $this->whenCounted('issues'),
            'change_orders_count'     => $this->whenCounted('changeOrders'),
            'created_by' => $this->whenLoaded('creator', fn() => $this->creator ? [
                'id' => $this->creator->id,
                'name' => trim("{$this->creator->first_name} {$this->creator->last_name}"),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
