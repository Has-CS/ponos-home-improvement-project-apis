<?php

namespace App\Services\Rfq;

use App\Jobs\SendRfqEmailJob;
use App\Models\CatalogItem;
use App\Models\EmailLog;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\RfqStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Document\DocumentSequenceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Request for Quotation: draft -> sent, no approval chain.
 *
 * Unlike Material Request / Change Order, only one actor class (Admin/Project
 * Manager, both behind manage_rfqs) ever touches an RFQ, so there is nothing
 * to route between reviewers — this is a straight two-state machine.
 */
class RfqService
{
    private const DRAFT = 'draft';
    private const SENT = 'sent';

    private const LIST_WITH = ['status', 'vendor', 'project'];
    private const DETAIL_WITH = [
        'status', 'vendor', 'project', 'creator',
        'items.catalogItem', 'items.tradeCategory', 'items.unit',
    ];

    /** @var array<string,int> */
    private array $statusIdCache = [];

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly RfqPdfService $pdf,
    ) {}

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Rfq::query()->with(self::LIST_WITH);

        if (! empty($filters['search'])) {
            $query->where('rfq_no', 'ilike', '%'.$filters['search'].'%');
        }
        foreach (['vendor_id', 'project_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['status_id'])) {
            $query->where('rfq_status_id', $filters['status_id']);
        }

        return $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function findDetailed(Rfq $rfq): Rfq
    {
        return $rfq->load(self::DETAIL_WITH);
    }

    public function create(array $data, int $userId): Rfq
    {
        return DB::transaction(function () use ($data, $userId) {
            $rfq = Rfq::create([
                'rfq_no' => $this->sequences->next('rfq', 'RFQ'),
                'project_id' => $data['project_id'] ?? null,
                'vendor_id' => $data['vendor_id'],
                'rfq_status_id' => $this->statusId(self::DRAFT),
                'title' => $data['title'],
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['items'] ?? [] as $line) {
                $this->persistItem($rfq, $line);
            }

            return $rfq->fresh(self::DETAIL_WITH);
        });
    }

    public function update(Rfq $rfq, array $data): Rfq
    {
        $this->assertEditable($rfq);

        $rfq->fill($data)->save();

        return $rfq->fresh(self::DETAIL_WITH);
    }

    public function delete(Rfq $rfq, User $user): void
    {
        $this->assertEditable($rfq);

        if ($rfq->created_by !== $user->id && ! $this->isAdmin($user)) {
            abort(403, 'Only the creator can delete this draft.');
        }

        $rfq->items()->delete();
        $rfq->delete();
    }

    // ---- Line items ----

    public function addItem(Rfq $rfq, array $data): RfqItem
    {
        $this->assertEditable($rfq);

        $item = $this->persistItem($rfq, $data);

        return $item->load(['catalogItem', 'tradeCategory', 'unit']);
    }

    public function updateItem(Rfq $rfq, RfqItem $item, array $data): RfqItem
    {
        $this->assertEditable($rfq);

        // PATCH: catalog_item_id may be absent (unchanged), swapped, or
        // explicitly nulled to turn the line into free text. Derive against the
        // MERGED post-update state, same pattern as MaterialRequestService.
        if (array_key_exists('catalog_item_id', $data)) {
            $catalogItemId = $data['catalog_item_id'] !== null ? (int) $data['catalog_item_id'] : null;
        } else {
            $catalogItemId = $item->catalog_item_id !== null ? (int) $item->catalog_item_id : null;
        }

        $resolved = $this->resolveLineAttributes($catalogItemId, $data, $item);

        // The unit is the caller's own input — only ever DEFAULTED at create
        // time, never re-derived under them when the catalog item changes.
        unset($resolved['unit_id']);

        $item->fill([...$data, ...$resolved])->save();

        return $item->fresh(['catalogItem', 'tradeCategory', 'unit']);
    }

    public function removeItem(Rfq $rfq, RfqItem $item): void
    {
        $this->assertEditable($rfq);

        $item->delete();
    }

    // ---- Workflow ----

    /**
     * File the document, email it to the vendor, and flip draft -> sent.
     *
     * 422s rather than sending a broken request: at least one item must be on
     * the RFQ, and the target vendor must have an email on file to send to.
     */
    public function submit(Rfq $rfq, User $user): Rfq
    {
        $from = $this->statusCode($rfq);

        if ($from !== self::DRAFT) {
            abort(409, "An RFQ in '{$from}' status cannot be submitted.");
        }

        if ($rfq->items()->count() === 0) {
            abort(422, 'Add at least one item before submitting.');
        }

        $vendor = $rfq->vendor ?? Vendor::findOrFail($rfq->vendor_id);

        if (blank($vendor->email)) {
            abort(422, 'The target vendor has no email on file; add one before submitting.');
        }

        return DB::transaction(function () use ($rfq, $user, $vendor) {
            $this->pdf->storeFor($rfq, $user->id);

            $rfq->update([
                'rfq_status_id' => $this->statusId(self::SENT),
                'sent_at' => now(),
            ]);

            $emailLog = EmailLog::create([
                'to_email' => $vendor->email,
                'subject' => "Request for Quotation {$rfq->rfq_no} — {$rfq->title}",
                'template' => 'emails.rfq.quote-request',
                'mailable_type' => Rfq::class,
                'mailable_id' => $rfq->id,
                'status' => 'queued',
            ]);

            SendRfqEmailJob::dispatch($emailLog->id, $rfq->id)->afterCommit();

            return $rfq->fresh(self::DETAIL_WITH);
        });
    }

    // ---- Internals ----

    private function persistItem(Rfq $rfq, array $data): RfqItem
    {
        $catalogItemId = isset($data['catalog_item_id']) ? (int) $data['catalog_item_id'] : null;

        return $rfq->items()->create([
            'catalog_item_id' => $catalogItemId,
            'description' => $data['description'] ?? null,
            'quantity' => $data['quantity'],
            'notes' => $data['notes'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            ...$this->resolveLineAttributes($catalogItemId, $data),
        ]);
    }

    /**
     * Resolve the two line attributes the server owns rather than the caller —
     * identical rule to MaterialRequestService::resolveLineAttributes().
     *
     * trade_category_id is DERIVED from the catalog item when one is picked
     * (catalog_items.trade_category_id is NOT NULL), any client-sent value
     * ignored; on a free-text line the caller's value stands, since there is
     * nothing to derive it from.
     *
     * unit_id is only a FALLBACK to the catalog item's default — an explicit
     * unit is the caller's stated intent and always wins.
     *
     * $existing is passed on the update path so an unrelated PATCH cannot
     * blank a free-text line's stored category.
     *
     * @param  array<string,mixed>  $data
     * @return array{trade_category_id: ?int, unit_id: ?int}
     */
    private function resolveLineAttributes(?int $catalogItemId, array $data, ?RfqItem $existing = null): array
    {
        $catalogItem = $catalogItemId !== null ? CatalogItem::find($catalogItemId) : null;

        return [
            'trade_category_id' => $catalogItem?->trade_category_id
                ?? $data['trade_category_id']
                ?? $existing?->trade_category_id,
            'unit_id' => $data['unit_id']
                ?? $catalogItem?->default_unit_id
                ?? $existing?->unit_id,
        ];
    }

    private function assertEditable(Rfq $rfq): void
    {
        if ($this->statusCode($rfq) !== self::DRAFT) {
            abort(409, 'This RFQ can no longer be edited once it has been sent.');
        }
    }

    private function isAdmin(User $user): bool
    {
        $user->unsetRelation('roles');
        return $user->hasRole('Admin');
    }

    private function statusCode(Rfq $rfq): string
    {
        return $rfq->status?->code ?? RfqStatus::whereKey($rfq->rfq_status_id)->value('code');
    }

    private function statusId(string $code): int
    {
        return $this->statusIdCache[$code] ??= RfqStatus::where('code', $code)->value('id')
            ?? abort(500, "RFQ status '{$code}' is not seeded.");
    }
}
