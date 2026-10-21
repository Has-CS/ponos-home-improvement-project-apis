<?php

namespace App\Services\Dashboard\Sections;

use App\Services\ActionItems\ActionContext;
use App\Services\Dashboard\DashboardScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Change-order pipeline. Membership scope, like every CO read endpoint. */
class ChangeOrdersSection implements DashboardSection
{
    public function key(): string
    {
        return 'change_orders';
    }

    public function applies(DashboardScope $scope): bool
    {
        return $scope->canAny(['create_change_request', 'approve_change_request']);
    }

    public function cacheable(): bool
    {
        return false;
    }

    public function build(DashboardScope $scope): ?array
    {
        $base = fn () => ActionContext::applyIds(DB::table('change_orders')->whereNull('deleted_at'), $scope->ctx->memberProjectIds());
        $codes = $scope->statuses->codes('co');
        $typeCodes = $scope->statuses->codes('co_type');
        $byStatus = fn ($q) => $q->selectRaw('change_order_status_id as s, count(*) as c')->groupBy('change_order_status_id')
            ->pluck('c', 's')->mapWithKeys(fn ($c, $id) => [$codes[$id] ?? (string) $id => (int) $c])->all();

        $gc = $base()->where('change_order_status_id', $scope->statuses->id('co', 'pending_gc'))
            ->selectRaw('count(*) as c, min(sent_to_gc_at) as oldest')->first();

        $out = [
            'by_status' => $byStatus($base()),
            'by_type' => $base()->selectRaw('change_order_type_id as t, count(*) as c')->groupBy('change_order_type_id')
                ->pluck('c', 't')->mapWithKeys(fn ($c, $id) => [$typeCodes[$id] ?? (string) $id => (int) $c])->all(),
            'awaiting_gc' => [
                'count' => (int) $gc->c,
                'oldest_days' => $gc->oldest ? (int) Carbon::parse($gc->oldest)->startOfDay()->diffInDays(today(), true) : null,
            ],
            'created_in_range' => $scope->inRange($base(), 'created_at')->count(),
            'emergency_captured_in_range' => $scope->inRange($base(), 'work_authorized_at')->count(),
        ];

        if ($scope->can('create_change_request')) {
            $out['mine'] = ['by_status' => $byStatus($base()->where('originator_id', $scope->user()->id))];
        }

        return $out;
    }
}
