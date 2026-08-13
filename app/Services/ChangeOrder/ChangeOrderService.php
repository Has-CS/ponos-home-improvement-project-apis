<?php

namespace App\Services\ChangeOrder;

use App\Models\ChangeOrder;
use App\Models\ChangeOrderSignature;
use App\Models\ChangeOrderStatus;
use App\Models\ChangeOrderType;
use App\Models\GcDecision;
use App\Models\Project;
use App\Models\User;
use App\Services\Attachment\AttachmentService;
use App\Services\Document\DocumentSequenceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ChangeOrderService
{
    // change_order_statuses.code
    private const DRAFT = 'draft';
    private const PENDING_PM = 'pending_pm';
    private const PENDING_ADMIN = 'pending_admin';
    private const SENT_BACK = 'sent_back';
    private const REJECTED_INTERNAL = 'rejected_internal';
    private const PENDING_COUNTER_SIGN = 'pending_counter_sign';
    private const PENDING_GC = 'pending_gc';
    private const ACTIVE = 'active';
    private const GC_REJECTED = 'gc_rejected';
    private const CANCELLED = 'cancelled';

    /** Statuses in which the originator may still edit the CO's fields. */
    private const EDITABLE_STATUSES = [self::DRAFT, self::SENT_BACK, self::PENDING_COUNTER_SIGN];

    private const LIST_WITH = ['type', 'status', 'gcDecision', 'originator'];
    private const DETAIL_WITH = [
        'type', 'status', 'gcDecision', 'costCode', 'urgency', 'originator',
        'counterSignedBy', 'gcDecisionBy',
        'approvals.actor', 'approvals.fromStatus', 'approvals.toStatus',
        'signatures.capturedBy', 'signatures.signatureAttachment',
    ];

    /** @var array<string,int> */
    private array $statusIdCache = [];

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(Project $project, array $filters): LengthAwarePaginator
    {
        $query = ChangeOrder::query()->where('project_id', $project->id)->with(self::LIST_WITH);

        if (! empty($filters['search'])) {
            $t = $filters['search'];
            $query->where(fn ($q) => $q->where('change_order_no', 'ilike', "%{$t}%")->orWhere('title', 'ilike', "%{$t}%"));
        }
        if (! empty($filters['status_id'])) {
            $query->where('change_order_status_id', $filters['status_id']);
        }
        if (! empty($filters['type_id'])) {
            $query->where('change_order_type_id', $filters['type_id']);
        }
        if (! empty($filters['gc_decision_id'])) {
            $query->where('gc_decision_id', $filters['gc_decision_id']);
        }

        return $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function findDetailed(ChangeOrder $co): ChangeOrder
    {
        return $co->load(self::DETAIL_WITH);
    }

    // ---- Creation ----

    public function createNormal(Project $project, array $data, int $userId): ChangeOrder
    {
        return DB::transaction(function () use ($project, $data, $userId) {
            $co = ChangeOrder::create([
                'change_order_no' => $this->sequences->next('change_order', 'CO'),
                'project_id' => $project->id,
                'cost_code_id' => $data['cost_code_id'] ?? null,
                'change_order_type_id' => $this->typeId('normal'),
                'change_order_status_id' => $this->statusId(self::DRAFT),
                'gc_decision_id' => $this->gcDecisionId('pending'),
                'originator_id' => $userId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'scope' => $data['scope'] ?? null,
                'location' => $data['location'] ?? null,
                'urgency_id' => $data['urgency_id'] ?? null,
                'value' => $data['value'] ?? null,
                'created_by' => $userId,
            ]);

            return $co->fresh(self::DETAIL_WITH);
        });
    }

    /**
     * Emergency CO: the GC rep signed on-site, so the CO is authorized on
     * creation. One transaction: store the signature image, create the CO
     * straight to active, record the signature, log a single approval row.
     */
    public function createEmergency(Project $project, array $data, User $user): ChangeOrder
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $co = ChangeOrder::create([
                'change_order_no' => $this->sequences->next('change_order', 'CO'),
                'project_id' => $project->id,
                'cost_code_id' => $data['cost_code_id'] ?? null,
                'change_order_type_id' => $this->typeId('emergency'),
                'change_order_status_id' => $this->statusId(self::ACTIVE),
                'gc_decision_id' => $this->gcDecisionId('approved'),
                'originator_id' => $user->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'scope' => $data['scope'],
                'location' => $data['location'],
                'urgency_id' => $data['urgency_id'] ?? null,
                'value' => $data['value'] ?? null,
                'gc_decision_by' => $user->id,
                'gc_decision_at' => now(),
                'gc_decision_notes' => 'On-site GC representative signature (emergency).',
                'became_active_at' => now(),
                'created_by' => $user->id,
            ]);

            $attachment = $this->attachments->storeBase64Image($data['signature_image'], [
                'attachable_type' => ChangeOrder::class,
                'attachable_id' => $co->id,
                'project_id' => $project->id,
                'attachment_type' => 'signature',
                'directory' => 'change-order-signatures',
                'uploaded_by' => $user->id,
                'captured_at' => now(),
            ]);

            ChangeOrderSignature::create([
                'change_order_id' => $co->id,
                'signer_name' => $data['signer_name'],
                'signer_title' => $data['signer_title'] ?? null,
                'signer_company' => $data['signer_company'] ?? null,
                'signer_contact' => $data['signer_contact'] ?? null,
                'signature_attachment_id' => $attachment->id,
                'signed_at' => $data['signed_at'] ?? now(),
                'signed_lat' => $data['signed_lat'] ?? null,
                'signed_lng' => $data['signed_lng'] ?? null,
                'location_note' => $data['location_note'] ?? null,
                'captured_by' => $user->id,
                'device_info' => $data['device_info'] ?? null,
            ]);

            $this->logApproval($co, $user, 'submit', null, $this->statusId(self::ACTIVE), 'Emergency change order authorized on-site.');

            return $co->fresh(self::DETAIL_WITH);
        });
    }

    public function update(ChangeOrder $co, array $data): ChangeOrder
    {
        if (! in_array($this->statusCode($co), self::EDITABLE_STATUSES, true)) {
            abort(409, 'This change order can no longer be edited in its current status.');
        }
        $co->fill($data)->save();

        return $co->fresh(self::DETAIL_WITH);
    }

    // ---- Workflow transitions ----

    public function submit(ChangeOrder $co, User $user): ChangeOrder
    {
        $from = $this->statusCode($co);
        if (! in_array($from, [self::DRAFT, self::SENT_BACK], true)) {
            abort(409, "A change order in '{$from}' status cannot be submitted.");
        }
        if ($co->originator_id !== $user->id && ! $this->isAdmin($user)) {
            abort(403, 'Only the originator can submit this change order.');
        }

        return $this->transition($co, $user, 'submit', $from, self::PENDING_PM, null);
    }

    public function validateCo(ChangeOrder $co, User $user, ?string $comments): ChangeOrder
    {
        $from = $this->statusCode($co);
        if ($from !== self::PENDING_PM) {
            abort(409, "A change order in '{$from}' status cannot be validated.");
        }
        if (! $this->isPmLevel($user)) {
            abort(403, 'This step must be actioned by a project manager.');
        }

        return $this->transition($co, $user, 'validate', $from, self::PENDING_ADMIN, $comments);
    }

    public function approve(ChangeOrder $co, User $user, ?string $comments): ChangeOrder
    {
        $from = $this->statusCode($co);
        if ($from !== self::PENDING_ADMIN) {
            abort(409, "A change order in '{$from}' status cannot be approved.");
        }
        if (! $this->isAdmin($user)) {
            abort(403, 'This step must be actioned by an administrator.');
        }

        return $this->transition($co, $user, 'approve', $from, self::PENDING_COUNTER_SIGN, $comments);
    }

    public function counterSign(ChangeOrder $co, User $user, ?string $comments): ChangeOrder
    {
        $from = $this->statusCode($co);
        if ($from !== self::PENDING_COUNTER_SIGN) {
            abort(409, "A change order in '{$from}' status cannot be counter-signed.");
        }
        if (! $this->isAdmin($user)) {
            abort(403, 'Only an administrator can counter-sign a change order.');
        }
        if ($co->value === null) {
            abort(422, 'A base-bid value must be set before the change order can be counter-signed.');
        }

        $co->forceFill(['counter_signed_by' => $user->id, 'counter_signed_at' => now()])->save();

        return $this->transition($co, $user, 'counter_sign', $from, self::PENDING_GC, $comments);
    }

    public function setGcDecision(ChangeOrder $co, User $user, string $decision, ?string $notes): ChangeOrder
    {
        $from = $this->statusCode($co);
        if ($from !== self::PENDING_GC) {
            abort(409, 'The GC decision can only be recorded while the change order is pending GC.');
        }
        if (! $this->isPmLevel($user)) {
            abort(403, 'Only a project manager or administrator can record the GC decision.');
        }

        $to = $decision === 'approved' ? self::ACTIVE : self::GC_REJECTED;

        $co->forceFill([
            'gc_decision_id' => $this->gcDecisionId($decision),
            'gc_decision_by' => $user->id,
            'gc_decision_at' => now(),
            'gc_decision_notes' => $notes,
            'became_active_at' => $decision === 'approved' ? now() : null,
        ])->save();

        return $this->transition($co, $user, 'set_gc_status', $from, $to, $notes);
    }

    public function sendBack(ChangeOrder $co, User $user, string $comments): ChangeOrder
    {
        $from = $this->statusCode($co);
        $this->assertInternalReviewer($co, $user, $from);

        return $this->transition($co, $user, 'reject', $from, self::SENT_BACK, $comments);
    }

    public function reject(ChangeOrder $co, User $user, string $comments): ChangeOrder
    {
        $from = $this->statusCode($co);
        $this->assertInternalReviewer($co, $user, $from);

        return $this->transition($co, $user, 'reject', $from, self::REJECTED_INTERNAL, $comments);
    }

    public function cancel(ChangeOrder $co, User $user, ?string $comments): ChangeOrder
    {
        $from = $this->statusCode($co);
        if (in_array($from, [self::ACTIVE, self::GC_REJECTED, self::REJECTED_INTERNAL, self::CANCELLED], true)) {
            abort(409, "A change order in '{$from}' status can no longer be cancelled.");
        }
        if (! $this->isPmLevel($user) && $co->originator_id !== $user->id) {
            abort(403, 'You are not allowed to cancel this change order.');
        }

        return $this->transition($co, $user, 'reject', $from, self::CANCELLED, $comments);
    }

    // ---- Internals ----

    /** Only a PM/Admin may send back or reject, and only at a pending internal step. */
    private function assertInternalReviewer(ChangeOrder $co, User $user, string $from): void
    {
        if (! in_array($from, [self::PENDING_PM, self::PENDING_ADMIN], true)) {
            abort(409, "A change order in '{$from}' status cannot be sent back or rejected.");
        }
        if ($from === self::PENDING_PM && ! $this->isPmLevel($user)) {
            abort(403, 'This step must be actioned by a project manager.');
        }
        if ($from === self::PENDING_ADMIN && ! $this->isAdmin($user)) {
            abort(403, 'This step must be actioned by an administrator.');
        }
    }

    private function transition(ChangeOrder $co, User $user, string $action, ?string $fromCode, string $toCode, ?string $comments): ChangeOrder
    {
        return DB::transaction(function () use ($co, $user, $action, $fromCode, $toCode, $comments) {
            $fromId = $fromCode ? $this->statusId($fromCode) : null;
            $toId = $this->statusId($toCode);

            $co->update(['change_order_status_id' => $toId]);
            $this->logApproval($co, $user, $action, $fromId, $toId, $comments);

            return $co->fresh(self::DETAIL_WITH);
        });
    }

    /** Overloaded so callers can pass a status code OR a resolved id for from/to. */
    private function logApproval(ChangeOrder $co, User $user, string $action, ?int $fromId, ?int $toId, ?string $comments): void
    {
        $nextStep = (int) $co->approvals()->max('step_no') + 1;
        $co->approvals()->create([
            'step_no' => $nextStep,
            'actor_id' => $user->id,
            'actor_role' => $this->actorRole($user),
            'action' => $action,
            'comments' => $comments,
            'from_status_id' => $fromId,
            'to_status_id' => $toId,
            'acted_at' => now(),
        ]);
    }

    private function isAdmin(User $user): bool
    {
        $user->unsetRelation('roles');
        return $user->hasRole('Admin');
    }

    private function isPmLevel(User $user): bool
    {
        $user->unsetRelation('roles');
        return $user->hasRole(['Admin', 'Project Manager', 'Assistant Project Manager']);
    }

    private function actorRole(User $user): string
    {
        $user->unsetRelation('roles');
        foreach (['Admin', 'Project Manager', 'Assistant Project Manager'] as $role) {
            if ($user->hasRole($role)) {
                return $role;
            }
        }
        return $user->getRoleNames()->first() ?? 'User';
    }

    private function statusCode(ChangeOrder $co): string
    {
        return $co->status?->code ?? ChangeOrderStatus::whereKey($co->change_order_status_id)->value('code');
    }

    private function statusId(string $code): int
    {
        return $this->statusIdCache[$code] ??= ChangeOrderStatus::where('code', $code)->value('id')
            ?? abort(500, "Change order status '{$code}' is not seeded.");
    }

    private function typeId(string $code): int
    {
        return ChangeOrderType::where('code', $code)->value('id')
            ?? abort(500, "Change order type '{$code}' is not seeded.");
    }

    private function gcDecisionId(string $code): int
    {
        return GcDecision::where('code', $code)->value('id')
            ?? abort(500, "GC decision '{$code}' is not seeded.");
    }
}
