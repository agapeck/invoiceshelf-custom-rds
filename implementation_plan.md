# Implementation Plan Review + Hardening Status (March 19, 2026)

## Verdict on the previous plan

The prior plan was **not pure AI slop**, but it mixed strong findings with a few speculative or incomplete items.

### Accurate/high-value parts
- Tenant scoping hardening in requests/models/controllers.
- Replacing resource `->exists()` patterns with `whenLoaded()` plus controller eager-loading.
- Concurrency concerns around payment/invoice due-amount updates.
- Delete-request scoping and soft-delete-aware validation.

### Problems in the previous plan
- Some recommendations were not fully mapped to concrete code paths.
- Several file references were incorrect or generic.
- It missed customer-portal-specific regressions and a few request-context bypass paths.

## Newly identified bugs (added beyond prior bughunts)

1. **Customer profile billing/shipping write bug**
   - `ProfileController` wrote billing payload into shipping address and vice versa.
   - Fixed by mapping billing → billing and shipping → shipping correctly.

2. **Appointment company-context override risk**
   - `getAvailableSlots` accepted query `company_id` with higher priority than header context.
   - Fixed by prioritizing the authenticated header company and scoping `exclude_appointment_id` validation by company.

3. **Appointment hash regeneration skip bug**
   - `Appointment::regenerateMissingHashes()` used `chunk()` on a mutating filter set.
   - Fixed by grouping null/empty conditions and switching to `chunkById()`.

4. **Unsafe dynamic ordering in high-traffic paths**
   - Found unsanitized dynamic order handling in:
     - `Invoice::scopeApplyFilters`
     - `Appointment::scopeApplyFilters`
     - `RolesController::index`
   - Fixed via strict field/direction allowlists and safe fallbacks.

5. **Tenant leakage via ungrouped `orWhere` conditions**
   - `Expense::scopeWhereSearch` and `FileDisk::scopeWhereSearch` could bypass company filters.
   - Fixed by wrapping OR conditions inside grouped closures.

## Completed hardening/performance work

### Security & data isolation
- Strengthened request validation bounds:
  - non-negative totals/taxes/prices/amounts in invoice/estimate/payment requests.
- Scoped assigned user lookup in invoice payload generation to active company.
- Payment deletion now supports explicit company scoping from controller.
- `ConfigMiddleware` now scopes by company header when available, with safe fallback for non-company contexts.

### Concurrency and reliability
- Added row-level invoice locks (`lockForUpdate`) in payment create/update/delete flows to reduce lost updates under concurrency.
- Wrapped bulk payment deletion in transaction with invoice row locks.

### API performance
- Converted customer-facing resources from relation `->exists()` checks to `whenLoaded()` to avoid N+1 serialization queries.
- Added/expanded eager-loading in customer controllers and customer PDF data endpoints to match resource requirements.
- Added optional request timing logger controls:
  - `REQUEST_TIMING_LOG_ENABLED`
  - `REQUEST_TIMING_LOG_THRESHOLD_MS`

### DB-layer speed support
- Added migration:
  - `2026_03_19_140001_add_performance_indexes_for_high_traffic_lists.php`
- Includes composite indexes for invoice/estimate/payment/expense/customer/appointment/file_disk list patterns.

## Test status

New regression tests were added under:
- `tests/Feature/Hardening/*`
- `tests/Unit/Hardening/*`

Current environment limitations:
- `pdo_sqlite` is missing, so DB-backed tests are skipped locally.
- Existing project test runner is Pest-style, but Pest runtime wiring is incomplete in this workspace, so full-suite execution remains blocked.

## Remaining recommended work (next pass)

1. Enable and run DB-backed tests in an environment with `pdo_sqlite` (or test MySQL config), then remove skip paths.
2. Execute full endpoint query profiling with timing logs enabled in staging.
3. Add load/concurrency tests for payment create/update/delete hot paths (parallel writes).
4. Validate DB indexes with `EXPLAIN` on production-like data and trim unused indexes after observation window.
5. Confirm Redis/session/queue driver settings and FPM worker tuning in deployment environment.
