<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\ActionItems\ActionAuthority;
use App\Services\ActionItems\ActionContext;
use App\Services\ActionItems\StatusIds;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Everything a dashboard section needs to know about the request: who, which
 * projects (via ActionContext's three scopes), which period, and lookup ids.
 */
final class DashboardScope
{
    public function __construct(
        public readonly ActionContext $ctx,
        public readonly StatusIds $statuses,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $upcomingDays,
    ) {}

    public function user(): User
    {
        return $this->ctx->user;
    }

    public function authority(): ActionAuthority
    {
        return $this->ctx->authority;
    }

    public function can(string $permission): bool
    {
        return $this->authority()->can($this->user(), $permission);
    }

    /** @param  array<int,string>  $permissions */
    public function canAny(array $permissions): bool
    {
        return $this->authority()->canAny($this->user(), $permissions);
    }

    /** Money is shown to holders of view_reports AND view_pricing. */
    public function seesFinancials(): bool
    {
        return $this->can('view_reports') && $this->can('view_pricing');
    }

    /** Start and end instants of the selected period, both days inclusive. */
    public function rangeBounds(): array
    {
        return [$this->from->startOfDay(), $this->to->endOfDay()];
    }

    /**
     * Constrain a timestamp column to the selected period.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public function inRange(Builder $query, string $column): Builder
    {
        return $query->whereBetween($column, $this->rangeBounds());
    }

    /**
     * Constrain a DATE column to the selected period.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public function inDateRange(Builder $query, string $column): Builder
    {
        return $query->whereBetween($column, [$this->from->toDateString(), $this->to->toDateString()]);
    }

    /** Stable fingerprint of the filters, for cache keys. */
    public function fingerprint(): string
    {
        return sha1(json_encode([
            $this->ctx->projectId,
            $this->from->toDateString(),
            $this->to->toDateString(),
            $this->upcomingDays,
            today()->toDateString(),
        ]));
    }

    /** 2-dp string, the same representation the decimal:2 casts give elsewhere. */
    public static function money(float|int|string|null $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
