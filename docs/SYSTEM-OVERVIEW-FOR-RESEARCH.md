# Ponos Home Improvement — Construction Management API
## Complete System Specification (as-built)

> **Purpose of this document.** This is a self-contained description of a construction-management backend that is currently built and running. It is written to be pasted into a fresh chat as context for a research conversation about **industry best practices for Material Requests, Purchase Orders, and Change Orders** in residential/light-commercial construction. Everything below describes what the system *actually does today*, not what is planned. A final section lists known gaps and the specific questions to research.
>
> Nothing here requires access to the codebase — all field names, statuses, rules, and role grants are reproduced inline.

---

## 0. Context in one paragraph

Ponos Home Improvement is a home-improvement / renovation general contractor. This system is the back office + field operations API for their projects: it tracks clients and projects, staffs people onto projects with roles, tracks construction milestones, maintains a vendor price catalog, and runs four field-to-office workflows — **Material Requests** (field asks for materials), **Purchase Orders** (office buys them), **Deliveries** (field receives them), and **Change Orders** (scope changes that need General Contractor / client authorization). Daily Logs and Issues capture day-to-day site reporting.

The company operates **as a subcontractor or trade contractor beneath a General Contractor (GC)** on at least some projects — this matters a lot, because the Change Order flow ends with an *external* GC approving or rejecting the change. The GC is not a user of this system; their decision arrives by email and is recorded by staff.

---

## 1. Technical shape (brief — skip if researching process, not software)

- **Laravel 12 REST API**, PostgreSQL, JWT auth. No frontend in this repo; all endpoints are `/api/v1/...`.
- **Layering:** `routes` → `Controller` (thin) → `FormRequest` (validation) → `Service` (business logic + DB transactions) → `Model`. Business rules live in Services, not Controllers.
- **Everything is soft-deleted.** No hard deletes anywhere. Money is `decimal(-,2)`; math on money uses arbitrary-precision `bcmul`/`bcadd`, not floats.
- **Lookup tables** (statuses, types, units, urgencies…) are real DB tables with `code` + `label` + `sort_order` + `is_terminal`, each with full admin CRUD. Statuses are therefore *data*, not enums in code — but the workflow services still branch on the known `code` strings, so adding a new status row does not automatically create a new transition.
- **Audit trail:** every workflow transition writes a row to an `*_approvals` table (`step_no`, `actor`, `action`, `comments`, `from_status_id`, `to_status_id`). Prices, once written onto a document line, are snapshotted columns — later price changes never rewrite history.
- **Document numbers** (`MR-…`, `PO-…`, `CO-…`) are minted from an atomic row-locked counter table (`document_sequences`).

---

## 2. Roles and permissions (the whole authorization model)

### 2.1 The 22 seeded permissions

```
view_project            create_project           edit_project            delete_project
manage_milestones       manage_lookups           manage_clients
view_pricing            edit_pricing
create_material_request approve_material_request manage_purchase_orders  receive_deliveries
manage_issues
create_change_request   approve_change_request
submit_daily_log        view_daily_log
view_inventory          update_inventory         view_reports            manage_users
```

### 2.2 The 7 roles and what each holds

| Permission | Admin | Project Manager | Assistant PM | Project Coordinator | Site Engineer | Foreman | Procurement |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| view_project | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| create/edit/delete_project | ✅ | edit only | — | — | — | — | — |
| manage_milestones | ✅ | ✅ | ✅ | ✅ | — | — | — |
| manage_lookups | ✅ | — | — | — | — | — | — |
| manage_clients | ✅ | — | — | — | — | — | — |
| view_pricing | ✅ | ✅ | ✅ | — | — | — | ✅ |
| edit_pricing | ✅ | ✅ | — | — | — | — | ✅ |
| create_material_request | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| approve_material_request | ✅ | ✅ | ✅ | — | — | — | ✅ |
| manage_purchase_orders | ✅ | ✅ | — | — | — | — | ✅ |
| receive_deliveries | ✅ | ✅ | ✅ | — | ✅ | ✅ | ✅ |
| manage_issues | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| create_change_request | ✅ | ✅ | ✅ | — | ✅ | ✅ | — |
| approve_change_request | ✅ | ✅ | — | — | — | — | — |
| submit_daily_log / view_daily_log | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| view_inventory | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| update_inventory | ✅ | ✅ | — | — | — | — | ✅ |
| view_reports | ✅ | ✅ | ✅ | ✅ | — | — | ✅ |
| manage_users | ✅ | — | — | — | — | — | — |

`Admin` holds every permission via a wildcard.

### 2.3 Two-layer authorization — important for understanding the workflows

Authorization is enforced at **two** layers, and the distinction shapes every approval chain:

1. **Route middleware = the coarse capability gate.** e.g. every Material Request approval endpoint is behind `approve_material_request`. Both PM and Procurement hold it.
2. **In-service step gate = who may act *at this particular status*.** The service re-checks the actor's role against the current status. So `approve_material_request` lets you *reach* the endpoint, but at status `pending_pm` the service demands a PM-level role, and at `pending_admin` it demands Admin. Anyone else gets `403`.

This is why the approval chains are described below as "two-level" — one permission, two sequential role-gated steps.

Separately, **project membership** (`project.access`) is checked independently of permissions: a user must be actively assigned to a project to read its data. Reads of project-scoped data are membership-only (no extra permission) for milestones / material requests / issues / change orders — with **Daily Logs as the deliberate exception**, where reading also requires `view_daily_log`.

**Project staffing:** a user can hold multiple roles on one project simultaneously; the system enforces **exactly one active Project Manager per project** (409 if occupied). RBAC administration endpoints are gated by *role* (`Admin|Project Manager`) rather than by permission, deliberately — gating "who can grant permissions" by a permission would be a privilege-escalation path.

---

## 3. Foundation modules (context for the four workflow modules)

### 3.1 Clients & Projects
- **Client** — name, contact_name, email, phone, address. Read by any authenticated user; writes Admin-only. Cannot be deleted while it has projects.
- **Project** — `code`, `name`, `client_id`, `project_type_id`, `project_status_id`, `site_address`, **`budget`**, `start_date`, `end_date`. Non-admins only ever see projects they are assigned to.
- Note: `budget` is a stored number with **no committed-cost / spent-to-date roll-up anywhere in the system**. Nothing compares PO totals or CO values against it. (Flagged in §11.)

### 3.2 Milestones
Full construction-tracking shape: `code` (client-facing business ID, unique per project), `phase_id` (Pre-Construction / Design / Procurement / Construction / Closeout), `status_id` (Not Started / In Progress / Completed / Delayed / On Hold / Cancelled), `sequence`, `planned_date`, `actual_date`, `predecessor_id` (single self-referencing dependency with app-level cycle detection), `responsible_user_id` **plus** `responsible_party_label` (hybrid: an internal user FK *or* free text for external parties like "Owner / AHJ"), `deliverable`, `is_payment_milestone`, `payment_amount`.

Completion is driven by the status row's `is_terminal` + code, not by date presence. A milestone that other milestones depend on cannot be deleted.

**Note:** payment milestones exist as data but are **not connected to any billing, invoicing, or draw-request flow** — nothing consumes `is_payment_milestone` / `payment_amount`.

### 3.3 Attachments (minimal slice only)
A polymorphic `attachments` table exists (`attachable_type`/`attachable_id`, `project_id`, `attachment_type`, disk, path, mime, size, `captured_at`, `uploaded_by`). Only **one** ingest path is built: `storeBase64Image()` — decodes a base64/data-URI PNG or JPEG, 5 MB cap, writes to a **private** disk, creates the row. Built specifically to store emergency change-order signatures. Download is a single authenticated, project-access-checked streaming endpoint.

There is **no general file upload** (no multipart, no photo attachment on daily logs / deliveries / issues / change orders, no document galleries).

---

## 4. Module 1 — Pricing Core (Vendors, Catalog Items, Vendor Rates)

The foundation the purchasing modules read from. No workflow or status machine — three CRUD resources plus one append-only ledger. Reads gated by `view_pricing`, writes by `edit_pricing` (i.e. commercially sensitive: PM, Procurement, Admin can edit; Assistant PM read-only; field roles see none of it).

### 4.1 Entities

**Vendor** — `name` (required), `contact_name`, `email`, `phone`, `address`, `is_active`, `notes`, `created_by`.

**CatalogItem** — the "thing that can be bought":
- `trade_category_id`, `catalog_item_type_id`, `default_unit_id` — **all required**
- `catalog_item_type` is one of: `material`, `labor`, `subcontractor`
- `project_id` — optional; scopes a custom item to a single project
- `sku` — optional, unique among non-deleted items
- `name` (required), `is_custom` flag, `attributes` (freeform JSON)

**VendorRate** — a priced link between one vendor and one catalog item: `rate`, `currency`, `effective_from`, `effective_to`, `source`, `notes`, `entered_by`, `superseded_by_id`.

### 4.2 The rate ledger — the key design decision

`VendorRate` is an **append-only ledger, not a mutable price field**. There is no update or delete route for it at all — only create.

`addRate()` runs in a row-locked transaction:
1. If an open rate already exists for that `(vendor, catalog_item)` pair, it **closes** it — sets its `effective_to` to the day before the new rate's `effective_from` — and links `superseded_by_id` to the new row.
2. Inserts the new rate with `effective_to = NULL` (open / current).

A **partial unique DB index** guarantees only one row per `(vendor_id, catalog_item_id)` can ever have `effective_to IS NULL`. There is no window in which two "current" rates coexist.

Reading: the rate list defaults to `current_only=true`; pass `current_only=false` to see full price history.

### 4.3 Validation & delete guards
- Vendor: `name` required, `email` must be RFC-valid, everything else optional.
- CatalogItem: the three FKs required and must exist (soft-delete aware); `sku` unique among non-deleted.
- VendorRate: `rate` numeric ≥ 0; the new `effective_from` must be **strictly after** the current open rate's `effective_from` (422 otherwise — prevents back-dating a rate behind the one it supersedes).
- A Vendor cannot be deleted if it has any rate history.
- A CatalogItem cannot be deleted if it has rate history, or is referenced by an estimate line, MR item, or PO item.

---

## 5. Module 2 — Material Requests (MR)

The field's request for materials. **A material request contains no prices at all** — it is "what and how much," never "what it costs."

### 5.1 Data

**Header:** `request_no` (auto, prefix `MR`), `project_id`, `requested_by`, `material_request_status_id`, `urgency_id`, `needed_by_date`, `notes`, `created_by`.

**Line items (`MaterialRequestItem`):** `cost_code_id` (**required**), `catalog_item_id` (*optional*), `trade_category_id` (optional), `unit_id` (required), `description` (free text), `quantity` (> 0), `notes`, `sort_order`. **No price field of any kind.**

**`MaterialRequestApproval`:** one row per transition — `step_no`, actor, `action`, `comments`, `from_status_id`, `to_status_id`.

**Urgency lookup:** `low` / `normal` / `high` / `critical`.

### 5.2 Status machine

```
                                    ┌──── send_back ────┐
                                    ▼                   │
draft ──submit──> pending_pm ──approve──> pending_admin ──approve──> approved
  ▲                    │                       │                        │
  └─ sent_back_to_foreman                      └── send_back ──> sent_back_to_pm
                                                                     │ submit (by PM)
                                                                     └──> pending_admin

pending_pm | pending_admin ──reject──> rejected  (terminal)

approved ──(first PO created against it)──> ordered
ordered  ──(deliveries recorded)──> partially_delivered ──> delivered  (terminal)
```

Seeded statuses (11): `draft`, `pending_pm`, `sent_back_to_foreman`, `pending_admin`, `sent_back_to_pm`, `rejected`(terminal), `approved`, `ordered`, `partially_delivered`, `delivered`(terminal), `returned`(terminal).
*`returned` is seeded but no endpoint or service path ever sets it — there is no returns/RMA flow.*

### 5.3 Rules

- **Create** — anyone with `create_material_request` (Foreman, Site Engineer, Project Coordinator, Assistant PM, PM, Admin) opens a `draft`, optionally with `items[]` inline.
- **Edit lines** — only while status is `draft`, `sent_back_to_foreman`, or `sent_back_to_pm`, and only by the requester or a PM-level user.
- **Submit** — the requester submits `draft` / `sent_back_to_foreman` → `pending_pm`. A PM-level user re-submits `sent_back_to_pm` → `pending_admin`. **Blocked with 422 if the request has zero line items.**
- **Approve / send-back / reject** — two-level in-service gate: at `pending_pm` the actor must be PM-level (Admin, PM, Assistant PM); at `pending_admin` the actor must be Admin. Same gate applies to send-back and reject.
- **Delete** — only a `draft`, only by the requester or Admin.
- **Fulfilment cascade** — the first PO cut against an approved MR flips it to `ordered`. After each delivery receipt, the system walks all that MR's POs' delivery lines and advances it to `partially_delivered` / `delivered`. **It never regresses.**

### 5.4 Validation
- `urgency_id` required and must exist; `needed_by_date` nullable date.
- Each line: `cost_code_id` + `unit_id` required and must exist; `quantity` required, numeric, > 0.
- **DB CHECK constraint:** a line must have **either** `catalog_item_id` **or** `description` — mirrored in the FormRequest so it fails as a clean 422 rather than a DB error.

### 5.5 Relationship to the pricing catalog
**None required.** `catalog_item_id` is optional on an MR line; a Foreman can simply write free text ("2× 8ft pressure-treated 4x4"). Pricing enters only one step later, at the Purchase Order.

---

## 6. Module 3 — Purchase Orders (PO)

The procurement desk's buying document. Gated entirely by `manage_purchase_orders` (Admin, PM, Procurement). Unlike MRs, POs are **top-level, not project-nested** — the list spans projects and is filterable by `project_id` / `vendor_id` / `status`.

### 6.1 Data

**Header:** `po_number` (auto, prefix `PO`), **`material_request_id` (required)**, `project_id` (inherited from the MR), `vendor_id`, `total_amount`, `expected_delivery_date`, `purchase_order_status_id`, `notes`.

**Line items (`PurchaseOrderItem`):** `material_request_item_id` (optional link back to the requesting line), `cost_code_id`, **`catalog_item_id` (required — unlike MR)**, `vendor_rate_id` (nullable, see below), `unit_id`, `quantity_ordered`, `unit_price`, `line_total`.

### 6.2 Status machine

```
draft ──issue──> issued ──send──> sent ──(deliveries)──> partially_received ──> received (terminal)

draft | issued | sent ──cancel──> cancelled   (blocked once ANY delivery exists)
```

Seeded statuses (7): `draft`, `issued`, `sent`, `acknowledged`, `partially_received`, `received`(terminal), `cancelled`(terminal).
*`acknowledged` is seeded but unreachable — there is no vendor-acknowledgement endpoint or vendor-facing portal.*

### 6.3 Rules

- **Create requires an approved Material Request.** `material_request_id` is required, and the MR's status must be `approved` or `ordered` — enforced in the FormRequest so it returns a clean 422. **There is no way to raise a standalone PO, a blanket PO, or a service/subcontract PO without an MR behind it.**
- One PO targets exactly one `vendor_id`. Splitting an MR across vendors means cutting multiple POs against the same MR.
- **Pricing resolution, per line:**
  1. Look up the CatalogItem (404 if it doesn't exist — this is why `catalog_item_id` is mandatory here even though it's optional on the MR).
  2. If the caller supplies `unit_price` explicitly → that price is used and `vendor_rate_id` stays **null**, meaning "manual override, not sourced from the rate card."
  3. Otherwise look up the vendor's **current open rate** (`effective_to IS NULL`) for that `(vendor, item)` pair. If found, use it and record `vendor_rate_id` (traceability back to the exact rate row).
  4. If neither an override price nor a current rate exists → **422: "No current vendor rate for catalog item #X; provide a unit_price."**
  5. `line_total = quantity_ordered × unit_price`; header `total_amount` is the running sum, both at 2-decimal precision.
- Superseded rates are never used for new POs — they exist only as history.
- **Price snapshotting:** `unit_price` and `line_total` are stored columns. A later VendorRate change never retroactively alters an existing PO.
- **First PO** cut against an `approved` MR flips that MR to `ordered`.
- **Edit / delete** — only while `draft`. **Cancel** — blocked once any delivery exists; delete likewise.

### 6.4 Validation
- `material_request_id` / `vendor_id` required and must exist; MR status must be `approved` or `ordered`.
- `items` required, at least one line.
- Each line: `catalog_item_id` required + must exist; `quantity_ordered` required, numeric, > 0; `unit_price` optional, ≥ 0 if given.

### 6.5 What a PO does *not* have
No vendor-facing delivery (nothing is emailed or transmitted to the vendor — "send" is a status flip only), no PDF generation, no terms/tax/freight/shipping fields, no approval or spend-threshold gate of its own (whoever holds `manage_purchase_orders` can create and issue any value), no revision history once issued, no invoice or three-way-match, no link to accounting.

---

## 7. Module 4 — Deliveries (receipt against a PO)

Gated by `receive_deliveries` — deliberately broad: Foreman, Site Engineer, Assistant PM, PM, Procurement, Admin. The people physically on site record receipt.

**Delivery header:** `purchase_order_id`, `received_by`, `received_at`, `bol_number` (bill of lading), `has_discrepancy`, `notes`.
**Delivery lines:** `purchase_order_item_id`, `quantity_received`, `quantity_accepted` (optional — the accepted/rejected split), `notes`.
**Discrepancy:** `delivery_item_id` (optional), `discrepancy_type_id`, `description`, `reported_by`. Types seeded: `short_shipment`, `damage`, `substitution`, `overage`, `wrong_item`.

**Rules:**
- Recording is **blocked** if the PO is `draft` or `cancelled` (409) — a PO must be issued before goods can be received against it.
- On receipt, in one transaction: create the delivery + lines → **recompute the PO status** (sum `quantity_received` per PO line across *all* deliveries; if every line is fully received → `received`, else if any received → `partially_received`) → **cascade to the originating MR** (`partially_delivered` / `delivered`).
- Adding a discrepancy simply creates the row and flips `has_discrepancy = true`. **There is no discrepancy resolution workflow** — no return-to-vendor, no credit note, no re-order trigger, no status of its own.
- Deliveries are **append-only in practice** — there is no update or delete endpoint for a delivery or its lines. A mis-keyed receipt cannot be corrected through the API.
- **There is no over-receipt guard**: `quantity_received` is not capped at `quantity_ordered`. Receiving 500 against an order of 100 is accepted and marks the PO `received`.

---

## 8. Module 5 — Issues (field-to-office tracking)

Project-scoped. `manage_issues` is held by every role except… none — all seven roles hold it.

**Fields:** `project_id`, `daily_log_id` (optional link), `raised_by`, `assigned_to`, `issue_status_id`, `title`, `description`, `severity` (free-text string, not a lookup), `resolved_by`, `resolved_at`.
**Statuses:** `open`, `in_progress`, `resolved`(terminal), `closed`(terminal).

**Rules:** anyone with `manage_issues` can raise and update an issue; **only Admin or Project Manager can resolve** (403 otherwise), and resolving twice is a 409. Resolve stamps `resolved_by` + `resolved_at`. `closed` is seeded but has no endpoint; there is no re-open path.

---

## 9. Module 6 — Daily Logs (the Foreman's Daily Scope of Work)

**Fields:** `project_id`, `logged_by`, `log_date`, `work_description`, `weather`, `crew_count`, `has_issue`.

**Access — the one deliberate exception in the system:** both reads *and* writes require **active project membership AND a permission** (`view_daily_log` to read, `submit_daily_log` to write). Every other project-scoped read is membership-only. This was an explicit product decision — daily logs are not visible to all project members by default. Procurement holds neither and is fully excluded.

**Rules:**
- `logged_by` is **always the authenticated user** — you log your own scope of work; no logging on behalf of others.
- **One live log per (project, person, date)** — 422 on a clash. `log_date` must be `before_or_equal:today` (no future field reports).
- Edit/delete = author or Admin, **no time limit** (a Foreman can revise a log from any past date).
- **Optional linked issue on submit:** the payload may carry an `issue` object; the log and the issue are created in one transaction, the issue gets `daily_log_id`, and the log's `has_issue` flips true. Raising the issue additionally requires `manage_issues`.

**Not present:** no photos (no attachment path), no labor hours / headcount by trade, no equipment log, no material-delivered-today log, no visitor/inspection log, no weather auto-capture, no signature or supervisor approval, no PDF export.

---

## 10. Module 7 — Change Orders (CO)

The most complex workflow, and the one with an **external** decision-maker: the General Contractor.

### 10.1 Data

**Header:** `change_order_no` (auto, prefix `CO`), `project_id`, `cost_code_id` (optional), `change_order_type_id` (`normal` | `emergency`), `change_order_status_id`, `gc_decision_id`, `originator_id`, `title`, `description`, `scope`, `location`, `urgency_id`, **`value`** (a single decimal), `counter_signed_by` / `counter_signed_at`, `gc_decision_by` / `gc_decision_at` / `gc_decision_notes`, `became_active_at`, `document_attachment_id`.

**`ChangeOrderApproval`:** same audit shape as MR.
**`ChangeOrderSignature` + `Attachment`:** emergency path only — signer identity, geo/time, device info, and the signature image file.

**Statuses (10):** `draft`, `pending_pm`, `pending_admin`, `sent_back`, `rejected_internal`(terminal), `pending_counter_sign`, `pending_gc`, `active`(terminal), `gc_rejected`(terminal), `cancelled`(terminal).
**GC decisions (3):** `pending`, `approved`, `rejected`.

### 10.2 Normal flow

```
draft ──submit (originator)──> pending_pm ──validate (PM)──> pending_admin ──approve (Admin)──> pending_counter_sign
                                                                                                       │
                                                                              counter_sign (Admin; requires value ≠ null)
                                                                                                       ▼
                                                                                                  pending_gc
                                                                                        ┌──────────────┴──────────────┐
                                                                          gc-decision approved              gc-decision rejected
                                                                                    ▼                                ▼
                                                                                 active                         gc_rejected

pending_pm | pending_admin ──send_back──> sent_back ──(edit + resubmit)──> pending_pm
pending_pm | pending_admin ──reject────> rejected_internal  (terminal)
any non-terminal ──cancel──> cancelled
```

**Step rules:**
- **Create** — `create_change_request` (Foreman, Site Engineer, Assistant PM, PM, Admin). Only `title` is required; description, scope, location, cost_code, urgency, and **value** are all optional at creation.
- **Edit** — only while `draft`, `sent_back`, or **`pending_counter_sign`**. That last one is deliberate: it is the window in which the `value` gets filled in immediately before counter-signing.
- **Submit** — only the originator (or Admin), from `draft` or `sent_back`.
- **Validate** (`pending_pm` → `pending_admin`) — must be PM-level.
- **Approve** (`pending_admin` → `pending_counter_sign`) — must be Admin.
- **Counter-sign** (`pending_counter_sign` → `pending_gc`) — must be Admin, and **blocked with 422 if `value` is still null**. Stamps `counter_signed_by`/`_at`.
- **GC decision** (`pending_gc` → `active` | `gc_rejected`) — recorded by a PM-level user **entering the GC's out-of-band, emailed answer**. Stamps `gc_decision_by`/`_at`/`_notes` and, on approval, `became_active_at`.
- **Send-back / reject** — same two-level PM-at-`pending_pm` / Admin-at-`pending_admin` gate.
- **Cancel** — PM-level or the originator; blocked once the CO is `active`, `gc_rejected`, `rejected_internal`, or `cancelled`.

Only Admin and PM hold `approve_change_request` — Procurement does not.

### 10.3 Emergency flow

A single atomic call by a field-level user (`create_change_request`, explicitly extended to **Foreman** for exactly this purpose):

1. The CO is created going **straight to `active`**, `gc_decision = approved`, `became_active_at = now()` — **no PM step, no Admin step, no counter-sign**.
2. The signature image (base64 / data-URI, PNG or JPEG, 5 MB cap) is decoded and stored as a real private Attachment.
3. A `ChangeOrderSignature` row captures signer name / title / company / contact, `signed_at`, geo lat+lng, location note, device info, `captured_by`.
4. One `submit` approval row is logged (null → active).

**The on-site GC signature *is* the authorization.** There is no PM/Admin review afterward — not before, not after.

**Emergency validation is stricter than normal:** `title`, **`scope`, and `location` are all required** (they are optional on a normal CO) — because there is no later review step to fill the gaps, the record must state what happened and where at capture time. `signature_image` and `signer_name` are required; lat/lng bounded to valid coordinate ranges if given.

### 10.4 Change Orders and money
Change Orders **do not touch the pricing catalog or vendor rates at all.** `value` is a single freeform decimal typed in by the originator or Admin. There is no line-item breakdown, no labor/material/equipment split, no markup or overhead & profit calculation, no link to catalog items or vendor rates, no schedule-impact (time extension) field, and no roll-up into the project budget or contract sum.

The real difference between Normal and Emergency is **not pricing — it is the authorization path.** Normal requires the full PM → Admin → counter-sign → GC chain and a `value` before counter-signing. Emergency skips the entire internal chain because the on-site signature is the authorization, and can go active with `value` still null.

### 10.5 What the CO module defers
No server-side PDF of the formal change-order document. No email dispatch to the GC (there is no `gc_email` field anywhere) and no in-app notifications — the `pending_gc` state exists precisely so a future job can pick it up. No T&M / force-account daily ticket flow. No linkage from a CO to the MRs or POs that the extra work generates.

---

## 11. Known gaps — what is *not* built

**Schema exists, zero application code:**
- **Estimates** — `estimates`, `estimate_versions`, `estimate_line_items` tables and models exist (with `vendor_rate_id`, `quantity`, `unit_price`, `line_total`, version status), but there are **no endpoints, no service, no workflow**. So the system can price a purchase but cannot yet produce a customer estimate or bid.
- **Notifications** — table exists, nothing writes to or reads it. No user in this system is ever notified that something is waiting for their approval. Every approval chain relies on out-of-band nudging.
- **Email verification** — table only.
- **Activity logs**, **email logs** — tables only.

**Permissions seeded but with no module behind them:**
- `view_inventory` / `update_inventory` — **there is no inventory module at all.** Material is requested, purchased, and received, but never tracked as stock, never issued out to a work area, and never reconciled.
- `view_reports` — **no reporting or analytics endpoints exist.**

**Cross-cutting gaps relevant to the three workflows being researched:**
1. **No cost roll-up.** `cost_code_id` is captured on MR lines, PO lines, and CO headers, but nothing aggregates by cost code. `projects.budget` is never compared against committed or actual spend.
2. **No spend thresholds.** MR and CO approval chains are fixed at PM→Admin regardless of value. A $50 request and a $50,000 request take the identical path. POs have **no approval step at all** — anyone with `manage_purchase_orders` can create and issue any amount.
3. **No vendor-facing anything.** Vendors don't receive POs, acknowledge them, or submit invoices. `sent` and `acknowledged` are internal status flips.
4. **No invoicing / three-way match / payment.** Nothing closes the loop from PO → delivery → vendor invoice → payment.
5. **No returns / RMA.** The MR `returned` status is unreachable; delivery discrepancies have no downstream flow.
6. **No document output.** No PDF for a PO, a CO, or a daily log — nothing printable to hand a vendor, a GC, or a client.
7. **No schedule impact on Change Orders** — value only, no time extension, and no link between a CO and the milestone dates it should move.
8. **Payment milestones are inert** — flagged and valued on milestones, but connected to no billing or draw process.
9. **No photos anywhere** except the emergency signature.
10. **Deliveries cannot be corrected** and can over-receive without limit.

---

## 12. End-to-end data flow, as built

```
        Client ──> Project ──> Milestones (phases, predecessors, payment flags — inert)
                      │
                      ├── Daily Logs ──(optional)──> Issues
                      │
                      ├── Material Request  [no prices]
                      │        │ Foreman/SE/PC/APM/PM creates draft, adds lines
                      │        │ submit → PM approves → Admin approves → APPROVED
                      │        ▼
                      │   Purchase Order  [prices enter here]
                      │        │ requires an approved MR + one vendor
                      │        │ each line: catalog item + current vendor rate  (or manual override)
                      │        │ price snapshotted onto the line, forever
                      │        │ draft → issued → sent
                      │        ▼
                      │   Delivery (receipt)
                      │        │ cascades PO status: partially_received → received
                      │        │ cascades MR status: partially_delivered → delivered
                      │        └── Discrepancy (flag only, no resolution flow)
                      │
                      └── Change Order   [value typed in by hand; no catalog link]
                               ├── NORMAL:    draft → PM validate → Admin approve →
                               │              Admin counter-sign (value required) →
                               │              pending GC → [emailed, answered out-of-band] →
                               │              ACTIVE or GC-REJECTED
                               └── EMERGENCY: one call → ACTIVE
                                              (on-site GC signature + geo + timestamp = authorization)

        Vendors ──< Vendor Rates (append-only ledger, one open rate per vendor+item) >── Catalog Items
                                          └── read by PO pricing only
```

---

## 13. Research questions this document is meant to support

The following are the open questions to evaluate against industry best practice for residential / light-commercial construction, particularly for a contractor working **under a General Contractor**.

**Material Requests**
1. Is a two-level (PM → Admin) approval on *every* request, regardless of value, standard — or do real systems use value thresholds, and at what typical break points?
2. Should an MR carry an estimated cost so approvers know what they are approving? This system deliberately shows approvers no price at all.
3. Is "requester writes free text instead of picking a catalog item" a normal accommodation, or does it cause downstream data problems worth forcing catalog discipline over?
4. What does industry practice do about partial approval — approving 8 of 10 lines? This system approves or rejects the whole request.
5. Should `needed_by_date` drive anything (lead-time warnings, urgency escalation)? Here it is inert.

**Purchase Orders**
6. Is requiring every PO to originate from an approved MR realistic? What about blanket POs, subcontract POs, service POs, and emergency counter-purchases?
7. Should a PO have its own approval/spend-authority gate separate from the MR's? Here it has none.
8. What are standard PO document fields this system lacks — payment terms, tax, freight, delivery address, ship-to vs bill-to, retainage, revision number?
9. What is standard practice for PO revisions after issuance (change to a PO, not a change order to the client)? Here an issued PO is immutable.
10. How do systems typically handle vendor acknowledgement and PO transmission?
11. Three-way matching (PO ↔ receipt ↔ invoice) — how essential is it at this company size?

**Deliveries & receiving**
12. Over-receipt tolerance: what percentage over-delivery do systems typically accept before blocking or requiring approval?
13. What is the standard resolution flow for a short shipment, damage, or wrong item?
14. Should receipts be correctable/reversible, and how is that audited?

**Change Orders**
15. Is the PM-validate → Admin-approve → Admin-counter-sign → GC-decision chain aligned with how subcontractor change orders actually move? Is "counter-sign" a real, distinct step or a merge of two things?
16. What belongs in a change order beyond a single `value` — labor/material/equipment breakdown, markup and O&P percentages, and above all **schedule impact / time extension**?
17. Emergency / field change orders: is an on-site signature with geo + timestamp accepted as authorization in practice, and what is the standard follow-up (retroactive pricing, T&M tickets, a formalization deadline)?
18. Should an emergency CO really be allowed to go active with no value at all? What is the industry pattern for pricing after the fact?
19. What is the standard relationship between a change order and the T&M / force-account daily tickets that document the extra work?
20. How do COs normally roll into contract value, billing, and schedule — and what breaks if, as here, they roll into nothing?

**Cross-cutting**
21. Where should cost codes actually roll up, and what is the minimum viable job-cost report a contractor this size needs?
22. Which of the missing pieces (notifications, PDFs, estimates, invoicing, inventory, reporting) matter most in practice, and in what order would a practitioner build them?
