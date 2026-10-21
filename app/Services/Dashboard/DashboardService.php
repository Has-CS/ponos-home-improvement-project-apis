<?php

namespace App\Services\Dashboard;

use App\Models\Project;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use App\Services\ActionItems\ActionItemRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Builds GET /api/v1/dashboard.
 *
 * One endpoint for every role. Each section decides from permissions whether
 * it applies; everything is scoped through ActionContext so a user only ever
 * sees projects the rest of the API would let them read. Action items come
 * from the shared ActionItemRegistry (also the future notification source).
 *
 * Caching: action items and counts are always computed live, so acting on
 * something is reflected at once. Only cacheable() sections (financials,
 * charts) are held for CACHE_SECONDS per user + filters; ?refresh=1 bypasses.
 */
class DashboardService
{
    public const CACHE_SECONDS = 300;

    public const DEFAULT_RANGE_DAYS = 30;

    public const DEFAULT_UPCOMING_DAYS = 14;

    /**
     * Order of the keys under `data`, after `meta`. Overview first, the
     * personal to-do list (`action_items`) next, then reference data and charts.
     * `meta.sections` reports the same order, minus anything omitted.
     */
    private const RESPONSE_ORDER = [
        'projects',
        'financials',
        'milestones',
        'procurement',
        'change_orders',
        'field',
        'people',
        'action_items',
        'pricing',
        'charts',
    ];

    private const PRIORITIES = ['high', 'medium', 'low'];

    /** Section builders (permission-gated); action_items comes from the registry. */
    private const SECTIONS = [
        Sections\ProjectsSection::class,
        Sections\FinancialsSection::class,
        Sections\MilestonesSection::class,
        Sections\ProcurementSection::class,
        Sections\ChangeOrdersSection::class,
        Sections\FieldSection::class,
        Sections\PeopleSection::class,
        Sections\PricingSection::class,
        Sections\ChartsSection::class,
    ];

    public function __construct(private readonly ActionItemRegistry $registry) {}

    /**
     * @param  array<string,mixed>  $filters  validated DashboardRequest input
     * @return array<string,mixed>
     */
    public function build(User $user, array $filters): array
    {
        $projectId = isset($filters['project_id']) ? (int) $filters['project_id'] : null;
        $ctx = ActionContext::for($user, $this->registry->authority(), $projectId);

        // Same answer `project.access` would give, widened for the procurement
        // desk exactly as the PO/delivery services widen it.
        if ($projectId !== null && ! $ctx->canSeeProject($projectId)) {
            abort(403, 'You do not have access to this project.');
        }

        $to = isset($filters['date_to']) ? CarbonImmutable::parse($filters['date_to']) : CarbonImmutable::today();
        $from = isset($filters['date_from'])
            ? CarbonImmutable::parse($filters['date_from'])
            : $to->subDays(self::DEFAULT_RANGE_DAYS - 1);

        $scope = new DashboardScope(
            $ctx,
            $this->registry->statuses(),
            $from,
            $to,
            (int) ($filters['upcoming_days'] ?? self::DEFAULT_UPCOMING_DAYS),
        );

        $refresh = (bool) ($filters['refresh'] ?? false);

        $built = ['action_items' => $this->actionItems($ctx)];
        $cachedAt = [];

        foreach (self::SECTIONS as $class) {
            /** @var Sections\DashboardSection $section */
            $section = app($class);
            if (! $section->applies($scope)) {
                continue;
            }

            if ($section->cacheable()) {
                [$data, $at] = $this->cached($section, $scope, $refresh);
                $cachedAt[$section->key()] = $at;
            } else {
                $data = $section->build($scope);
            }

            if ($data !== null) {
                $built[$section->key()] = $data;
            }
        }

        $body = [];
        foreach (self::RESPONSE_ORDER as $key) {
            if (array_key_exists($key, $built)) {
                $body[$key] = $built[$key];
            }
        }

        return [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'user' => [
                    'id' => $user->id,
                    'name' => trim($user->first_name.' '.$user->last_name),
                ],
                // What was asked for (defaults filled in).
                'filters' => [
                    'project_id' => $projectId,
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                    'upcoming_days' => $scope->upcomingDays,
                ],
                // What the user can see.
                'scope' => [
                    'all_projects' => $ctx->isAllProjects(),
                    'project_count' => $this->projectCount($ctx),
                ],
                // Keys present under `data`, in response order.
                'sections' => array_keys($body),
                // Every queue this user is watched for, including empty ones,
                // so the client can say "nothing waiting" with confidence.
                'action_item_keys' => array_keys($this->registry->applicableTo($user)),
                'cached_at' => (object) array_filter($cachedAt),
                'notes' => isset($body['financials']) ? [
                    'Committed spend covers material purchase orders only; labor and subcontract costs are not tracked.',
                    'There is no invoicing or payment data, so no actual-cost or billed figures are shown.',
                ] : [],
            ],
            ...$body,
        ];
    }

    /**
     * The user's to-do list: a headline total, counts per priority for badges,
     * and the non-empty queues most urgent first.
     *
     * @return array<string,mixed>
     */
    private function actionItems(ActionContext $ctx): array
    {
        $items = $this->registry->summaries($ctx);

        $byPriority = array_fill_keys(self::PRIORITIES, 0);
        foreach ($items as $item) {
            $byPriority[$item['priority']] += $item['count'];
        }

        return [
            'total' => array_sum($byPriority),
            'by_priority' => $byPriority,
            'items' => $items,
        ];
    }

    /**
     * @return array{0:?array,1:?string} section body and when it was computed
     *                                   (null when computed by this request)
     */
    private function cached(Sections\DashboardSection $section, DashboardScope $scope, bool $refresh): array
    {
        $key = sprintf('dashboard:%d:%s:%s', $scope->user()->id, $section->key(), $scope->fingerprint());

        if ($refresh) {
            Cache::forget($key);
        }

        $hit = true;
        $payload = Cache::remember($key, self::CACHE_SECONDS, function () use ($section, $scope, &$hit) {
            $hit = false;

            return ['at' => now()->toIso8601String(), 'data' => $section->build($scope)];
        });

        return [$payload['data'], $hit ? $payload['at'] : null];
    }

    private function projectCount(ActionContext $ctx): int
    {
        return ActionContext::applyIds(Project::query(), $ctx->memberProjectIds(), 'id')->count();
    }
}
