<?php

namespace App\Services\ActionItems;

use App\Models\ChangeOrderStatus;
use App\Models\ChangeOrderType;
use App\Models\IssueStatus;
use App\Models\MaterialRequestStatus;
use App\Models\MilestoneStatus;
use App\Models\ProjectStatus;
use App\Models\PurchaseOrderStatus;
use App\Models\RfqStatus;
use App\Models\UserStatus;

/**
 * Lookup `code` → id, resolved once per table per request.
 *
 * Aggregates filter on the indexed FK id rather than joining the lookup table
 * on every query. Codes are the same ones the workflow services hard-code
 * (see LookupSeeder); a code that is not seeded resolves to 0, which matches
 * nothing, so a missing row degrades to a zero count instead of an error.
 */
class StatusIds
{
    private const TABLES = [
        'mr' => MaterialRequestStatus::class,
        'co' => ChangeOrderStatus::class,
        'co_type' => ChangeOrderType::class,
        'po' => PurchaseOrderStatus::class,
        'issue' => IssueStatus::class,
        'milestone' => MilestoneStatus::class,
        'project' => ProjectStatus::class,
        'rfq' => RfqStatus::class,
        'user' => UserStatus::class,
    ];

    /** @var array<string,array<string,int>> */
    private array $maps = [];

    public function id(string $table, string $code): int
    {
        return $this->map($table)[$code] ?? 0;
    }

    /**
     * @param  array<int,string>  $codes
     * @return array<int,int>
     */
    public function ids(string $table, array $codes): array
    {
        return array_map(fn (string $code) => $this->id($table, $code), $codes);
    }

    /** @return array<string,int> code => id */
    public function map(string $table): array
    {
        return $this->maps[$table] ??= (self::TABLES[$table])::query()
            ->pluck('id', 'code')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<int,string> id => code */
    public function codes(string $table): array
    {
        return array_flip($this->map($table));
    }
}
