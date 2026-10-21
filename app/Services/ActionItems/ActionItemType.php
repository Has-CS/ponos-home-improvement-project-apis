<?php

namespace App\Services\ActionItems;

use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One kind of "this is waiting on someone" — e.g. a material request awaiting
 * Admin approval.
 *
 * Each type is answerable in both directions, from ONE definition:
 *
 *  - pull (dashboard):    summarize($ctx) — how many items wait on this user,
 *                         oldest first, with a short preview;
 *  - push (notifications): recipientsFor($item) — which users this item now
 *                         waits on.
 *
 * recipientsFor() is derived from appliesTo() + query() rather than written
 * separately, so the two directions cannot drift: a user is a recipient of an
 * item exactly when that item would appear in their own dashboard count.
 *
 * key() is stable API: it is the dashboard row key today and is meant to be
 * the notification type tomorrow. Never rename one casually.
 */
abstract class ActionItemType
{
    public function __construct(
        protected readonly ActionAuthority $authority,
        protected readonly StatusIds $statuses,
    ) {}

    abstract public function key(): string;

    abstract public function label(): string;

    /** Dashboard section the item belongs to (procurement, change_orders, field, milestones). */
    abstract public function section(): string;

    /** The Eloquent model an item of this type is. */
    abstract public function subject(): string;

    /** Short stable name of the subject for payloads (material_request, change_order, ...). */
    abstract public function subjectType(): string;

    /** Route-level permission AND in-service step rule: could this user act on such an item at all? */
    abstract public function appliesTo(User $user): bool;

    /** The items waiting on $ctx->user, already project-scoped. */
    abstract public function query(ActionContext $ctx): Builder;

    /**
     * Number, title and status of one item, for the shared payload.
     *
     * @return array{number:?string,title:?string,status:?string}
     */
    abstract protected function describe(Model $item): array;

    /** Which list endpoint shows these items, and the filters it already accepts. */
    abstract public function link(): array;

    /** high | medium | low */
    public function priority(): string
    {
        return 'medium';
    }

    /** Column measuring how long an item has waited; null when there is none. */
    protected function waitingSinceColumn(): ?string
    {
        return 'updated_at';
    }

    /** Eager loads for the preview rows only — never used for counting. */
    protected function previewWith(): array
    {
        return ['project:id,code,name'];
    }

    protected function projectIdOf(Model $item): ?int
    {
        return $item->project_id !== null ? (int) $item->project_id : null;
    }

    /**
     * Dashboard row: count, oldest wait, and up to $limit oldest items.
     *
     * @return array<string,mixed>
     */
    public function summarize(ActionContext $ctx, int $limit = 5): array
    {
        $column = $this->waitingSinceColumn();
        $qualified = $column ? $this->qualify($column) : null;

        $aggregate = $this->query($ctx)->toBase()->reorder()
            ->selectRaw('count(*) as aggregate_count'.($qualified ? ", min({$qualified}) as oldest" : ''))
            ->first();

        $count = (int) ($aggregate->aggregate_count ?? 0);

        $preview = [];
        if ($count > 0) {
            $rows = $this->query($ctx)->with($this->previewWith());
            $qualified ? $rows->orderBy($qualified) : $rows->orderBy($this->qualify('id'));
            $preview = $rows->limit($limit)->get()->map(fn (Model $item) => $this->payload($item))->all();
        }

        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'priority' => $this->priority(),
            'section' => $this->section(),
            'count' => $count,
            'oldest_at' => $qualified && ! empty($aggregate->oldest) ? Carbon::parse($aggregate->oldest)->toIso8601String() : null,
            'link' => $this->link(),
            'preview' => $preview,
        ];
    }

    /**
     * The one payload shape shared by dashboard previews and (later) the
     * `notifications.data` column, so one frontend component renders both.
     *
     * @return array<string,mixed>
     */
    public function payload(Model $item): array
    {
        $described = $this->describe($item);
        $column = $this->waitingSinceColumn();
        $since = $column && $item->{$column} ? Carbon::parse($item->{$column}) : null;
        $project = $item->relationLoaded('project') ? $item->project : null;

        return [
            'key' => $this->key(),
            'subject_type' => $this->subjectType(),
            'subject_id' => $item->getKey(),
            'number' => $described['number'] ?? null,
            'title' => $described['title'] ?? null,
            'status' => $described['status'] ?? null,
            'project' => $project ? ['id' => $project->id, 'code' => $project->code, 'name' => $project->name] : null,
            'waiting_since' => $since?->toIso8601String(),
            // Signed: negative means "due in N days" for forward-dated items.
            'age_days' => $since ? (int) $since->copy()->startOfDay()->diffInDays(now()->startOfDay(), false) : null,
            'link' => [
                'resource' => $this->link()['resource'],
                'project_id' => $this->projectIdOf($item),
                'id' => $item->getKey(),
            ],
        ];
    }

    /**
     * Users this item is currently waiting on. Not called by the dashboard;
     * defined here for the notification module, and covered by tests that
     * assert it agrees with summarize().
     *
     * Candidates are the item's active project members plus the global
     * Admin / Procurement holders (who reach projects without membership);
     * each is kept only if the item is in their own query().
     *
     * @return Collection<int,User>
     */
    public function recipientsFor(Model $item): Collection
    {
        $projectId = $this->projectIdOf($item);
        $activeStatusId = $this->statuses->id('user', 'active');

        $memberIds = $projectId === null ? collect() : ProjectUser::where('project_id', $projectId)
            ->where('is_active', true)
            ->pluck('user_id');

        $teamKey = config('permission.column_names.team_foreign_key', 'project_id');
        $globalIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.'.$teamKey, 0)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('roles.name', ['Admin', 'Procurement'])
            ->pluck('model_has_roles.model_id');

        return User::query()
            ->whereIn('id', $memberIds->merge($globalIds)->unique()->values()->all() ?: [0])
            ->where('user_status_id', $activeStatusId)
            ->get()
            ->filter(fn (User $user) => $this->appliesTo($user)
                && $this->query(ActionContext::for($user, $this->authority))->whereKey($item->getKey())->exists())
            ->values();
    }

    protected function qualify(string $column): string
    {
        return (new ($this->subject()))->getTable().'.'.$column;
    }

    /** List-endpoint filter for a single status, the way existing index endpoints accept it. */
    protected function statusLink(string $resource, string $table, ?string $code): array
    {
        return [
            'resource' => $resource,
            'filters' => $code !== null ? ['status_id' => $this->statuses->id($table, $code)] : [],
        ];
    }
}
