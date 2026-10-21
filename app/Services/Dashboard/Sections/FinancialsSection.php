<?php

namespace App\Services\Dashboard\Sections;

use App\Services\ActionItems\ActionContext;
use App\Services\Dashboard\DashboardScope;
use Illuminate\Support\Facades\DB;

/**
 * Contract-value financials, position to date (not filtered by the period).
 *
 *   contract value          projects.budget (agreed: budget IS the contract value)
 *   approved CO value       Σ change_orders.value at `active` (GC accepted)
 *   revised contract        contract + approved CO
 *   pending CO value        Σ value not yet agreed: pending_admin, approved,
 *                           pending_gc, and priced work_authorized
 *   unpriced emergency      count of work_authorized with no value
 *   disputed CO value       Σ value of emergency COs the GC rejected after work
 *   committed               Σ purchase_orders.total_amount once issued (not draft/cancelled)
 *   draft PO pipeline       Σ total_amount at draft
 *   received                Σ delivered qty × PO unit price, on non-cancelled POs
 *   payment milestones      scheduled / earned (completed) / remaining
 *
 * Procurement-desk scope: Admin and Procurement see every project.
 * Each figure is ONE grouped query by project; totals are summed in PHP.
 */
class FinancialsSection implements DashboardSection
{
    public const COMMITTED_PO_STATUSES = ['issued', 'sent', 'acknowledged', 'partially_received', 'received'];

    private const BY_PROJECT_LIMIT = 50;

    public function key(): string
    {
        return 'financials';
    }

    public function applies(DashboardScope $scope): bool
    {
        return $scope->seesFinancials();
    }

    public function cacheable(): bool
    {
        return true;
    }

    public function build(DashboardScope $scope): ?array
    {
        $ids = $scope->ctx->procurementProjectIds();
        $st = $scope->statuses;

        $projects = ActionContext::applyIds(DB::table('projects')->whereNull('deleted_at'), $ids, 'id')
            ->get(['id', 'code', 'name', 'budget', 'project_status_id']);

        $co = $this->changeOrders($scope, $ids);
        $po = $this->purchaseOrders($scope, $ids);
        $received = $this->received($scope, $ids);
        $payments = $this->paymentMilestones($scope, $ids);
        $codes = $st->codes('project');

        $rows = $projects->map(function ($p) use ($co, $po, $received, $payments, $codes) {
            $c = $co[$p->id] ?? null;
            $o = $po[$p->id] ?? null;
            $pay = $payments[$p->id] ?? null;

            $contract = (float) ($p->budget ?? 0);
            $approvedCo = (float) ($c->approved ?? 0);
            $committed = (float) ($o->committed ?? 0);

            return [
                'project' => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'status' => $codes[$p->project_status_id] ?? null],
                'has_contract_value' => $p->budget !== null,
                'contract_value' => $contract,
                'approved_co_value' => $approvedCo,
                'revised_contract_value' => $contract + $approvedCo,
                'pending_co_value' => (float) ($c->pending ?? 0),
                'unpriced_emergency_count' => (int) ($c->unpriced ?? 0),
                'disputed_co_value' => (float) ($c->disputed ?? 0),
                'committed' => $committed,
                'draft_po_value' => (float) ($o->draft ?? 0),
                'received' => (float) ($received[$p->id] ?? 0),
                'payment_scheduled' => (float) ($pay->scheduled ?? 0),
                'payment_earned' => (float) ($pay->earned ?? 0),
            ];
        });

        $sum = fn (string $field) => $rows->sum($field);
        $totals = $this->present([
            'contract_value' => $sum('contract_value'),
            'approved_co_value' => $sum('approved_co_value'),
            'revised_contract_value' => $sum('revised_contract_value'),
            'pending_co_value' => $sum('pending_co_value'),
            'unpriced_emergency_count' => $sum('unpriced_emergency_count'),
            'disputed_co_value' => $sum('disputed_co_value'),
            'committed' => $sum('committed'),
            'draft_po_value' => $sum('draft_po_value'),
            'received' => $sum('received'),
            'payment_scheduled' => $sum('payment_scheduled'),
            'payment_earned' => $sum('payment_earned'),
        ]);

        $sorted = $rows->sortByDesc('revised_contract_value')->values();

        return [
            'currency' => 'USD',
            'totals' => [
                ...$totals,
                'project_count' => $rows->count(),
                'projects_missing_contract_value' => $rows->where('has_contract_value', false)->count(),
            ],
            'by_project' => $sorted->take(self::BY_PROJECT_LIMIT)
                ->map(fn (array $r) => ['project' => $r['project'], 'has_contract_value' => $r['has_contract_value'], ...$this->present($r)])
                ->all(),
            'by_project_truncated' => $sorted->count() > self::BY_PROJECT_LIMIT,
        ];
    }

    /**
     * Money fields → 2-dp strings, plus the derived ratio and remaining payment.
     *
     * @param  array<string,mixed>  $r
     * @return array<string,mixed>
     */
    private function present(array $r): array
    {
        $revised = (float) $r['revised_contract_value'];

        return [
            'contract_value' => DashboardScope::money($r['contract_value']),
            'approved_co_value' => DashboardScope::money($r['approved_co_value']),
            'revised_contract_value' => DashboardScope::money($revised),
            'pending_co_value' => DashboardScope::money($r['pending_co_value']),
            'unpriced_emergency_count' => (int) $r['unpriced_emergency_count'],
            'disputed_co_value' => DashboardScope::money($r['disputed_co_value']),
            'committed' => DashboardScope::money($r['committed']),
            'draft_po_value' => DashboardScope::money($r['draft_po_value']),
            'received' => DashboardScope::money($r['received']),
            'committed_pct_of_revised' => $revised > 0 ? round($r['committed'] / $revised * 100, 1) : null,
            'payment_milestones' => [
                'scheduled' => DashboardScope::money($r['payment_scheduled']),
                'earned' => DashboardScope::money($r['payment_earned']),
                'remaining' => DashboardScope::money($r['payment_scheduled'] - $r['payment_earned']),
            ],
        ];
    }

    private function changeOrders(DashboardScope $scope, ?array $ids)
    {
        $st = $scope->statuses;
        $in = fn (array $codes) => implode(',', $st->ids('co', $codes));
        $workAuthorized = $st->id('co', 'work_authorized');

        return ActionContext::applyIds(DB::table('change_orders')->whereNull('deleted_at'), $ids)
            ->selectRaw("project_id,
                coalesce(sum(value) filter (where change_order_status_id = ?), 0) as approved,
                coalesce(sum(value) filter (where change_order_status_id in ({$in(['pending_admin', 'approved', 'pending_gc'])})
                    or (change_order_status_id = ? and value is not null)), 0) as pending,
                count(*) filter (where change_order_status_id = ? and value is null) as unpriced,
                coalesce(sum(value) filter (where change_order_status_id = ? and work_authorized_at is not null), 0) as disputed",
                [$st->id('co', 'active'), $workAuthorized, $workAuthorized, $st->id('co', 'gc_rejected')])
            ->groupBy('project_id')->get()->keyBy('project_id');
    }

    private function purchaseOrders(DashboardScope $scope, ?array $ids)
    {
        $committed = implode(',', $scope->statuses->ids('po', self::COMMITTED_PO_STATUSES));

        return ActionContext::applyIds(DB::table('purchase_orders')->whereNull('deleted_at'), $ids)
            ->selectRaw("project_id,
                coalesce(sum(total_amount) filter (where purchase_order_status_id in ({$committed})), 0) as committed,
                coalesce(sum(total_amount) filter (where purchase_order_status_id = ?), 0) as draft",
                [$scope->statuses->id('po', 'draft')])
            ->groupBy('project_id')->get()->keyBy('project_id');
    }

    private function received(DashboardScope $scope, ?array $ids)
    {
        $query = DB::table('delivery_items as di')
            ->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
            ->join('purchase_order_items as poi', 'poi.id', '=', 'di.purchase_order_item_id')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->whereNull('di.deleted_at')->whereNull('d.deleted_at')
            ->whereNull('poi.deleted_at')->whereNull('po.deleted_at')
            ->where('po.purchase_order_status_id', '!=', $scope->statuses->id('po', 'cancelled'));

        return ActionContext::applyIds($query, $ids, 'po.project_id')
            ->selectRaw('po.project_id, sum(di.quantity_received * poi.unit_price) as received')
            ->groupBy('po.project_id')->pluck('received', 'project_id');
    }

    private function paymentMilestones(DashboardScope $scope, ?array $ids)
    {
        return ActionContext::applyIds(DB::table('milestones')->whereNull('deleted_at'), $ids)
            ->where('is_payment_milestone', true)
            ->selectRaw('project_id,
                coalesce(sum(payment_amount), 0) as scheduled,
                coalesce(sum(payment_amount) filter (where status_id = ?), 0) as earned',
                [$scope->statuses->id('milestone', 'completed')])
            ->groupBy('project_id')->get()->keyBy('project_id');
    }
}
