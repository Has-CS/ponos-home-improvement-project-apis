<?php

namespace App\Services\ActionItems;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Every "waiting on someone" type in the system.
 *
 * Shared infrastructure: the dashboard reads it through summaries(); the
 * notification module is meant to read it through recipientsByType() when an
 * item changes state. Add a type here and both pick it up.
 */
class ActionItemRegistry
{
    /** @var array<int,class-string<ActionItemType>> */
    private const TYPES = [
        // Material requests
        Types\MrPendingPm::class,
        Types\MrPendingAdmin::class,
        Types\MrSentBackToPm::class,
        Types\MrReturnedToMe::class,
        Types\MrDraft::class,
        Types\MrNeedsStructuring::class,
        Types\MrAwaitingPo::class,
        // Change orders
        Types\CoPendingPm::class,
        Types\CoPendingAdmin::class,
        Types\CoReadyToSend::class,
        Types\CoNeedsPricing::class,
        Types\CoReadyToForward::class,
        Types\CoPendingGc::class,
        Types\CoDisputed::class,
        Types\CoReturnedToMe::class,
        // Purchasing
        Types\PoDraft::class,
        Types\PoIssuedNotSent::class,
        Types\PoOverdueDelivery::class,
        // Field
        Types\IssueAssignedToMe::class,
        Types\IssueHighOpen::class,
        Types\DailyLogMissingToday::class,
        // Milestones
        Types\MilestoneOverdue::class,
        Types\MilestoneMineDue::class,
    ];

    private const PRIORITY_RANK = ['high' => 0, 'medium' => 1, 'low' => 2];

    /** @var array<string,ActionItemType> */
    private array $types = [];

    public function __construct(
        private readonly ActionAuthority $authority,
        private readonly StatusIds $statuses,
    ) {
        foreach (self::TYPES as $class) {
            $type = new $class($authority, $statuses);
            $this->types[$type->key()] = $type;
        }
    }

    public function authority(): ActionAuthority
    {
        return $this->authority;
    }

    public function statuses(): StatusIds
    {
        return $this->statuses;
    }

    /** @return array<string,ActionItemType> */
    public function all(): array
    {
        return $this->types;
    }

    public function find(string $key): ?ActionItemType
    {
        return $this->types[$key] ?? null;
    }

    /** @return array<string,ActionItemType> */
    public function applicableTo(User $user): array
    {
        return array_filter($this->types, fn (ActionItemType $type) => $type->appliesTo($user));
    }

    /**
     * Non-empty queues for this user, most urgent first: priority, then size.
     *
     * @return array<int,array<string,mixed>>
     */
    public function summaries(ActionContext $ctx, int $previewLimit = 5): array
    {
        $rows = [];
        foreach ($this->applicableTo($ctx->user) as $type) {
            $row = $type->summarize($ctx, $previewLimit);
            if ($row['count'] > 0) {
                $rows[] = $row;
            }
        }

        usort($rows, fn (array $a, array $b) => [self::PRIORITY_RANK[$a['priority']], -$a['count']]
            <=> [self::PRIORITY_RANK[$b['priority']], -$b['count']]);

        return $rows;
    }

    /**
     * For the notification module: every type this item currently sits in,
     * with the users it now waits on.
     *
     * @return array<string,Collection<int,User>>
     */
    public function recipientsByType(Model $item): array
    {
        $out = [];
        foreach ($this->types as $key => $type) {
            if (! $item instanceof ($type->subject())) {
                continue;
            }
            $recipients = $type->recipientsFor($item);
            if ($recipients->isNotEmpty()) {
                $out[$key] = $recipients;
            }
        }

        return $out;
    }
}
