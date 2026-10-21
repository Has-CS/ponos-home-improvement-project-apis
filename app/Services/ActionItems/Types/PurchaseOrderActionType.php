<?php

namespace App\Services\ActionItems\Types;

use App\Models\PurchaseOrder;
use App\Services\ActionItems\ActionItemType;
use Illuminate\Database\Eloquent\Model;

/** Purchase-order queues. Procurement-desk scoped, like PurchaseOrderService::paginate(). */
abstract class PurchaseOrderActionType extends ActionItemType
{
    public function subject(): string
    {
        return PurchaseOrder::class;
    }

    public function subjectType(): string
    {
        return 'purchase_order';
    }

    public function section(): string
    {
        return 'procurement';
    }

    protected function previewWith(): array
    {
        return ['project:id,code,name', 'status:id,code', 'vendor:id,name'];
    }

    protected function describe(Model $item): array
    {
        return ['number' => $item->po_number, 'title' => $item->vendor?->name, 'status' => $item->status?->code];
    }

    abstract protected function statusCode(): ?string;

    public function link(): array
    {
        return $this->statusLink('purchase_orders', 'po', $this->statusCode());
    }
}
