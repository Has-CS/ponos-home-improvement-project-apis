<?php

namespace Tests\Feature\Dashboard;

use App\Models\ChangeOrder;
use App\Models\MaterialRequest;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\ActionItems\ActionItemRegistry;

/**
 * The notification module's contract: recipientsFor($item) — "who is this
 * waiting on?" — must agree with each user's own dashboard. If a user is a
 * recipient, the item is in their queue; if not, it is not.
 */
class ActionItemRecipientsTest extends DashboardTestCase
{
    public function test_recipients_match_who_sees_the_item_on_their_dashboard(): void
    {
        $cases = [
            'mr_pending_pm' => [MaterialRequest::find($this->mr($this->projectA, $this->foreman, 'pending_pm')), [$this->pm]],
            'mr_pending_admin' => [MaterialRequest::find($this->mr($this->projectA, $this->foreman, 'pending_admin')), [$this->admin]],
            'mr_returned_to_me' => [MaterialRequest::find($this->mr($this->projectA, $this->foreman, 'sent_back_to_foreman')), [$this->foreman]],
            'co_pending_gc' => [ChangeOrder::find($this->co($this->projectA, $this->foreman, 'pending_gc')), [$this->pm, $this->admin]],
            // Project B has no staff: only the procurement desk reaches its orders.
            'po_draft' => [PurchaseOrder::find($this->po($this->projectB, 'draft', 300)), [$this->admin, $this->procurement]],
        ];

        $registry = app(ActionItemRegistry::class);
        $everyone = [$this->admin, $this->pm, $this->apm, $this->coordinator, $this->foreman, $this->siteEngineer, $this->procurement];

        foreach ($cases as $key => [$item, $expected]) {
            $recipients = $registry->find($key)->recipientsFor($item)->pluck('id')->sort()->values()->all();
            $this->assertSame(
                collect($expected)->pluck('id')->sort()->values()->all(),
                $recipients,
                "Unexpected recipients for {$key}",
            );

            foreach ($everyone as $user) {
                $this->assertSame(
                    in_array($user->id, $recipients, true),
                    $this->onDashboard($user, $key, $item),
                    "{$key}: user {$user->id} dashboard disagrees with recipientsFor()",
                );
            }
        }
    }

    public function test_recipients_by_type_lists_every_queue_an_item_sits_in(): void
    {
        $mr = MaterialRequest::find($this->mr($this->projectA, $this->foreman, 'approved', [
            'request_text' => 'Need 40 sheets of 3/4 ply',
        ]));

        $byType = app(ActionItemRegistry::class)->recipientsByType($mr);

        $this->assertSame(['mr_needs_structuring', 'mr_awaiting_po'], array_keys($byType));
        $this->assertContains($this->procurement->id, $byType['mr_awaiting_po']->pluck('id')->all());
    }

    private function onDashboard(User $user, string $key, $item): bool
    {
        $row = $this->actionItem($this->dashboard($user), $key);

        return $row !== null && collect($row['preview'])->pluck('subject_id')->contains($item->getKey());
    }
}
