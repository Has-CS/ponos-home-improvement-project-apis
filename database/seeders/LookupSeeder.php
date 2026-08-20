<?php

namespace Database\Seeders;

use App\Models\CatalogItemType;
use App\Models\ChangeOrderStatus;
use App\Models\ChangeOrderType;
use App\Models\CostCode;
use App\Models\DiscrepancyType;
use App\Models\Gender;
use App\Models\GcDecision;
use App\Models\IssueStatus;
use App\Models\MaterialRequestStatus;
use App\Models\MilestonePhase;
use App\Models\MilestoneStatus;
use App\Models\ProjectStatus;
use App\Models\ProjectType;
use App\Models\PurchaseOrderStatus;
use App\Models\RfqStatus;
use App\Models\TradeCategory;
use App\Models\Unit;
use App\Models\UserStatus;
use App\Models\Urgency;
use Illuminate\Database\Seeder;

/**
 * Seeds the ~16 reference/lookup tables that other tables have mandatory,
 * restrictOnDelete() FKs into. Without this, no user or project can be
 * created via the API (see DatabaseSeeder's former TODO comment).
 */
class LookupSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCoded(UserStatus::class, [
            ['code' => UserStatus::ACTIVE, 'label' => 'Active', 'sort_order' => 1, 'is_system' => true],
            ['code' => UserStatus::INVITED, 'label' => 'Invited', 'sort_order' => 2, 'is_system' => true],
            ['code' => UserStatus::SUSPENDED, 'label' => 'Suspended', 'sort_order' => 3, 'is_system' => true],
            ['code' => UserStatus::DISABLED, 'label' => 'Disabled', 'sort_order' => 4, 'is_system' => true],
        ]);

        $this->seedCoded(ProjectStatus::class, [
            ['code' => ProjectStatus::ACTIVE, 'label' => 'Active', 'sort_order' => 1, 'is_terminal' => false, 'is_system' => true],
            ['code' => ProjectStatus::ON_HOLD, 'label' => 'On Hold', 'sort_order' => 2, 'is_terminal' => false, 'is_system' => true],
            ['code' => ProjectStatus::COMPLETED, 'label' => 'Completed', 'sort_order' => 3, 'is_terminal' => true, 'is_system' => true],
            ['code' => ProjectStatus::ARCHIVED, 'label' => 'Archived', 'sort_order' => 4, 'is_terminal' => true, 'is_system' => true],
        ]);

        $this->seedCoded(Gender::class, [
            ['code' => 'male', 'label' => 'Male', 'sort_order' => 1],
            ['code' => 'female', 'label' => 'Female', 'sort_order' => 2],
            ['code' => 'other', 'label' => 'Other', 'sort_order' => 3],
            ['code' => 'prefer_not_to_say', 'label' => 'Prefer not to say', 'sort_order' => 4],
        ]);

        $this->seedCoded(ProjectType::class, [
            ['code' => 'residential', 'label' => 'Residential', 'sort_order' => 1],
            ['code' => 'commercial', 'label' => 'Commercial', 'sort_order' => 2],
            ['code' => 'renovation', 'label' => 'Renovation', 'sort_order' => 3],
        ]);

        $this->seedCoded(Unit::class, [
            ['code' => 'ea', 'label' => 'Each'],
            ['code' => 'sf', 'label' => 'Square Foot'],
            ['code' => 'lf', 'label' => 'Linear Foot'],
            ['code' => 'cy', 'label' => 'Cubic Yard'],
            ['code' => 'cf', 'label' => 'Cubic Foot'],
            ['code' => 'sy', 'label' => 'Square Yard'],
            ['code' => 'hr', 'label' => 'Hour'],
            ['code' => 'day', 'label' => 'Day'],
            ['code' => 'box', 'label' => 'Box'],
            ['code' => 'bag', 'label' => 'Bag'],
            ['code' => 'roll', 'label' => 'Roll'],
            ['code' => 'sheet', 'label' => 'Sheet'],
            ['code' => 'gal', 'label' => 'Gallon'],
            ['code' => 'ton', 'label' => 'Ton'],
            ['code' => 'ls', 'label' => 'Lump Sum'],
        ]);

        $this->seedCoded(CatalogItemType::class, [
            ['code' => 'material', 'label' => 'Material'],
            ['code' => 'labor', 'label' => 'Labor'],
            ['code' => 'subcontractor', 'label' => 'Subcontractor'],
        ]);

        $this->seedCoded(Urgency::class, [
            ['code' => 'low', 'label' => 'Low', 'sort_order' => 1],
            ['code' => 'normal', 'label' => 'Normal', 'sort_order' => 2],
            ['code' => 'high', 'label' => 'High', 'sort_order' => 3],
            ['code' => 'critical', 'label' => 'Critical', 'sort_order' => 4],
        ]);

        $this->seedCoded(MaterialRequestStatus::class, [
            ['code' => 'draft', 'label' => 'Draft', 'sort_order' => 1, 'is_terminal' => false],
            ['code' => 'pending_pm', 'label' => 'Pending PM', 'sort_order' => 2, 'is_terminal' => false],
            ['code' => 'sent_back_to_foreman', 'label' => 'Sent Back to Foreman', 'sort_order' => 3, 'is_terminal' => false],
            ['code' => 'pending_admin', 'label' => 'Pending Admin', 'sort_order' => 4, 'is_terminal' => false],
            ['code' => 'sent_back_to_pm', 'label' => 'Sent Back to PM', 'sort_order' => 5, 'is_terminal' => false],
            ['code' => 'rejected', 'label' => 'Rejected', 'sort_order' => 6, 'is_terminal' => true],
            ['code' => 'approved', 'label' => 'Approved', 'sort_order' => 7, 'is_terminal' => false],
            ['code' => 'ordered', 'label' => 'Ordered', 'sort_order' => 8, 'is_terminal' => false],
            ['code' => 'partially_delivered', 'label' => 'Partially Delivered', 'sort_order' => 9, 'is_terminal' => false],
            ['code' => 'delivered', 'label' => 'Delivered', 'sort_order' => 10, 'is_terminal' => true],
            ['code' => 'returned', 'label' => 'Returned', 'sort_order' => 11, 'is_terminal' => true],
        ]);

        $this->seedCoded(PurchaseOrderStatus::class, [
            ['code' => 'draft', 'label' => 'Draft', 'sort_order' => 1, 'is_terminal' => false],
            ['code' => 'issued', 'label' => 'Issued', 'sort_order' => 2, 'is_terminal' => false],
            ['code' => 'sent', 'label' => 'Sent', 'sort_order' => 3, 'is_terminal' => false],
            ['code' => 'acknowledged', 'label' => 'Acknowledged', 'sort_order' => 4, 'is_terminal' => false],
            ['code' => 'partially_received', 'label' => 'Partially Received', 'sort_order' => 5, 'is_terminal' => false],
            ['code' => 'received', 'label' => 'Received', 'sort_order' => 6, 'is_terminal' => true],
            ['code' => 'cancelled', 'label' => 'Cancelled', 'sort_order' => 7, 'is_terminal' => true],
        ]);

        $this->seedCoded(DiscrepancyType::class, [
            ['code' => 'short_shipment', 'label' => 'Short Shipment'],
            ['code' => 'damage', 'label' => 'Damage'],
            ['code' => 'substitution', 'label' => 'Substitution'],
            ['code' => 'overage', 'label' => 'Overage'],
            ['code' => 'wrong_item', 'label' => 'Wrong Item'],
        ]);

        $this->seedCoded(IssueStatus::class, [
            ['code' => 'open', 'label' => 'Open', 'sort_order' => 1, 'is_terminal' => false],
            ['code' => 'in_progress', 'label' => 'In Progress', 'sort_order' => 2, 'is_terminal' => false],
            ['code' => 'resolved', 'label' => 'Resolved', 'sort_order' => 3, 'is_terminal' => true],
            ['code' => 'closed', 'label' => 'Closed', 'sort_order' => 4, 'is_terminal' => true],
        ]);

        $this->seedCoded(ChangeOrderType::class, [
            ['code' => 'normal', 'label' => 'Normal'],
            ['code' => 'emergency', 'label' => 'Emergency'],
        ]);

        $this->seedCoded(ChangeOrderStatus::class, [
            ['code' => 'draft', 'label' => 'Draft', 'sort_order' => 1, 'is_terminal' => false],
            ['code' => 'pending_pm', 'label' => 'Pending PM', 'sort_order' => 2, 'is_terminal' => false],
            ['code' => 'pending_admin', 'label' => 'Pending Admin', 'sort_order' => 3, 'is_terminal' => false],
            ['code' => 'sent_back', 'label' => 'Sent Back', 'sort_order' => 4, 'is_terminal' => false],
            ['code' => 'rejected_internal', 'label' => 'Rejected (Internal)', 'sort_order' => 5, 'is_terminal' => true],
            // Between Admin review and the counter-signature: the PM prepares the
            // formal change-order document. See migration 2026_08_16_000001.
            ['code' => 'pending_document', 'label' => 'Pending Document', 'sort_order' => 6, 'is_terminal' => false],
            ['code' => 'pending_counter_sign', 'label' => 'Pending Counter-Sign', 'sort_order' => 7, 'is_terminal' => false],
            ['code' => 'pending_gc', 'label' => 'Pending GC', 'sort_order' => 8, 'is_terminal' => false],
            ['code' => 'active', 'label' => 'Active', 'sort_order' => 9, 'is_terminal' => true],
            ['code' => 'gc_rejected', 'label' => 'GC Rejected', 'sort_order' => 10, 'is_terminal' => true],
            ['code' => 'cancelled', 'label' => 'Cancelled', 'sort_order' => 11, 'is_terminal' => true],
        ]);

        $this->seedCoded(RfqStatus::class, [
            ['code' => 'draft', 'label' => 'Draft', 'sort_order' => 1, 'is_terminal' => false],
            ['code' => 'sent', 'label' => 'Sent', 'sort_order' => 2, 'is_terminal' => true],
        ]);

        $this->seedCoded(GcDecision::class, [
            ['code' => 'pending', 'label' => 'Pending'],
            ['code' => 'approved', 'label' => 'Approved'],
            ['code' => 'rejected', 'label' => 'Rejected'],
        ]);

        $this->seedCoded(MilestonePhase::class, [
            ['code' => MilestonePhase::PRE_CONSTRUCTION, 'label' => 'Pre-Construction', 'sort_order' => 1],
            ['code' => MilestonePhase::DESIGN, 'label' => 'Design', 'sort_order' => 2],
            ['code' => MilestonePhase::PROCUREMENT, 'label' => 'Procurement', 'sort_order' => 3],
            ['code' => MilestonePhase::CONSTRUCTION, 'label' => 'Construction', 'sort_order' => 4],
            ['code' => MilestonePhase::CLOSEOUT, 'label' => 'Closeout', 'sort_order' => 5],
        ]);

        $this->seedCoded(MilestoneStatus::class, [
            ['code' => MilestoneStatus::NOT_STARTED, 'label' => 'Not Started', 'sort_order' => 1, 'is_terminal' => false, 'is_system' => true],
            ['code' => MilestoneStatus::IN_PROGRESS, 'label' => 'In Progress', 'sort_order' => 2, 'is_terminal' => false, 'is_system' => true],
            ['code' => MilestoneStatus::COMPLETED, 'label' => 'Completed', 'sort_order' => 3, 'is_terminal' => true, 'is_system' => true],
            ['code' => MilestoneStatus::DELAYED, 'label' => 'Delayed', 'sort_order' => 4, 'is_terminal' => false, 'is_system' => true],
            ['code' => MilestoneStatus::ON_HOLD, 'label' => 'On Hold', 'sort_order' => 5, 'is_terminal' => false, 'is_system' => true],
            ['code' => MilestoneStatus::CANCELLED, 'label' => 'Cancelled', 'sort_order' => 6, 'is_terminal' => true, 'is_system' => true],
        ]);

        // trade_categories uses 'name', not 'code'/'label' — flat top-level seed
        // covering the trades typical of a residential remodel.
        foreach (
            [
                ['name' => 'Doors', 'sort_order' => 1],
                ['name' => 'Windows', 'sort_order' => 2],
                ['name' => 'Tiles', 'sort_order' => 3],
                ['name' => 'Flooring', 'sort_order' => 4],
                ['name' => 'Labor', 'sort_order' => 5],
                ['name' => 'Finishes', 'sort_order' => 6],
                ['name' => 'Cabinets', 'sort_order' => 7],
                ['name' => 'Countertops', 'sort_order' => 8],
                ['name' => 'Floor Sheets', 'sort_order' => 9],
                ['name' => 'Concrete', 'sort_order' => 10],
                ['name' => 'Masonry', 'sort_order' => 11],
                ['name' => 'Framing & Carpentry', 'sort_order' => 12],
                ['name' => 'Roofing', 'sort_order' => 13],
                ['name' => 'Plumbing', 'sort_order' => 14],
                ['name' => 'Electrical', 'sort_order' => 15],
                ['name' => 'HVAC', 'sort_order' => 16],
                ['name' => 'Insulation', 'sort_order' => 17],
                ['name' => 'Drywall', 'sort_order' => 18],
                ['name' => 'Painting', 'sort_order' => 19],
                ['name' => 'Appliances', 'sort_order' => 20],
                ['name' => 'Sitework & Landscaping', 'sort_order' => 21],
                ['name' => 'Demolition', 'sort_order' => 22],
            ] as $row
        ) {
            TradeCategory::firstOrCreate(['parent_id' => null, 'name' => $row['name']], $row);
        }

        // cost_codes: CSI-MasterFormat-inspired, scoped to the divisions a
        // residential/home-improvement contractor actually uses. Flat for now
        // (parent_id supports nesting whenever finer sub-codes are needed).
        $this->seedCoded(CostCode::class, [
            ['code' => '01-GENERAL', 'name' => 'General Requirements'],
            ['code' => '02-SITE', 'name' => 'Site Work & Demolition'],
            ['code' => '03-CONCRETE', 'name' => 'Concrete'],
            ['code' => '04-MASONRY', 'name' => 'Masonry'],
            ['code' => '05-METALS', 'name' => 'Metals'],
            ['code' => '06-WOOD', 'name' => 'Wood & Carpentry'],
            ['code' => '07-THERMAL-MOISTURE', 'name' => 'Thermal & Moisture Protection'],
            ['code' => '08-DOORS-WINDOWS', 'name' => 'Doors & Windows'],
            ['code' => '09-FINISHES', 'name' => 'Finishes'],
            ['code' => '10-SPECIALTIES', 'name' => 'Specialties'],
            ['code' => '11-EQUIPMENT', 'name' => 'Equipment & Appliances'],
            ['code' => '15-PLUMBING', 'name' => 'Plumbing'],
            ['code' => '15-MECHANICAL', 'name' => 'HVAC / Mechanical'],
            ['code' => '16-ELECTRICAL', 'name' => 'Electrical'],
            ['code' => '32-SITEWORK', 'name' => 'Sitework & Landscaping'],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function seedCoded(string $modelClass, array $rows): void
    {
        foreach ($rows as $row) {
            $modelClass::firstOrCreate(['code' => $row['code']], $row);
        }
    }
}
