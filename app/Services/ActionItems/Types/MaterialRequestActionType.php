<?php

namespace App\Services\ActionItems\Types;

use App\Models\MaterialRequest;
use App\Services\ActionItems\ActionItemType;
use Illuminate\Database\Eloquent\Model;

/** Material-request queues. Reads are membership-scoped (`project.access`) unless a type says otherwise. */
abstract class MaterialRequestActionType extends ActionItemType
{
    public function subject(): string
    {
        return MaterialRequest::class;
    }

    public function subjectType(): string
    {
        return 'material_request';
    }

    public function section(): string
    {
        return 'procurement';
    }

    protected function previewWith(): array
    {
        return ['project:id,code,name', 'status:id,code', 'requester:id,first_name,last_name'];
    }

    protected function describe(Model $item): array
    {
        return ['number' => $item->request_no, 'title' => $item->title, 'status' => $item->status?->code];
    }

    /** The single status this queue lists, for the link filter; null when it spans several. */
    abstract protected function statusCode(): ?string;

    public function link(): array
    {
        return $this->statusLink('material_requests', 'mr', $this->statusCode());
    }
}
