<?php

namespace App\Services\Dashboard\Sections;

use App\Services\Dashboard\DashboardScope;
use Illuminate\Support\Facades\DB;

/**
 * Catalog health. Pricing is company-wide (not project-scoped), like the
 * vendor/catalog/rate endpoints, so the project filter does not apply here.
 */
class PricingSection implements DashboardSection
{
    public function key(): string
    {
        return 'pricing';
    }

    public function applies(DashboardScope $scope): bool
    {
        return $scope->can('view_pricing');
    }

    public function cacheable(): bool
    {
        return false;
    }

    public function build(DashboardScope $scope): ?array
    {
        $items = DB::table('catalog_items')->whereNull('deleted_at')->where('is_active', true)
            ->selectRaw('count(*) as active,
                count(*) filter (where not exists (
                    select 1 from vendor_rates vr
                    where vr.catalog_item_id = catalog_items.id
                      and vr.effective_to is null and vr.deleted_at is null)) as without_rate')
            ->first();

        return [
            'active_vendors' => DB::table('vendors')->whereNull('deleted_at')->where('is_active', true)->count(),
            'active_catalog_items' => (int) $items->active,
            // A PO line prices from the open vendor rate; these can't be priced without a manual override.
            'active_items_without_rate' => (int) $items->without_rate,
            'rates_entered_in_range' => $scope->inRange(DB::table('vendor_rates')->whereNull('deleted_at'), 'created_at')->count(),
        ];
    }
}
