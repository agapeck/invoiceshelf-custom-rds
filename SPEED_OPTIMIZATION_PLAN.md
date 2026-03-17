# RDS App Speed & Security Optimization Plan

## Goal

Implement the code-level performance and security improvements from the VPS `speed` branch into this local codebase. These changes reduce API query counts by ~95% on list endpoints (e.g., 400 queries → 4 for a 50-row invoice list) and close multi-tenant data isolation gaps.

Reference: `vps_commits.md` contains the exact unified diff from the VPS deployment.

## Changes Overview

### Performance (N+1 query elimination)

| Milestone | Files | What |
|---|---|---|
| **M1: Resource `whenLoaded`** | 8 resources | Replace `->exists()` with `whenLoaded()` |
| **M2: Controller eager-loading** | 16 controllers | Add `with()`/`load()` to match resources |
| **M3: Model-internal eager-loads** | 5 models | Expand `::with([...])->find()` inside model methods |
| **M4: CompanySetting cache** | 1 model | Static per-request cache for `getSetting()` |
| **M5: ExpenseCategory withSum** | 1 model + 1 controller | Preloaded aggregate instead of per-row query |

### Security (tenant isolation + injection prevention)

| Milestone | Files | What |
|---|---|---|
| **M6: `orWhere` → `where`** | 12 models | Fix tenant scope bypass on ID-filter scopes |
| **M7: `orderBy` whitelists** | 11 locations | Prevent SQL injection via sort field |
| **M8: Request validation scoping** | 6 request files | `customer_id`, `invoice_id`, `assigned_to_id` scoped to company |
| **M9: Delete request scoping** | 7 request files | Bulk-delete IDs validated against company |
| **M10: Utility hardening** | 6 files | RelationNotExist, HasCustomFieldsTrait, SerialNumberFormatter, ConfigMiddleware, PaymentMethod::getSettings |
| **M11: PDF controller scoping** | 3 controllers | Customer lookups scoped to company |

### Infrastructure & Documentation

| Milestone | Files | What |
|---|---|---|
| **M12: RequestTimingLogger** | 2 files | Diagnostic middleware for slow API requests |
| **M13: Documentation** | 2 files | RDS_OPTIMIZATIONS.md + INSTALLATION_GUIDE.md |

## Verification

App is not installed locally — verification is code-review only. Each milestone is committed separately for easy rollback.
