<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogItemController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\DeliveryController;
use App\Http\Controllers\Api\V1\IssueController;
use App\Http\Controllers\Api\V1\CostCodeController;
use App\Http\Controllers\Api\V1\Lookup\CatalogItemTypeLookupController;
use App\Http\Controllers\Api\V1\Lookup\DiscrepancyTypeLookupController;
use App\Http\Controllers\Api\V1\Lookup\GenderLookupController;
use App\Http\Controllers\Api\V1\Lookup\IssueStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\MaterialRequestStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\MilestonePhaseLookupController;
use App\Http\Controllers\Api\V1\Lookup\MilestoneStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\ProjectStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\ProjectTypeLookupController;
use App\Http\Controllers\Api\V1\Lookup\PurchaseOrderStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\UnitLookupController;
use App\Http\Controllers\Api\V1\Lookup\UrgencyLookupController;
use App\Http\Controllers\Api\V1\Lookup\UserStatusLookupController;
use App\Http\Controllers\Api\V1\MaterialRequestController;
use App\Http\Controllers\Api\V1\MilestoneController;
use App\Http\Controllers\Api\V1\ProjectAssignmentController;
use App\Http\Controllers\Api\V1\ProjectChangeOrderController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\RbacController;
use App\Http\Controllers\Api\V1\TradeCategoryController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserRoleController;
use App\Http\Controllers\Api\V1\VendorController;
use App\Http\Controllers\Api\V1\VendorRateController;
use Illuminate\Support\Facades\Route;

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

        // ---- RBAC administration (Admin / PM) — intentionally role-gated, not
        // permission-gated: these actions define/grant permissions themselves
        // (roles catalog, global role assignment, project staffing), so gating
        // them by permission would let a holder grant themselves broader access —
        // a privilege-escalation path. No permission in the seeded catalog maps
        // to "administer RBAC" for this reason; identity-based gating stays here
        // by design.

        Route::middleware('role:Admin|Project Manager')->group(function () {
            Route::post('permissions', [RbacController::class, 'storePermission']);
            Route::post('roles', [RbacController::class, 'storeRole']);
            Route::put('roles/{role}/permissions', [RbacController::class, 'updateRolePermissions']);

            Route::post('users/{user}/assign-role', [UserRoleController::class, 'assign']);
            Route::delete('users/{user}/roles/{role}', [UserRoleController::class, 'revoke']);

            // Project staffing (writes project_user + model_has_roles)
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
            Route::patch('trade-categories/{trade_category}', [TradeCategoryController::class, 'update']);
            Route::delete('trade-categories/{trade_category}', [TradeCategoryController::class, 'destroy']);

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
            Route::get('projects/{project}/change-orders', [ProjectChangeOrderController::class, 'index']);

            // Material requests: reads open to any project member.
            Route::get('projects/{project}/material-requests', [MaterialRequestController::class, 'index']);
            Route::get('projects/{project}/material-requests/{material_request}', [MaterialRequestController::class, 'show']);

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

        // Milestones (writes) — gated by the manage_milestones capability, evaluated
        // under the global scope this whole outer group runs under (same scope the
        // old role:Admin gate used, so this is a drop-in replacement).
        Route::middleware('permission:manage_milestones')->group(function () {
            Route::post('projects/{project}/milestones', [MilestoneController::class, 'store']);
            Route::patch('projects/{project}/milestones/{milestone}', [MilestoneController::class, 'update']);
            Route::delete('projects/{project}/milestones/{milestone}', [MilestoneController::class, 'destroy']);
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

        // ---- Purchase Orders: procurement-desk entity (Admin/Procurement/PM),
        // created from an approved material request. Top-level (spans projects);
        // index filterable by project_id/vendor_id/status.
        Route::middleware('permission:manage_purchase_orders')->group(function () {
            Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
            Route::get('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show']);
            Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
            Route::patch('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'update']);
            Route::delete('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'destroy']);
            Route::post('purchase-orders/{purchase_order}/issue', [PurchaseOrderController::class, 'issue']);
            Route::post('purchase-orders/{purchase_order}/send', [PurchaseOrderController::class, 'send']);
            Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel']);
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
    });
});
