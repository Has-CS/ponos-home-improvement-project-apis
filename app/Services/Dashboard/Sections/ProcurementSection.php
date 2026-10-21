<?php

namespace App\Services\Dashboard\Sections;

use App\Services\ActionItems\ActionContext;
use App\Services\ActionItems\Types\PoOverdueDelivery;
use App\Services\Dashboard\DashboardScope;
use Illuminate\Support\Facades\DB;

/**
 * Material requests → purchase orders → deliveries → RFQs. Each block appears
 * only for the permission that reads it in the API.
 */
class ProcurementSection implements DashboardSection
{
    private const TOP_REQUESTERS = 10;

    public function key(): string
    {
        return 'procurement';
    }

    public function applies(DashboardScope $scope): bool
    {
        return $scope->canAny([
            'create_material_request', 'approve_material_request', 'manage_purchase_orders',
            'receive_deliveries', 'manage_rfqs',
        ]);
    }

    public function cacheable(): bool
    {
        return false;
    }

    public function build(DashboardScope $scope): ?array
    {
        $out = [];

        if ($scope->canAny(['create_material_request', 'approve_material_request', 'manage_purchase_orders'])) {
            $out['material_requests'] = $this->materialRequests($scope);
        }
        if ($scope->can('manage_purchase_orders')) {
            $out['purchase_orders'] = $this->purchaseOrders($scope);
        }
        if ($scope->can('receive_deliveries')) {
            $out['deliveries'] = $this->deliveries($scope);
        }
        if ($scope->can('manage_rfqs')) {
            $out['rfqs'] = $this->rfqs($scope);
        }

        return $out ?: null;
    }

    private function materialRequests(DashboardScope $scope): array
    {
        // The buyer sees across projects, like the pending-requests queue;
        // everyone else sees the projects they are on.
        $ids = $scope->can('manage_purchase_orders')
            ? $scope->ctx->procurementProjectIds()
            : $scope->ctx->memberProjectIds();
        // Qualified columns: the top-requesters query joins users, which also has deleted_at.
        $base = fn () => ActionContext::applyIds(
            DB::table('material_requests')->whereNull('material_requests.deleted_at'),
            $ids,
            'material_requests.project_id',
        );
        $codes = $scope->statuses->codes('mr');

        $out = [
            'by_status' => $this->byStatus($base(), 'material_request_status_id', $codes),
            'created_in_range' => $scope->inRange($base(), 'created_at')->count(),
        ];

        if ($scope->can('create_material_request')) {
            $mine = fn () => $base()->where('requested_by', $scope->user()->id);
            $draft = $scope->statuses->id('mr', 'draft');
            $out['mine'] = [
                'by_status' => $this->byStatus($mine(), 'material_request_status_id', $codes),
                // Ordering / delivery progress of what was actually submitted.
                'ordering_status' => $mine()->where('material_request_status_id', '!=', $draft)
                    ->selectRaw('ordering_status, count(*) as c')->groupBy('ordering_status')->pluck('c', 'ordering_status')->map(fn ($c) => (int) $c)->all(),
                'delivery_status' => $mine()->where('material_request_status_id', '!=', $draft)
                    ->selectRaw('delivery_status, count(*) as c')->groupBy('delivery_status')->pluck('c', 'delivery_status')->map(fn ($c) => (int) $c)->all(),
            ];
        }

        if ($scope->can('approve_material_request')) {
            // "Requests from the field": who is asking, in the period.
            $out['top_requesters_in_range'] = $scope->inRange($base(), 'material_requests.created_at')
                ->join('users', 'users.id', '=', 'material_requests.requested_by')
                ->selectRaw("users.id, trim(users.first_name || ' ' || users.last_name) as name, count(*) as requests")
                ->groupBy('users.id', 'users.first_name', 'users.last_name')
                ->orderByDesc('requests')->limit(self::TOP_REQUESTERS)->get()
                ->map(fn ($r) => ['user_id' => $r->id, 'name' => $r->name, 'requests' => (int) $r->requests])->all();
        }

        return $out;
    }

    private function purchaseOrders(DashboardScope $scope): array
    {
        $base = fn () => ActionContext::applyIds(DB::table('purchase_orders')->whereNull('deleted_at'), $scope->ctx->procurementProjectIds());
        $codes = $scope->statuses->codes('po');

        $byStatus = $base()->selectRaw('purchase_order_status_id, count(*) as c, coalesce(sum(total_amount), 0) as v')
            ->groupBy('purchase_order_status_id')->get()
            ->mapWithKeys(fn ($r) => [$codes[$r->purchase_order_status_id] ?? (string) $r->purchase_order_status_id => [
                'count' => (int) $r->c,
                'value' => DashboardScope::money($r->v),
            ]])->all();

        $issued = $scope->inRange($base(), 'issued_at')
            ->selectRaw('count(*) as c, coalesce(sum(total_amount), 0) as v')->first();

        return [
            'by_status' => $byStatus,
            'issued_in_range' => ['count' => (int) $issued->c, 'value' => DashboardScope::money($issued->v)],
            'standalone_in_range' => $scope->inRange($base(), 'created_at')->whereNull('material_request_id')->count(),
        ];
    }

    private function deliveries(DashboardScope $scope): array
    {
        $ids = $scope->ctx->procurementProjectIds();

        $received = ActionContext::applyIds(
            DB::table('deliveries as d')->join('purchase_orders as po', 'po.id', '=', 'd.purchase_order_id')
                ->whereNull('d.deleted_at')->whereNull('po.deleted_at'),
            $ids, 'po.project_id',
        );
        $r = $scope->inRange($received, 'd.received_at')
            ->selectRaw('count(*) as c, count(*) filter (where d.has_discrepancy) as with_discrepancy')->first();

        $expected = ActionContext::applyIds(DB::table('purchase_orders')->whereNull('deleted_at'), $ids)
            ->whereIn('purchase_order_status_id', $scope->statuses->ids('po', PoOverdueDelivery::OPEN_STATUSES))
            ->whereBetween('expected_delivery_date', [today()->toDateString(), today()->addDays($scope->upcomingDays)->toDateString()])
            ->count();

        return [
            'received_in_range' => (int) $r->c,
            'with_discrepancy_in_range' => (int) $r->with_discrepancy,
            'expected_upcoming' => $expected,
        ];
    }

    private function rfqs(DashboardScope $scope): array
    {
        // RFQ reads are not project-scoped in RfqService; only the explicit
        // project filter narrows them.
        $base = fn () => DB::table('rfqs')->whereNull('deleted_at')
            ->when($scope->ctx->projectId, fn ($q, $id) => $q->where('project_id', $id));

        return [
            'draft' => $base()->where('rfq_status_id', $scope->statuses->id('rfq', 'draft'))->count(),
            'sent_in_range' => $scope->inRange($base(), 'sent_at')->count(),
        ];
    }

    /** @param  array<int,string>  $codes */
    private function byStatus($query, string $column, array $codes): array
    {
        return $query->selectRaw("{$column} as s, count(*) as c")->groupBy($column)->pluck('c', 's')
            ->mapWithKeys(fn ($c, $id) => [$codes[$id] ?? (string) $id => (int) $c])->all();
    }
}
