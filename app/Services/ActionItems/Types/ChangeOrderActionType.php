<?php

namespace App\Services\ActionItems\Types;

use App\Models\ChangeOrder;
use App\Services\ActionItems\ActionItemType;
use Illuminate\Database\Eloquent\Model;

/** Change-order queues. Reads are membership-scoped (`project.access`). */
abstract class ChangeOrderActionType extends ActionItemType
{
    public function subject(): string
    {
        return ChangeOrder::class;
    }

    public function subjectType(): string
    {
        return 'change_order';
    }

    public function section(): string
    {
        return 'change_orders';
    }

    protected function previewWith(): array
    {
        return ['project:id,code,name', 'status:id,code', 'type:id,code'];
    }

    protected function describe(Model $item): array
    {
        return ['number' => $item->change_order_no, 'title' => $item->title, 'status' => $item->status?->code];
    }

    abstract protected function statusCode(): ?string;

    public function link(): array
    {
        return $this->statusLink('change_orders', 'co', $this->statusCode());
    }
}
