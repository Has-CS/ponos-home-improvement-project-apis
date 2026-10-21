<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogItemController;
use App\Http\Controllers\Api\V1\ChangeOrderController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\DailyLogController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeliveryController;
use App\Http\Controllers\Api\V1\IssueController;
use App\Http\Controllers\Api\V1\CostCodeController;
use App\Http\Controllers\Api\V1\Lookup\CatalogItemTypeLookupController;
use App\Http\Controllers\Api\V1\Lookup\ChangeOrderStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\ChangeOrderTypeLookupController;
use App\Http\Controllers\Api\V1\Lookup\DiscrepancyTypeLookupController;
use App\Http\Controllers\Api\V1\Lookup\GcDecisionLookupController;
use App\Http\Controllers\Api\V1\Lookup\GenderLookupController;
use App\Http\Controllers\Api\V1\Lookup\IssueStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\MaterialRequestStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\MilestonePhaseLookupController;
use App\Http\Controllers\Api\V1\Lookup\MilestoneStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\ProjectStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\ProjectTypeLookupController;
use App\Http\Controllers\Api\V1\Lookup\PurchaseOrderStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\RfqStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\UnitLookupController;
use App\Http\Controllers\Api\V1\Lookup\UrgencyLookupController;
use App\Http\Controllers\Api\V1\Lookup\UserStatusLookupController;
use App\Http\Controllers\Api\V1\MaterialRequestController;
use App\Http\Controllers\Api\V1\MilestoneController;
use App\Http\Controllers\Api\V1\ProjectAssignmentController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectDeliveryAddressController;
use App\Http\Controllers\Api\V1\ChangeOrderTermsController;
use App\Http\Controllers\Api\V1\ProjectGeneralContractorController;
use App\Http\Controllers\Api\V1\GcRepresentativeController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseOrderTermsController;
use App\Http\Controllers\Api\V1\RbacController;
use App\Http\Controllers\Api\V1\RfqController;
use App\Http\Controllers\Api\V1\TradeCategoryController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserRoleController;
use App\Http\Controllers\Api\V1\VendorController;
use App\Http\Controllers\Api\V1\VendorRateController;
use Illuminate\Support\Facades\Route;

/**
 * Every route parameter in this file binds to a model with a bigint primary key, so
 * a segment that is not digits can never be a valid id. Constrained here, in one
 * place, rather than with ->whereNumber() on ~200 routes.
 *
 * This is a bug fix, not tidiness. Without it a wrong URL returns 500, not 404:
 * implicit binding hands the raw segment to Postgres, which refuses the cast with
 * SQLSTATE 22P02 ("invalid input syntax for type bigint"), and the resulting
 * QueryException is one the exception handler is right to mask as a generic
 * "Something went wrong" — its message carries SQL. So the caller gets a server
 * error, and the log gets a stack trace, for what is only a mistyped path.
 *
 * It bit a real case: GET /api/v1/projects/87/change-orders/gc-queue. The oversight
 * queue is top-level (/api/v1/change-orders/gc-queue), so that path instead matched
 * projects/{project}/change-orders/{change_order} with change_order = "gc-queue".
 *
 * With the pattern in place the route simply does not match, Laravel raises its own
 * NotFoundHttpException, and the handler renders a clean 404.
 *
 * Safe to apply globally: no route uses custom key binding ({param:field}), no model
 * overrides getRouteKeyName(), and there is no Route::bind() anywhere — so nothing
 * resolves a parameter by slug or code. Add a parameter here whenever a new
 * id-bound route parameter appears; a non-numeric key (a slug, a uuid) must NOT be
 * added, and would need its own pattern.
 */
Route::patterns(array_fill_keys([
    'attachment',
    'catalog_item',
    'catalog_item_type',
    'change_order',
    'change_order_status',
    'change_order_term',
    'change_order_type',
    'client',
    'cost_code',
    'daily_log',
    'delivery',
    'delivery_address',
    'discrepancy_type',
    'gc_decision',
    'gender',
    'general_contractor',
    'issue',
    'issue_status',
    'item',
    'material_request',
    'material_request_status',
    'milestone',
    'milestone_phase',
    'milestone_status',
    'project',
    'project_status',
    'project_type',
    'purchase_order',
    'purchase_order_status',
    'purchase_order_term',
    'representative',
    'rfq',
    'rfq_status',
    'role',
    'trade_category',
    'unit',
    'urgency',
    'user',
    'user_status',
    'vendor',
    'vendor_rate',
], '[0-9]+'));

Route::prefix('v1')->group(function () {

    // ---- Public ----
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/reset-password-request', [AuthController::class, 'resetPasswordRequest']);
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);

    // ---- Authenticated (single outer group: JWT guard + global permission scope) ----
    Route::middleware(['auth:api', 'team.global'])->group(function () {

        // ---- Auth (any authenticated user) ----
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/refresh', [AuthController::class, 'refresh']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/roles-permissions', [RbacController::class, 'catalog']);
        Route::get('auth/permissions', [RbacController::class, 'myAccess']);

        // ---- Dashboard (any authenticated user; sections are permission-driven
        // and project-scoped inside DashboardService, not by middleware) ----
        Route::get('dashboard', [DashboardController::class, 'index']);

        // ---- Users (gated by the `manage_users` capability, not a hardcoded role —
        // reassignable at runtime via PUT /roles/{role}/permissions or the
        // per-user `permissions` field on PATCH /users/{user}, no route change needed) ----
        Route::middleware('permission:manage_users')->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::get('users/{user}', [UserController::class, 'show']);
            Route::patch('users/{user}', [UserController::class, 'update']);
            Route::delete('users/{user}', [UserController::class, 'destroy']);
        });

        // ---- RBAC administration — intentionally role-gated, not
        // permission-gated: these actions define/grant permissions themselves,
        // so gating them by permission would let a holder grant themselves
        // broader access — a privilege-escalation path. No permission in the
        // seeded catalog maps to "administer RBAC" for this reason;
        // identity-based gating stays here by design.
        //
        // Admin-only: redefining what a role means (or creating a new
        // permission/role) is company-wide configuration that instantly
        // affects every project, and granting/revoking a GLOBAL role is a
        // company-level access decision — neither is project management, so
        // Project Manager does not belong on this gate even though it does
        // on project staffing below.
        Route::middleware('role:Admin')->group(function () {
            Route::post('permissions', [RbacController::class, 'storePermission']);
            Route::post('roles', [RbacController::class, 'storeRole']);
            Route::put('roles/{role}/permissions', [RbacController::class, 'updateRolePermissions']);

            Route::post('users/{user}/assign-role', [UserRoleController::class, 'assign']);
            Route::delete('users/{user}/roles/{role}', [UserRoleController::class, 'revoke']);
        });

        // Project staffing (writes project_user + model_has_roles): Admin, or
        // a PM who is actually staffed on the target project. `project.access`
        // stacks with the identity gate for the same reason it does on Change
        // Orders/Daily Logs — holding the PM role globally says nothing about
        // which project you manage.
        Route::middleware(['project.access', 'role:Admin|Project Manager'])->group(function () {
            Route::post('projects/{project}/assign-user', [ProjectAssignmentController::class, 'assign']);
            Route::delete('projects/{project}/users/{user}', [ProjectAssignmentController::class, 'remove']);
            Route::delete('projects/{project}/users/{user}/roles/{role}', [ProjectAssignmentController::class, 'revokeRole']);
        });

        // ---- Projects: any authenticated user ----
        Route::get('projects/mine', [ProjectController::class, 'mine']);      // switchable projects
        Route::get('projects', [ProjectController::class, 'index']);          // scoped list

        // ---- Clients: any authenticated user can browse (needed when creating/
        // filtering a project); writes gated by manage_clients (Admin-only).
        Route::get('clients', [ClientController::class, 'index']);
        Route::get('clients/{client}', [ClientController::class, 'show']);

        Route::middleware('permission:manage_clients')->group(function () {
            Route::post('clients', [ClientController::class, 'store']);
            Route::patch('clients/{client}', [ClientController::class, 'update']);
            Route::delete('clients/{client}', [ClientController::class, 'destroy']);
        });

        // ---- Lookup tables (genders, statuses, types, phases): dropdown/
        // reference data. Index open to any authenticated user, no
        // project-membership check — these are global reference tables, not
        // project-scoped. Writes gated by manage_lookups (Admin-only).
        Route::get('genders', [GenderLookupController::class, 'index']);
        Route::get('genders/{gender}', [GenderLookupController::class, 'show']);
        Route::get('user-statuses', [UserStatusLookupController::class, 'index']);
        Route::get('user-statuses/{user_status}', [UserStatusLookupController::class, 'show']);
        Route::get('project-statuses', [ProjectStatusLookupController::class, 'index']);
        Route::get('project-statuses/{project_status}', [ProjectStatusLookupController::class, 'show']);
        Route::get('project-types', [ProjectTypeLookupController::class, 'index']);
        Route::get('project-types/{project_type}', [ProjectTypeLookupController::class, 'show']);
        Route::get('milestone-phases', [MilestonePhaseLookupController::class, 'index']);
        Route::get('milestone-phases/{milestone_phase}', [MilestonePhaseLookupController::class, 'show']);
        Route::get('milestone-statuses', [MilestoneStatusLookupController::class, 'index']);
        Route::get('milestone-statuses/{milestone_status}', [MilestoneStatusLookupController::class, 'show']);

        // ---- Pricing & Estimation lookups (cost codes, trade categories,
        // units, catalog item types): same openness/gating convention as the
        // lookups above — open read, manage_lookups-gated writes.
        Route::get('cost-codes', [CostCodeController::class, 'index']);
        Route::get('cost-codes/{cost_code}', [CostCodeController::class, 'show']);
        Route::get('trade-categories', [TradeCategoryController::class, 'index']);
        Route::get('trade-categories/{trade_category}', [TradeCategoryController::class, 'show']);
        Route::get('units', [UnitLookupController::class, 'index']);
        Route::get('units/{unit}', [UnitLookupController::class, 'show']);
        Route::get('catalog-item-types', [CatalogItemTypeLookupController::class, 'index']);
        Route::get('catalog-item-types/{catalog_item_type}', [CatalogItemTypeLookupController::class, 'show']);

        // ---- Material Request & Purchase Order lookups (urgencies, MR/PO
        // statuses, discrepancy types, issue statuses): same open-read,
        // manage_lookups-gated-write convention as every other lookup above —
        // these are workflow dropdowns needed by field roles (Foreman, Site
        // Engineer) who don't hold view_pricing, so they stay open-read.
        Route::get('urgencies', [UrgencyLookupController::class, 'index']);
        Route::get('urgencies/{urgency}', [UrgencyLookupController::class, 'show']);
        Route::get('material-request-statuses', [MaterialRequestStatusLookupController::class, 'index']);
        Route::get('material-request-statuses/{material_request_status}', [MaterialRequestStatusLookupController::class, 'show']);
        Route::get('purchase-order-statuses', [PurchaseOrderStatusLookupController::class, 'index']);
        Route::get('purchase-order-statuses/{purchase_order_status}', [PurchaseOrderStatusLookupController::class, 'show']);
        Route::get('discrepancy-types', [DiscrepancyTypeLookupController::class, 'index']);
        Route::get('discrepancy-types/{discrepancy_type}', [DiscrepancyTypeLookupController::class, 'show']);
        Route::get('issue-statuses', [IssueStatusLookupController::class, 'index']);
        Route::get('issue-statuses/{issue_status}', [IssueStatusLookupController::class, 'show']);

        // ---- Change Order lookups (types, statuses, GC decisions): same
        // open-read, manage_lookups-gated-write convention as every lookup above.
        Route::get('change-order-types', [ChangeOrderTypeLookupController::class, 'index']);
        Route::get('change-order-types/{change_order_type}', [ChangeOrderTypeLookupController::class, 'show']);
        Route::get('change-order-statuses', [ChangeOrderStatusLookupController::class, 'index']);
        Route::get('change-order-statuses/{change_order_status}', [ChangeOrderStatusLookupController::class, 'show']);
        Route::get('gc-decisions', [GcDecisionLookupController::class, 'index']);
        Route::get('gc-decisions/{gc_decision}', [GcDecisionLookupController::class, 'show']);

        // ---- RFQ statuses: same open-read, manage_lookups-gated-write
        // convention as every lookup above.
        Route::get('rfq-statuses', [RfqStatusLookupController::class, 'index']);
        Route::get('rfq-statuses/{rfq_status}', [RfqStatusLookupController::class, 'show']);

        Route::middleware('permission:manage_lookups')->group(function () {
            Route::post('genders', [GenderLookupController::class, 'store']);
            Route::patch('genders/{gender}', [GenderLookupController::class, 'update']);
            Route::delete('genders/{gender}', [GenderLookupController::class, 'destroy']);

            Route::post('user-statuses', [UserStatusLookupController::class, 'store']);
            Route::patch('user-statuses/{user_status}', [UserStatusLookupController::class, 'update']);
            Route::delete('user-statuses/{user_status}', [UserStatusLookupController::class, 'destroy']);

            Route::post('project-statuses', [ProjectStatusLookupController::class, 'store']);
            Route::patch('project-statuses/{project_status}', [ProjectStatusLookupController::class, 'update']);
            Route::delete('project-statuses/{project_status}', [ProjectStatusLookupController::class, 'destroy']);

            Route::post('project-types', [ProjectTypeLookupController::class, 'store']);
            Route::patch('project-types/{project_type}', [ProjectTypeLookupController::class, 'update']);
            Route::delete('project-types/{project_type}', [ProjectTypeLookupController::class, 'destroy']);

            Route::post('milestone-phases', [MilestonePhaseLookupController::class, 'store']);
            Route::patch('milestone-phases/{milestone_phase}', [MilestonePhaseLookupController::class, 'update']);
            Route::delete('milestone-phases/{milestone_phase}', [MilestonePhaseLookupController::class, 'destroy']);

            Route::post('milestone-statuses', [MilestoneStatusLookupController::class, 'store']);
            Route::patch('milestone-statuses/{milestone_status}', [MilestoneStatusLookupController::class, 'update']);
            Route::delete('milestone-statuses/{milestone_status}', [MilestoneStatusLookupController::class, 'destroy']);

            Route::post('cost-codes', [CostCodeController::class, 'store']);
            Route::patch('cost-codes/{cost_code}', [CostCodeController::class, 'update']);
            Route::delete('cost-codes/{cost_code}', [CostCodeController::class, 'destroy']);

            Route::post('trade-categories', [TradeCategoryController::class, 'store']);




            

            Route::post('units', [UnitLookupController::class, 'store']);
            Route::patch('units/{unit}', [UnitLookupController::class, 'update']);
            Route::delete('units/{unit}', [UnitLookupController::class, 'destroy']);

            Route::post('catalog-item-types', [CatalogItemTypeLookupController::class, 'store']);
            Route::patch('catalog-item-types/{catalog_item_type}', [CatalogItemTypeLookupController::class, 'update']);
            Route::delete('catalog-item-types/{catalog_item_type}', [CatalogItemTypeLookupController::class, 'destroy']);

            Route::post('urgencies', [UrgencyLookupController::class, 'store']);
            Route::patch('urgencies/{urgency}', [UrgencyLookupController::class, 'update']);
            Route::delete('urgencies/{urgency}', [UrgencyLookupController::class, 'destroy']);

            Route::post('material-request-statuses', [MaterialRequestStatusLookupController::class, 'store']);
            Route::patch('material-request-statuses/{material_request_status}', [MaterialRequestStatusLookupController::class, 'update']);
            Route::delete('material-request-statuses/{material_request_status}', [MaterialRequestStatusLookupController::class, 'destroy']);

            Route::post('purchase-order-statuses', [PurchaseOrderStatusLookupController::class, 'store']);
            Route::patch('purchase-order-statuses/{purchase_order_status}', [PurchaseOrderStatusLookupController::class, 'update']);
            Route::delete('purchase-order-statuses/{purchase_order_status}', [PurchaseOrderStatusLookupController::class, 'destroy']);

            Route::post('discrepancy-types', [DiscrepancyTypeLookupController::class, 'store']);
            Route::patch('discrepancy-types/{discrepancy_type}', [DiscrepancyTypeLookupController::class, 'update']);
            Route::delete('discrepancy-types/{discrepancy_type}', [DiscrepancyTypeLookupController::class, 'destroy']);

            Route::post('issue-statuses', [IssueStatusLookupController::class, 'store']);
            Route::patch('issue-statuses/{issue_status}', [IssueStatusLookupController::class, 'update']);
            Route::delete('issue-statuses/{issue_status}', [IssueStatusLookupController::class, 'destroy']);

            Route::post('change-order-types', [ChangeOrderTypeLookupController::class, 'store']);
            Route::patch('change-order-types/{change_order_type}', [ChangeOrderTypeLookupController::class, 'update']);
            Route::delete('change-order-types/{change_order_type}', [ChangeOrderTypeLookupController::class, 'destroy']);

            Route::post('change-order-statuses', [ChangeOrderStatusLookupController::class, 'store']);
            Route::patch('change-order-statuses/{change_order_status}', [ChangeOrderStatusLookupController::class, 'update']);
            Route::delete('change-order-statuses/{change_order_status}', [ChangeOrderStatusLookupController::class, 'destroy']);

            Route::post('gc-decisions', [GcDecisionLookupController::class, 'store']);
            Route::patch('gc-decisions/{gc_decision}', [GcDecisionLookupController::class, 'update']);
            Route::delete('gc-decisions/{gc_decision}', [GcDecisionLookupController::class, 'destroy']);

            Route::post('rfq-statuses', [RfqStatusLookupController::class, 'store']);
            Route::patch('rfq-statuses/{rfq_status}', [RfqStatusLookupController::class, 'update']);
            Route::delete('rfq-statuses/{rfq_status}', [RfqStatusLookupController::class, 'destroy']);
        });

        // ---- Pricing & Estimation core: Vendors, Catalog Items, Vendor Rates.
        // Unlike the plain lookup tables above, this data is commercially
        // sensitive (negotiated vendor pricing) — reads gated by view_pricing,
        // writes by edit_pricing, both already seeded to PM/Procurement (view+edit)
        // and Assistant PM (view only), Admin via wildcard.
        Route::middleware('permission:view_pricing')->group(function () {
            Route::get('vendors', [VendorController::class, 'index']);
            Route::get('vendors/{vendor}', [VendorController::class, 'show']);
            Route::get('catalog-items', [CatalogItemController::class, 'index']);
            Route::get('catalog-items/{catalog_item}', [CatalogItemController::class, 'show']);
            Route::get('vendor-rates', [VendorRateController::class, 'index']);
            Route::get('vendor-rates/{vendor_rate}', [VendorRateController::class, 'show']);
        });

        Route::middleware('permission:edit_pricing')->group(function () {
            Route::post('vendors', [VendorController::class, 'store']);
            Route::patch('vendors/{vendor}', [VendorController::class, 'update']);
            Route::patch('vendors/{vendor}/status', [VendorController::class, 'updateStatus']);
            Route::delete('vendors/{vendor}', [VendorController::class, 'destroy']);

            Route::post('catalog-items', [CatalogItemController::class, 'store']);
            Route::patch('catalog-items/{catalog_item}', [CatalogItemController::class, 'update']);
            Route::patch('catalog-items/{catalog_item}/status', [CatalogItemController::class, 'updateStatus']);
            Route::delete('catalog-items/{catalog_item}', [CatalogItemController::class, 'destroy']);

            // Vendor Rates: create-only — an append-only pricing ledger. No
            // update/destroy route exists; addRate() closes the prior open
            // rate automatically instead of ever overwriting history.
            Route::post('vendor-rates', [VendorRateController::class, 'store']);
        });

        // ---- Projects: single-project reads — must have access to THIS project.
        // NOTE: intentionally NOT permission-gated in addition to `project.access`.
        // A user's role/permission grant for a specific project lives under that
        // project's Spatie team scope, but `team.global` (wrapping this whole
        // group) fixes the scope to global (0) — so a permission check here would
        // evaluate the wrong scope and could reject a legitimately project-scoped
        // member. `project.access` already enforces membership directly against
        // project_user, independent of Spatie scope, which is correct as-is.
        Route::middleware('project.access')->group(function () {
            Route::get('projects/{project}', [ProjectController::class, 'show']);
            Route::get('projects/{project}/users', [ProjectAssignmentController::class, 'users']);
            Route::get('projects/{project}/milestones', [MilestoneController::class, 'index']);
            Route::get('projects/{project}/change-orders', [ChangeOrderController::class, 'index']);
            Route::get('projects/{project}/change-orders/{change_order}', [ChangeOrderController::class, 'show']);

            // The change-order document. Same gate as show() on purpose — if you
            // can view the change order you can print it, in any status. Serves
            // the filed copy once it has been sent to the GC (so what the GC was
            // sent is retrievable byte-for-byte) and renders live with a DRAFT
            // watermark before that. ?download=1 forces save-as.
            Route::get('projects/{project}/change-orders/{change_order}/pdf', [ChangeOrderController::class, 'pdf']);

            // The Work Authorization sheet: what a GC representative signed on
            // site, with their signature, and no price. Emergency change orders
            // only — 404 on a Normal one, which has no on-site authorization.
            //
            // Same gate as the change-order document, and for the same reason: if
            // you can view the change order you can print its paperwork.
            Route::get('projects/{project}/change-orders/{change_order}/authorization', [ChangeOrderController::class, 'authorization']);

            // Material requests: reads open to any project member.
            Route::get('projects/{project}/material-requests', [MaterialRequestController::class, 'index']);
            Route::get('projects/{project}/material-requests/{material_request}', [MaterialRequestController::class, 'show']);

            // The material-request document. Same gate as show() on purpose —
            // if you can view the request you can print it, in any status.
            // Rendered live rather than stored: a request keeps changing through
            // the approval chain, so a filed copy would go stale. ?download=1
            // forces save-as rather than inline display.
            Route::get('projects/{project}/material-requests/{material_request}/pdf', [MaterialRequestController::class, 'pdf']);

            // Issues: reads open to any project member.
            Route::get('projects/{project}/issues', [IssueController::class, 'index']);
            Route::get('projects/{project}/issues/{issue}', [IssueController::class, 'show']);
        });

        // ---- Projects: management writes — gated by capability, evaluated
        // under the global scope this whole group already runs under (same
        // scope the old role:Admin gate used, so this is a drop-in replacement,
        // not a new scoping concern).
        Route::middleware('permission:create_project')->group(function () {
            Route::post('projects', [ProjectController::class, 'store']);
        });

        Route::middleware('permission:edit_project')->group(function () {
            Route::patch('projects/{project}', [ProjectController::class, 'update']);
            Route::patch('projects/{project}/status', [ProjectController::class, 'updateStatus']);
        });

        Route::middleware('permission:delete_project')->group(function () {
            Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
        });

        // ---- Project delivery addresses: the ship-to sites a purchase order can
        // be delivered to. A project may run several at once, which is why this
        // is a table rather than the single projects.site_address text column.
        //
        // The read is NOT in the `project.access` group above, on purpose: PO
        // routes are deliberately not membership-scoped, and a Procurement user
        // is often not staffed onto the project they're buying for — gating on
        // membership alone would 403 the buyer on the only endpoint that feeds
        // the ship-to dropdown. The controller does the finer
        // "member OR manage_purchase_orders" check instead.
        Route::middleware('permission:view_project|manage_purchase_orders')->group(function () {
            Route::get('projects/{project}/delivery-addresses', [ProjectDeliveryAddressController::class, 'index']);
        });

        // Writes are project master data, so they ride on edit_project (Admin +
        // PM) — the same capability that already governs PATCH /projects/{project},
        // and likewise not stacked with project.access, for consistency with it.
        Route::middleware('permission:edit_project')->group(function () {
            Route::post('projects/{project}/delivery-addresses', [ProjectDeliveryAddressController::class, 'store']);
            Route::patch('projects/{project}/delivery-addresses/{delivery_address}', [ProjectDeliveryAddressController::class, 'update']);
            Route::delete('projects/{project}/delivery-addresses/{delivery_address}', [ProjectDeliveryAddressController::class, 'destroy']);
        });

        // Milestones (writes) — gated by the manage_milestones capability, evaluated
        // under the global scope this whole outer group runs under (same scope the
        // old role:Admin gate used, so this is a drop-in replacement).
        Route::middleware('permission:manage_milestones')->group(function () {
            Route::post('projects/{project}/milestones', [MilestoneController::class, 'store']);
            Route::patch('projects/{project}/milestones/{milestone}', [MilestoneController::class, 'update']);
            Route::delete('projects/{project}/milestones/{milestone}', [MilestoneController::class, 'destroy']);
        });

        // ---- Catalog type-ahead for material-request lines. One search field
        // replaces the catalog + trade-category dropdowns; the server derives
        // trade_category_id (and unit_id) from the picked item on save.
        //
        // NOT under view_pricing, unlike the catalog reads above: the roles that
        // file material requests (Foreman, Site Engineer, Project Coordinator)
        // don't hold it and would get a 403 on the only catalog endpoint that
        // exists. This returns the price-free CatalogItemSearchResource instead,
        // so the view_pricing gate on /catalog-items stays exactly as it is.
        // Project-nested because results are scoped to the global catalog plus
        // THIS project's custom items.
        Route::middleware('permission:create_material_request|view_pricing')->group(function () {
            Route::get('projects/{project}/catalog-items/search', [CatalogItemController::class, 'search']);
        });

        // ---- Material Requests: authoring (creator/PM) — create the request,
        // edit its lines while still editable, and submit it into the chain.
        // Fine-grained ownership + editable-status rules live in the service.
        Route::middleware('permission:create_material_request')->group(function () {
            Route::post('projects/{project}/material-requests', [MaterialRequestController::class, 'store']);
            Route::patch('projects/{project}/material-requests/{material_request}', [MaterialRequestController::class, 'update']);
            Route::delete('projects/{project}/material-requests/{material_request}', [MaterialRequestController::class, 'destroy']);

            Route::post('projects/{project}/material-requests/{material_request}/items', [MaterialRequestController::class, 'storeItem']);
            Route::patch('projects/{project}/material-requests/{material_request}/items/{item}', [MaterialRequestController::class, 'updateItem']);
            Route::delete('projects/{project}/material-requests/{material_request}/items/{item}', [MaterialRequestController::class, 'destroyItem']);

            Route::post('projects/{project}/material-requests/{material_request}/submit', [MaterialRequestController::class, 'submit']);
        });

        // ---- Material Requests: approval chain (PM then Admin). The coarse gate
        // is approve_material_request; the two-level PM-vs-Admin distinction is
        // enforced in MaterialRequestService by the current status.
        Route::middleware('permission:approve_material_request')->group(function () {
            Route::post('projects/{project}/material-requests/{material_request}/approve', [MaterialRequestController::class, 'approve']);
            Route::post('projects/{project}/material-requests/{material_request}/send-back', [MaterialRequestController::class, 'sendBack']);
            Route::post('projects/{project}/material-requests/{material_request}/reject', [MaterialRequestController::class, 'reject']);
        });

        // ---- Material Requests: structuring sign-off — "the request text is fully
        // mapped". Not a transition. `|` because two groups sign off: reviewers
        // before approval (approve_material_request) and whoever cuts the PO after
        // it (manage_purchase_orders). Which role may act at which status is
        // enforced in MaterialRequestService::markStructured().
        Route::middleware('permission:approve_material_request|manage_purchase_orders')->group(function () {
            Route::post('projects/{project}/material-requests/{material_request}/mark-structured', [MaterialRequestController::class, 'markStructured']);
        });

        // ---- Material Requests: PM final approval, bypassing the Admin step.
        // A SEPARATE endpoint rather than a branch inside /approve on purpose:
        // a PM holding this right must still be able to escalate to Admin when
        // a request warrants it, so the choice is made per request by calling
        // one endpoint or the other. /approve is therefore untouched.
        //
        // The permission is granted per USER (PATCH /users/{user} with
        // permissions[]), not via a role — Admin holds it through the '*'
        // wildcard. Note it resolves at GLOBAL Spatie scope like every check in
        // this group, so it applies across all projects, not per project.
        Route::middleware('permission:finalize_material_request')->group(function () {
            Route::post('projects/{project}/material-requests/{material_request}/finalize', [MaterialRequestController::class, 'finalize']);
        });

        // ---- Purchase Orders: procurement-desk entity (Admin/Procurement/PM),
        // created from an approved material request. Top-level (spans projects);
        // index filterable by project_id/vendor_id/status.
        Route::middleware('permission:manage_purchase_orders')->group(function () {
            Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);

            // The buyer's queue of approved requests still awaiting a PO, across
            // all projects — including the requester's free text and photos, so a
            // prose-only request can be mapped to catalog lines here. Registered
            // BEFORE the {purchase_order} wildcard, or "pending-requests" would be
            // bound as a model key.
            Route::get('purchase-orders/pending-requests', [PurchaseOrderController::class, 'pendingRequests']);

            Route::get('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show']);

            // The PO document. Serves the copy stored at issue when one exists,
            // otherwise renders live (drafts print watermarked). ?download=1
            // forces save-as rather than inline display.
            Route::get('purchase-orders/{purchase_order}/pdf', [PurchaseOrderController::class, 'pdf']);

            // Catalog type-ahead for purchase-order lines — the buyer's
            // equivalent of the material-request picker, reusing the same
            // CatalogItemService::search() (project scoping, trigram indexes,
            // prefix-first ordering, has_more). It differs only in carrying the
            // chosen vendor's current rate, so a buyer can see the price before
            // adding a line instead of discovering a missing rate as a 422 on
            // submit.
            //
            // Gated on BOTH permissions, not `|`: this endpoint exposes pricing.
            // Every role holding manage_purchase_orders also holds view_pricing
            // today, so the second gate is inert now — it exists so a later
            // grant to a non-pricing role cannot leak vendor rates.
            //
            // Three segments, so unlike `pending-requests` above it can never be
            // captured by the {purchase_order} wildcard.
            Route::middleware(['permission:view_pricing'])->group(function () {
                Route::get('purchase-orders/catalog-items/search', [CatalogItemController::class, 'searchForPurchaseOrder']);
            });
            Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
            Route::patch('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'update']);
            Route::delete('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'destroy']);
            // Line items of a draft order — the same add/update/remove shape the
            // material-request items use. Nested under the {purchase_order}
            // wildcard, so the literal `pending-requests` and
            // `catalog-items/search` routes registered above are unaffected.
            Route::post('purchase-orders/{purchase_order}/items', [PurchaseOrderController::class, 'storeItem']);
            Route::patch('purchase-orders/{purchase_order}/items/{item}', [PurchaseOrderController::class, 'updateItem']);
            Route::delete('purchase-orders/{purchase_order}/items/{item}', [PurchaseOrderController::class, 'destroyItem']);

            // Supporting files appended to the PDF the vendor receives. Allowed
            // at any status except cancelled — a rate screenshot often arrives
            // after the order has gone out.
            Route::post('purchase-orders/{purchase_order}/attachments', [PurchaseOrderController::class, 'storeAttachments']);
            Route::delete('purchase-orders/{purchase_order}/attachments/{attachment}', [PurchaseOrderController::class, 'destroyAttachment']);

            Route::post('purchase-orders/{purchase_order}/issue', [PurchaseOrderController::class, 'issue']);
            Route::post('purchase-orders/{purchase_order}/send', [PurchaseOrderController::class, 'send']);
            Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel']);
        });

        // ---- Purchase Order Terms & Conditions: the semi-contractual text
        // printed at the foot of a PO. ONE entity covers both scopes — a row
        // with project_id = null is the company default, a row with one set is
        // that project's override — so this is a single top-level CRUD rather
        // than a global setting plus a per-project table.
        //
        // Admin-only (manage_lookups): terms bind the company contractually,
        // which is a narrower question than "who may edit this project", so
        // this deliberately does NOT ride on edit_project the way the delivery
        // addresses above do.
        Route::middleware('permission:manage_lookups')->group(function () {
            Route::get('purchase-order-terms', [PurchaseOrderTermsController::class, 'index']);
            Route::get('purchase-order-terms/{purchase_order_term}', [PurchaseOrderTermsController::class, 'show']);
            Route::post('purchase-order-terms', [PurchaseOrderTermsController::class, 'store']);
            Route::patch('purchase-order-terms/{purchase_order_term}', [PurchaseOrderTermsController::class, 'update']);
            Route::delete('purchase-order-terms/{purchase_order_term}', [PurchaseOrderTermsController::class, 'destroy']);
        });

        // Which terms a PO cut against this project would carry. Open to the
        // procurement desk as well as Admin — the buyer needs to see the terms
        // before cutting the order, not only after. Read-only.
        Route::middleware('permission:manage_purchase_orders|manage_lookups')->group(function () {
            Route::get('projects/{project}/purchase-order-terms', [PurchaseOrderTermsController::class, 'effective']);
        });

        // ---- Deliveries: receipt against a PO, recorded by field/warehouse
        // staff (receive_deliveries). Recording cascades PO + MR fulfillment
        // status automatically. Discrepancies are flagged per delivery.
        Route::middleware('permission:receive_deliveries')->group(function () {
            Route::get('deliveries', [DeliveryController::class, 'index']);
            Route::get('deliveries/{delivery}', [DeliveryController::class, 'show']);
            Route::post('purchase-orders/{purchase_order}/deliveries', [DeliveryController::class, 'store']);
            Route::post('deliveries/{delivery}/discrepancies', [DeliveryController::class, 'storeDiscrepancy']);
        });

        // ---- Issues: field-to-office tracking, project-scoped. Any manage_issues
        // holder can raise/update; resolve is further restricted to Admin/PM
        // inside IssueService.
        Route::middleware('permission:manage_issues')->group(function () {
            Route::post('projects/{project}/issues', [IssueController::class, 'store']);
            Route::patch('projects/{project}/issues/{issue}', [IssueController::class, 'update']);
            Route::post('projects/{project}/issues/{issue}/resolve', [IssueController::class, 'resolve']);
        });

        // ---- Daily Logs: the Foreman's Daily Scope of Work, project-scoped.
        // Unlike the other project-scoped reads (membership-only), viewing is
        // ALSO permission-gated (view_daily_log). Submitting requires active
        // project membership (project.access) AND submit_daily_log — "only an
        // assigned person can add a daily log." logged_by is always the caller.
        Route::middleware(['project.access', 'permission:view_daily_log'])->group(function () {
            Route::get('projects/{project}/daily-logs', [DailyLogController::class, 'index']);
            Route::get('projects/{project}/daily-logs/{daily_log}', [DailyLogController::class, 'show']);
        });

        Route::middleware(['project.access', 'permission:submit_daily_log'])->group(function () {
            Route::post('projects/{project}/daily-logs', [DailyLogController::class, 'store']);
            Route::patch('projects/{project}/daily-logs/{daily_log}', [DailyLogController::class, 'update']);
            Route::delete('projects/{project}/daily-logs/{daily_log}', [DailyLogController::class, 'destroy']);
        });

        // ---- Change Order Terms: the standing Payment Terms / Changes /
        // Acceptance text printed at the foot of a change-order document. Same
        // one-table-two-scopes shape as purchase-order terms — a row with
        // project_id = null is the company default, a row with one set is that
        // project's override.
        //
        // Admin-only (manage_lookups), matching purchase-order terms and for the
        // same reason: this text binds the company contractually, which is a
        // narrower question than "who may edit this project", so it deliberately
        // does NOT ride on edit_project the way the GC records below do.
        Route::middleware('permission:manage_lookups')->group(function () {
            Route::get('change-order-terms', [ChangeOrderTermsController::class, 'index']);
            Route::get('change-order-terms/{change_order_term}', [ChangeOrderTermsController::class, 'show']);
            Route::post('change-order-terms', [ChangeOrderTermsController::class, 'store']);
            Route::patch('change-order-terms/{change_order_term}', [ChangeOrderTermsController::class, 'update']);
            Route::delete('change-order-terms/{change_order_term}', [ChangeOrderTermsController::class, 'destroy']);
        });

        // Which terms a change order raised against this project would carry.
        // Open to the PM who sends the document, not only to Admin — they
        // need to see the wording before generating, not after. Read-only.
        Route::middleware('permission:manage_lookups|approve_change_request')->group(function () {
            Route::get('projects/{project}/change-order-terms', [ChangeOrderTermsController::class, 'effective']);
        });

        // ---- Project General Contractors: the GC(s) a project runs under, and
        // the dropdown a change order picks from. Distinct from the project's
        // client (an owner commissioning the project) — Ponos contracts UNDER a
        // GC as a subcontractor, and the change-order document is addressed to
        // them.
        //
        // Both groups stack `project.access`, unlike the delivery-address routes
        // which deliberately omit it for Procurement's sake. These endpoints
        // serve the change-order module, which IS membership-scoped, so they
        // follow it rather than the purchase-order module.
        //
        // Reads: any project member — anyone who can raise a change order needs
        // to see the list. Writes: `edit_project` (Admin + PM), the same
        // capability governing every other piece of project master data.
        Route::middleware('project.access')->group(function () {
            Route::get('projects/{project}/general-contractors', [ProjectGeneralContractorController::class, 'index']);

            // A GC's on-site representatives — the dropdown on the emergency work
            // authorization step. Readable by any member, like the GC list.
            Route::get('projects/{project}/general-contractors/{general_contractor}/representatives', [GcRepresentativeController::class, 'index']);
            Route::get('projects/{project}/general-contractors/{general_contractor}/representatives/{representative}', [GcRepresentativeController::class, 'show']);
        });

        Route::middleware(['project.access', 'permission:edit_project'])->group(function () {
            Route::post('projects/{project}/general-contractors', [ProjectGeneralContractorController::class, 'store']);
            Route::patch('projects/{project}/general-contractors/{general_contractor}', [ProjectGeneralContractorController::class, 'update']);
            Route::delete('projects/{project}/general-contractors/{general_contractor}', [ProjectGeneralContractorController::class, 'destroy']);

            Route::post('projects/{project}/general-contractors/{general_contractor}/representatives', [GcRepresentativeController::class, 'store']);
            Route::patch('projects/{project}/general-contractors/{general_contractor}/representatives/{representative}', [GcRepresentativeController::class, 'update']);
            Route::delete('projects/{project}/general-contractors/{general_contractor}/representatives/{representative}', [GcRepresentativeController::class, 'destroy']);
        });

        // ---- Change Orders: authoring — Foreman/PM generate a query, edit while
        // still editable, submit into the chain, or record an emergency (on-site
        // GC signature) CO. Ownership + editable-status rules live in the service.
        //
        // `project.access` pairs with the permission gate here exactly as it does
        // on the daily-log writes above: holding create_change_request says you
        // may raise change orders, not that you may raise one against a project
        // you are not staffed onto. The CO READ routes have always been
        // membership-checked; the writes were not, which let any holder act on
        // any project in the system.
        Route::middleware(['project.access', 'permission:create_change_request'])->group(function () {
            Route::post('projects/{project}/change-orders', [ChangeOrderController::class, 'store']);
            Route::post('projects/{project}/change-orders/emergency', [ChangeOrderController::class, 'storeEmergency']);
            Route::patch('projects/{project}/change-orders/{change_order}', [ChangeOrderController::class, 'update']);
            Route::post('projects/{project}/change-orders/{change_order}/submit', [ChangeOrderController::class, 'submit']);
        });

        // ---- Change Orders: the authorization chain. Coarse gate is
        // approve_change_request; the PM-then-Admin two-level distinction (validate
        // = PM, approve = Admin, send-to-gc = PM or Admin) is enforced in
        // ChangeOrderService by the current status. gc-decision
        // records the GC's out-of-band response. `project.access` for the same
        // reason as the authoring group above.
        Route::middleware(['project.access', 'permission:approve_change_request'])->group(function () {
            Route::post('projects/{project}/change-orders/{change_order}/validate', [ChangeOrderController::class, 'validateCo']);
            Route::post('projects/{project}/change-orders/{change_order}/approve', [ChangeOrderController::class, 'approve']);
            Route::post('projects/{project}/change-orders/{change_order}/gc-decision', [ChangeOrderController::class, 'setGcDecision']);
            Route::post('projects/{project}/change-orders/{change_order}/send-back', [ChangeOrderController::class, 'sendBack']);
            Route::post('projects/{project}/change-orders/{change_order}/reject', [ChangeOrderController::class, 'reject']);
            Route::post('projects/{project}/change-orders/{change_order}/cancel', [ChangeOrderController::class, 'cancel']);

            // ---- The emergency flow's own steps.
            //
            // Separate endpoints rather than branches inside validate/approve/
            // send-back, because each of those Normal-flow methods hardcodes its
            // destination: approve() always lands at `approved`, and
            // send-back() lands at sent_back, which submit() then accepts and routes
            // BY ORIGINATOR — so a foreman-raised emergency change order sent back
            // would re-enter at pending_pm, a status with no pricing precondition, on
            // work already built.
            //
            // Pricing itself is NOT here: it goes through the ordinary PATCH, gated by
            // assertEditable()'s work_authorized arm. What is gated here are the
            // transitions.
            Route::post('projects/{project}/change-orders/{change_order}/forward', [ChangeOrderController::class, 'forwardToAdmin']);
            Route::post('projects/{project}/change-orders/{change_order}/send-back-to-pricing', [ChangeOrderController::class, 'sendBackToPricing']);
            Route::post('projects/{project}/change-orders/{change_order}/void-authorization', [ChangeOrderController::class, 'voidAuthorization']);

            // ONE endpoint for every route to the GC — Normal (from `approved`, PM
            // or Admin) and both emergency routes. The emergency bypass permission
            // (send_change_request_to_gc) is checked IN THE SERVICE rather than here,
            // because which gate applies depends on the status it is called from —
            // the same division PurchaseOrderService::assertMayRaiseStandalone() uses
            // for one endpoint serving two routes.
            Route::post('projects/{project}/change-orders/{change_order}/send-to-gc', [ChangeOrderController::class, 'sendToGc']);
        });

        // ---- The Admin oversight queue: change orders that reached a General
        // Contractor, and which route they took to get there.
        //
        // Top-level, not project-nested, because the question spans projects — the
        // same shape as purchase-orders/pending-requests. Scoping is applied in the
        // service: an Admin sees every project, a PM only the ones they are staffed
        // on, so no project.access middleware applies (there is no single project to
        // check).
        Route::middleware('permission:approve_change_request')->group(function () {
            Route::get('change-orders/gc-queue', [ChangeOrderController::class, 'gcQueue']);
        });

        // ---- RFQs (Request for Quotation): pre-project — a list of items sent
        // to one vendor to get rates for a bid, before any project is won.
        // Top-level, like purchase-orders, not project-nested: nesting under a
        // project would contradict the whole point of the module. No
        // project.access stacking for the same reason.
        //
        // Single actor class (Admin/PM, both behind manage_rfqs) authors AND
        // sends an RFQ — no approval chain, so reads and writes share one gate.
        Route::middleware('permission:manage_rfqs')->group(function () {
            Route::get('rfqs', [RfqController::class, 'index']);
            Route::get('rfqs/{rfq}', [RfqController::class, 'show']);

            // The RFQ document. Serves the filed copy once sent, otherwise
            // renders live (drafts print watermarked). ?download=1 forces
            // save-as rather than inline display.
            Route::get('rfqs/{rfq}/pdf', [RfqController::class, 'pdf']);

            // Catalog type-ahead for RFQ lines, scoped to this RFQ's own
            // (nullable) project and target vendor. Gated on BOTH permissions,
            // not `|`: this endpoint carries pricing context (has_rate/
            // current_rate). Every role holding manage_rfqs today also holds
            // view_pricing, so the second gate is currently inert — it exists
            // so a later grant of manage_rfqs to a non-pricing role cannot leak
            // vendor rates, same reasoning as the purchase-order picker.
            Route::middleware('permission:view_pricing')->group(function () {
                Route::get('rfqs/{rfq}/catalog-items/search', [RfqController::class, 'searchCatalogItems']);
            });

            Route::post('rfqs', [RfqController::class, 'store']);
            Route::patch('rfqs/{rfq}', [RfqController::class, 'update']);
            Route::delete('rfqs/{rfq}', [RfqController::class, 'destroy']);

            Route::post('rfqs/{rfq}/items', [RfqController::class, 'storeItem']);
            Route::patch('rfqs/{rfq}/items/{item}', [RfqController::class, 'updateItem']);
            Route::delete('rfqs/{rfq}/items/{item}', [RfqController::class, 'destroyItem']);

            // Files the PDF and emails it to the vendor — the system sends it
            // directly, no mailto / manual draft step for the user.
            Route::post('rfqs/{rfq}/submit', [RfqController::class, 'submit']);
        });

        // ---- Attachment download: authenticated + project-access-checked stream
        // of a stored file (e.g. a captured emergency-CO signature image).
        Route::get('attachments/{attachment}', [AttachmentController::class, 'download']);
    });
});
