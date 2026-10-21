<?php

namespace App\Services\Dashboard\Sections;

use App\Services\Dashboard\DashboardScope;

/**
 * One block of the dashboard. Whether it appears is decided by permissions in
 * applies() — never by role name — so a runtime-created role or a per-user
 * grant changes the dashboard with no code change.
 */
interface DashboardSection
{
    /** Response key. */
    public function key(): string;

    public function applies(DashboardScope $scope): bool;

    /** The section body, or null to omit it (e.g. nothing in it applies). */
    public function build(DashboardScope $scope): ?array;

    /** Heavy and slow-changing sections may be cached briefly; queues never are. */
    public function cacheable(): bool;
}
