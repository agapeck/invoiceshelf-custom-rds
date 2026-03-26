# High-level code and app review 2

Date: 2026-03-26

## Scope and method

This follow-up review was built from:

- the March 2026 review/report chain in the repo, including:
  - `High-level code and app review 1.md`
  - `Response to remaining high-level review.md`
  - `Fresh eyes review.md`
  - `fresh eyes review post-codex fixes.md`
  - `addressing high-level issues.md`
  - `Antigravity Opus review 1.md`
  - `GLM_Response_to_Antigravity_Review.md`
  - `Grok+GLM bug report.md`
  - `Deep Analysis Supplement.md`
  - `Performance Optimization Report.md`
  - `Repo Review by Grok 4.2.md`
  - `Grok bugreport 2.md`
  - `Bugssss.md`
  - `De bugs.md`
  - `Almost slipped through bugs.md`
  - `Speed_optimization_bugs.md`
  - `implementation_plan.md`
- the current code in this working tree
- the current local app/runtime config on this PC, including host-level verification of the deployed dev instance

I treated every prior `.md` claim as provisional and re-checked the current code before carrying it forward. I also checked the local runtime state with `php artisan about`, `php artisan schedule:list`, `.env`, nginx/systemd inspection, direct local HTTP probes, and host-level DB connectivity checks against the deployed dev instance on this PC.

## Executive summary

The last review’s directions were only partially implemented, but enough of them were completed that a fresh bugfix pass should not blindly follow `High-level code and app review 1.md` line by line.

Current reality:

- Several major March findings are genuinely fixed now and should not be reworked again.
- Several documented bugs are still definitely present.
- I also found one additional severe current-state bug that the March docs did not cleanly surface: bulk exchange-rate updates are still not company-scoped.
- The local deployed config on this PC is still operationally unsafe/incomplete, but those host/runtime tasks should be kept separate from the next code bugfix pass unless explicitly requested.

The best next step is a tightly scoped backend bugfix pass focused on verified controller/request/model correctness bugs first, with a small second wave of low-risk validation and cleanup fixes. Do not broaden the next pass into infrastructure refactors, scheduler redesign, or FileDisk architecture changes.

## Current host/app config on this PC

Verified from the current repo checkout:

- `.env` still has `APP_ENV=local`
- `.env` still has `APP_DEBUG=true`
- `.env` still has `APP_URL=http://localhost:3000`
- `.env` still has `TRUSTED_PROXIES="*"`
- no visible `CRON_JOB_AUTH_TOKEN` entry exists in `.env`
- no visible mail transport settings were present in the inspected `.env`
- `php artisan about --only=environment` confirms local/debug mode

Verified deployed dev-host state on this PC:

- nginx is serving this repo clone on `127.0.0.1:3000`
- the enabled nginx vhost points at `/home/hp/invoiceshelf-custom-rds/public`
- `invoiceshelf-queue.service` is enabled and runs against `/home/hp/invoiceshelf-custom-rds`
- `php artisan migrate:status` works with host-level access
- `php artisan schedule:list` works with host-level access and shows the app-defined schedule
- no user crontab entry or separate scheduler service was found during this review
- this PC is a dev instance, and the lack of production R2/S3 backup scheduling here is intentional

Interpretation:

- the earlier DB-backed command failures I hit during review were sandbox-related, not proof that the deployed dev instance lacked DB connectivity
- this dev PC does have a working local MySQL-backed app/runtime path
- host/runtime conclusions should still stay narrow because this is not the production host
- the next coding pass should still stay focused on low-risk code bugfixes, not deployment work

## Status of the last review’s directions

### Verified fixed enough to remove from the next bugfix pass

- Active-company policy hardening for `CustomerPolicy`, `InvoicePolicy`, `EstimatePolicy`, `PaymentPolicy`, and `AppointmentPolicy` is present now.
- The payment deletion decimal-casting bug from the prior review is fixed. `Payment::deletePayments()` now restores invoice balances through `Invoice::addInvoicePayment()`.
- Invoice create-and-send sent-flag consistency is fixed. `Invoice::send()` now sets `sent = true` even when the invoice was already created in `STATUS_SENT`.
- Backup delete is now null-safe in `app/Http/Controllers/V1/Admin/Backup/BackupsController.php`.
- Estimate conversion no longer drops base currency item/tax fields and is now transactional/locked.
- The earlier recurring-invoice scheduler closure explosion is gone from the codebase; `routes/console.php` schedules commands, not per-record closures.

### Still relevant from the last review/report chain

- `app/Http/Controllers/V1/Admin/Invoice/ChangeInvoiceStatusController.php` is still unsafe.
- `app/Http/Controllers/V1/Customer/Estimate/AcceptEstimateController.php` is still unsafe.
- `app/Http/Middleware/CustomerPortalMiddleware.php` still crashes on a null customer.
- `app/Http/Controllers/V1/Admin/Invoice/CloneInvoiceController.php` and `app/Http/Controllers/V1/Admin/Estimate/CloneEstimateController.php` still perform multi-step clones without a transaction.
- request validation gaps called out in later March docs are still present in several request classes.

### Stale or not worth pursuing in the next pass

- Do not spend the next pass on FileDisk architectural refactors (`config()` mutation, APP_KEY rotation strategy, dynamic disk factory redesign). Those are real concerns but are higher-risk changes than the current bugfix brief needs.
- Do not spend the next pass on scheduler wiring, host TLS enforcement, Redis/MySQL operational tuning, or mail setup. Those are deployment tasks, not code bugfix tasks.
- Do not spend the next pass on design-dependent findings such as recurring `COUNT` behavior unless product intent is clarified first.

## Verified bugs to fix now

These are the bugs I believe the next coding pass should fix first.

### P0

#### 1. Bulk exchange-rate updates are still cross-tenant

Files:

- `app/Http/Controllers/V1/Admin/General/BulkExchangeRateController.php`
- `app/Http/Requests/BulkExchangeRateRequest.php`

Verified current behavior:

- the controller updates `Invoice`, `Estimate`, `Payment`, and `Tax` rows by `currency_id` only
- none of those queries are scoped by `company_id`
- an admin in one company can therefore rewrite base values for records belonging to other companies that use the same currency

Why this should be first:

- it is both a correctness bug and a tenant-isolation bug
- the fix is conceptually straightforward and low-risk if done by consistently applying company scoping plus request validation

Required fix shape:

- scope every bulk update query by current company
- validate `currencies` as an array
- validate `currencies.*.id` against company-relevant currency rows or at minimum valid numeric IDs
- validate `currencies.*.exchange_rate` as numeric and bounded positive input
- add regression coverage proving Company A cannot alter Company B financial rows

#### 2. Invoice status endpoint still allows paymentless completion

File:

- `app/Http/Controllers/V1/Admin/Invoice/ChangeInvoiceStatusController.php`

Verified current behavior:

- posting `status = COMPLETED` sets `paid_status = PAID` and `due_amount = 0`
- there is no validation that payments exist or that the invoice is actually fully paid
- invalid statuses are silently accepted and return success with no state change

Why this should be first:

- it corrupts the financial ledger directly
- it is already heavily documented in March reports
- the change is localized and testable

Required fix shape:

- validate allowed status values
- block `COMPLETED` unless the invoice is already fully paid through real payment state
- return a 422 on invalid transitions instead of silent success

### P1

#### 3. Both estimate status endpoints still accept arbitrary status writes

Files:

- `app/Http/Controllers/V1/Admin/Estimate/ChangeEstimateStatusController.php`
- `app/Http/Controllers/V1/Customer/Estimate/AcceptEstimateController.php`

Verified current behavior:

- the admin endpoint does `$estimate->update($request->only('status'))` with no validation
- the customer endpoint does the same
- the customer endpoint does not restrict transitions to customer-safe values or require the estimate to be in a customer-actionable state first

Required fix shape:

- validate against explicit allowed status sets
- for the customer endpoint, only allow customer-safe transitions such as accepted/rejected
- require the estimate to be in a valid starting state before changing it

#### 4. Customer portal middleware still null-dereferences expired/invalid sessions

File:

- `app/Http/Middleware/CustomerPortalMiddleware.php`

Verified current behavior:

- `$user = Auth::guard('customer')->user()`
- the code immediately reads `$user->enable_portal`
- if the customer session is missing or expired, this is a fatal null access

Required fix shape:

- guard null before reading `enable_portal`
- keep behavior fail-closed
- add a regression test for an unauthenticated request through this middleware

#### 5. Clone invoice and clone estimate flows still lack transaction safety

Files:

- `app/Http/Controllers/V1/Admin/Invoice/CloneInvoiceController.php`
- `app/Http/Controllers/V1/Admin/Estimate/CloneEstimateController.php`

Verified current behavior:

- each controller creates the parent record, then items, then taxes, then custom fields
- none of that is wrapped in `DB::transaction()`
- any exception mid-clone can leave a half-created document

Required fix shape:

- wrap each clone flow in a transaction
- preserve current serial-generation and eager-loading behavior
- add a rollback-oriented test if feasible

### P1.5

#### 6. Request validation gaps that are real and low-risk to close

Files:

- `app/Http/Requests/BulkExchangeRateRequest.php`
- `app/Http/Requests/CustomerRequest.php`
- `app/Http/Requests/ExpenseRequest.php`
- `app/Http/Requests/PaymentRequest.php`

Verified current behavior:

- `BulkExchangeRateRequest` only requires the exchange rate field; it does not enforce numeric/positive input
- `CustomerRequest` has no meaningful password strength/confirmation rules
- `ExpenseRequest` accepts `amount` as merely `required`
- `PaymentRequest` makes `exchange_rate` required when currencies differ, but still does not enforce numeric/positive bounds

Required fix shape:

- keep the fixes validation-only
- do not change broader business rules beyond what the current code already assumes
- add or update targeted request/controller tests

### P2 easy fixes worth bundling in the same pass

#### 7. `Customer::creator()` still points to the wrong model

File:

- `app/Models/Customer.php`

Verified current behavior:

- `creator()` returns `belongsTo(Customer::class, 'creator_id')`
- elsewhere the system writes `creator_id` from the authenticated admin user
- `User` already has the inverse `customers()` relationship

Recommended action:

- change the relation to `User::class`
- add a small unit/feature assertion around eager loading if possible

#### 8. Minor request/model hygiene bugs that are easy and low-risk

Files:

- `app/Http/Requests/InvoicesRequest.php`
- `app/Http/Requests/EstimatesRequest.php`
- `app/Http/Requests/RecurringInvoiceRequest.php`
- `app/Models/Customer.php`
- `app/Http/Requests/DeleteCustomersRequest.php`
- `app/Http/Requests/DeleteInvoiceRequest.php`
- `app/Http/Requests/DeleteEstimatesRequest.php`
- `app/Http/Requests/DeleteExpensesRequest.php`
- `app/Http/Requests/DeleteItemsRequest.php`

Verified current behavior:

- fallback values still use `'NO '` with a trailing space in multiple request payload builders
- `RecurringInvoiceRequest` still defines `exchange_rate` twice
- `Customer::getAvatarAttribute()` returns integer `0` rather than a null/empty string style fallback
- several bulk delete request classes still validate `ids.*` without validating `ids` as an array or clamping size

Recommended action:

- bundle these only after the higher-priority bugs above are covered
- keep them strictly minimal and test-backed

## Recommended implementation order

The next coding pass should be sequenced like this:

1. Add focused regression tests for the highest-risk controller bugs first.
   - bulk exchange rate scoping
   - invoice status completion guard
   - admin/customer estimate status validation
   - customer portal null-guard

2. Fix the two highest-risk correctness bugs.
   - bulk exchange-rate controller + request
   - invoice status controller

3. Fix the customer/admin estimate status endpoints.

4. Wrap clone invoice/estimate flows in transactions.

5. Close the low-risk request validation gaps.

6. Only then bundle the tiny cleanup fixes.
   - `Customer::creator()`
   - avatar fallback
   - trailing-space defaults
   - duplicate request key
   - bulk delete array validation

This order is intentional:

- it addresses the most dangerous documented bugs first
- it avoids risky architectural churn
- it keeps each fix localized and regression-testable
- it reduces the chance of breaking the app while still making real progress

## What the next pass should explicitly avoid

Unless you want a separate hardening/deployment pass, the coding agent should not use this bugfix turn to:

- redesign FileDisk dynamic config handling
- implement APP_KEY rotation support
- rework scheduler topology
- change host `.env`/systemd/cron/TLS/mail setup
- undertake broad multi-policy refactors outside the specific verified bugs above

Those are valid topics, but they are not the most efficient or safest next move for this repository state.

## Testing and verification notes for the coding agent

Current local verification note:

- DB-backed Artisan commands are runnable on the host when executed with host-level access
- my initial failures on `migrate:status`, `schedule:list`, and targeted PHPUnit were caused by sandbox restrictions reaching local services
- I did not complete a clean, conclusive targeted PHPUnit pass during this follow-up host inspection, so full test status should still be treated as not yet established for this review

Because of that, the coding agent should:

- still add focused regression tests for each fix
- run targeted tests directly on the host environment rather than assuming sandbox-only results are authoritative
- avoid claiming full verification unless the relevant targeted tests actually pass

Minimum acceptable verification for the next pass:

- static code verification of each bug fix
- targeted tests added or updated for the changed behavior
- selective execution if the environment allows it

## Bottom line

The best way to proceed is not another broad audit pass. It is a disciplined bugfix pass that:

- fixes the verified documented controller/request bugs first
- includes the newly verified bulk exchange-rate tenant leak
- bundles only a small number of truly easy cleanup bugs afterward
- leaves deployment and architectural hardening for a separate follow-up

That will give the best risk reduction per change while minimizing the chance of destabilizing the app.
