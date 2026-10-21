<?php

namespace App\Services\Dashboard\Sections;

use App\Services\ActionItems\ActionContext;
use App\Services\Dashboard\DashboardScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Time series over the selected period, zero-filled so every series shares the
 * same x-axis. Bucket size follows the range: daily up to a month, weekly up to
 * ~4 months, monthly beyond. Each series is gated like the data it draws on.
 */
class ChartsSection implements DashboardSection
{
    public function key(): string
    {
        return 'charts';
    }

    public function applies(DashboardScope $scope): bool
    {
        return true;
    }

    public function cacheable(): bool
    {
        return true;
    }

    public function build(DashboardScope $scope): ?array
    {
        $granularity = $this->granularity($scope);
        $series = [];
        $st = $scope->statuses;

        if ($scope->seesFinancials()) {
            $ids = $scope->ctx->procurementProjectIds();

            $series[] = $this->series($scope, $granularity, 'committed', 'Committed spend (POs issued)', 'money',
                ActionContext::applyIds(DB::table('purchase_orders')->whereNull('deleted_at'), $ids)
                    ->whereIn('purchase_order_status_id', $st->ids('po', FinancialsSection::COMMITTED_PO_STATUSES)),
                'issued_at', 'coalesce(sum(total_amount), 0)');

            $series[] = $this->series($scope, $granularity, 'change_orders_approved_value', 'Change order value accepted by GC', 'money',
                ActionContext::applyIds(DB::table('change_orders')->whereNull('deleted_at'), $ids)
                    ->where('change_order_status_id', $st->id('co', 'active')),
                'gc_decision_at', 'coalesce(sum(value), 0)');
        }

        if ($scope->canAny(['create_material_request', 'approve_material_request', 'manage_purchase_orders'])) {
            $ids = $scope->can('manage_purchase_orders') ? $scope->ctx->procurementProjectIds() : $scope->ctx->memberProjectIds();
            $series[] = $this->series($scope, $granularity, 'material_requests_created', 'Material requests created', 'count',
                ActionContext::applyIds(DB::table('material_requests')->whereNull('deleted_at'), $ids), 'created_at', 'count(*)');
        }

        if ($scope->can('view_daily_log')) {
            $series[] = $this->series($scope, $granularity, 'daily_logs', 'Daily logs filed', 'count',
                ActionContext::applyIds(DB::table('daily_logs')->whereNull('deleted_at'), $scope->ctx->memberProjectIds()),
                'log_date', 'count(*)', dateColumn: true);
        }

        if ($scope->can('manage_issues')) {
            $series[] = $this->series($scope, $granularity, 'issues_opened', 'Issues raised', 'count',
                ActionContext::applyIds(DB::table('issues')->whereNull('deleted_at'), $scope->ctx->memberProjectIds()),
                'created_at', 'count(*)');
        }

        return $series === [] ? null : ['granularity' => $granularity, 'series' => $series];
    }

    private function granularity(DashboardScope $scope): string
    {
        $days = $scope->from->diffInDays($scope->to) + 1;

        return match (true) {
            $days <= 31 => 'day',
            $days <= 120 => 'week',
            default => 'month',
        };
    }

    /** @return array<string,mixed> */
    private function series(
        DashboardScope $scope,
        string $granularity,
        string $key,
        string $label,
        string $unit,
        Builder $query,
        string $column,
        string $aggregate,
        bool $dateColumn = false,
    ): array {
        $dateColumn ? $scope->inDateRange($query, $column) : $scope->inRange($query, $column);

        // $granularity comes from a fixed match() above, never from input.
        $values = $query->selectRaw("date_trunc('{$granularity}', {$column})::date as bucket, {$aggregate} as v")
            ->groupBy('bucket')->pluck('v', 'bucket');

        $points = [];
        foreach ($this->buckets($scope, $granularity) as $bucket) {
            $v = $values[$bucket] ?? 0;
            $points[] = ['period' => $bucket, 'value' => $unit === 'money' ? DashboardScope::money($v) : (int) $v];
        }

        return ['key' => $key, 'label' => $label, 'unit' => $unit, 'points' => $points];
    }

    /** @return array<int,string> bucket start dates (Y-m-d), matching Postgres date_trunc */
    private function buckets(DashboardScope $scope, string $granularity): array
    {
        $cursor = match ($granularity) {
            'week' => $scope->from->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $scope->from->startOfMonth(),
            default => $scope->from->startOfDay(),
        };

        $out = [];
        while ($cursor->lte($scope->to)) {
            $out[] = $cursor->toDateString();
            $cursor = match ($granularity) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $out;
    }
}
