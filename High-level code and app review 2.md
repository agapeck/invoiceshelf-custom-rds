# High-level code and app review 2

Date: 2026-03-27

## Scope

This follow-up review was based on:

- the March 2026 report/review chain in the repo
- git history through the March 25-26, 2026 commits, especially:
  - `dbc5740c` - `Implement review 2 bugfixes and hardening`
  - `315caf56` - `Remaining unfixed discovered bugs`
- the current code at `HEAD`
- the current deployed dev-instance config on this PC

I re-checked prior markdown claims against current code before carrying them forward.

## Executive summary

The current committed `High-level code and app review 2.md` is no longer an accurate action list.

Why:

- `dbc5740c` already fixed most of the controller/request bugs that the committed report still says are open.
- `315caf56` added a useful follow-up note, but mostly for factories/test-harness cleanup.
- the remaining work is now narrower than the March 25-26 report chain suggests.

Best next step:

- do a focused backend bugfix pass
- do not repeat already-fixed March 26 work
- keep deployment/ops and FileDisk architecture work separate
- fix the small number of still-verified app bugs first
- only then decide whether to take the still-larger delete-cascade cleanup

## Current deployed app config on this PC

Verified current host/runtime state:

- `.env` still has:
  - `APP_ENV=local`
  - `APP_DEBUG=true`
  - `APP_URL=http://localhost:3000`
  - `TRUSTED_PROXIES="*"`
  - no visible `CRON_JOB_AUTH_TOKEN`
- `php artisan about` confirms:
  - environment `local`
  - debug enabled
  - queue `redis`
  - database `mysql`
- nginx serves this repo from:
  - `/home/hp/invoiceshelf-custom-rds/public`
  - on `localhost:3000`
- `/etc/systemd/system/invoiceshelf-queue.service` is active and points at this repo
- `php artisan schedule:list` shows app-defined scheduled tasks
- no scheduler wiring was found via cron or a separate schedule service
- `config('services.cron_job.auth_token')` currently resolves to `NULL`

Interpretation:

- this PC is still a dev host, not a production-hardening reference
- host/runtime issues are real, but they should stay out of the next code bugfix pass unless explicitly requested

## What is already fixed and should not be reworked now

These were previously reported but are now fixed enough in current code:

- `app/Http/Controllers/V1/Admin/General/BulkExchangeRateController.php`
  - bulk exchange-rate updates are now company-scoped
- `app/Http/Requests/BulkExchangeRateRequest.php`
  - bulk exchange-rate payload is now array/numeric/positive validated
- `app/Http/Controllers/V1/Admin/Invoice/ChangeInvoiceStatusController.php`
  - status values are validated
  - paymentless completion is blocked
- `app/Http/Controllers/V1/Admin/Estimate/ChangeEstimateStatusController.php`
  - admin estimate status writes are now validated
- `app/Http/Controllers/V1/Customer/Estimate/AcceptEstimateController.php`
  - customer estimate actions are now constrained to sent estimates and accepted/rejected
- `app/Http/Middleware/CustomerPortalMiddleware.php`
  - null customer access no longer crashes
- `app/Http/Controllers/V1/Admin/Invoice/CloneInvoiceController.php`
- `app/Http/Controllers/V1/Admin/Estimate/CloneEstimateController.php`
  - clone flows are now transactional
- `app/Policies/CustomerPolicy.php`
- `app/Policies/InvoicePolicy.php`
- `app/Policies/EstimatePolicy.php`
- `app/Policies/PaymentPolicy.php`
- `app/Policies/AppointmentPolicy.php`
  - active-company policy checks are now strict
- `app/Models/Payment.php`
  - payment delete decimal drift is fixed
- `app/Http/Controllers/V1/Admin/Backup/BackupsController.php`
  - backup delete is now null-safe
- `app/Http/Controllers/V1/Admin/Appointment/AppointmentsController.php`
  - cross-midnight overlap handling is fixed
- already-cleaned request/model issues that do not need another pass:
  - `Customer::creator()`
  - avatar fallback
  - trailing-space `NO` defaults
  - delete-array caps for customer/invoice/estimate/expense/item deletes
  - `CustomerRequest` min password length
  - `ExpenseRequest` amount bounds
  - `PaymentRequest` exchange-rate bounds

## Verified bugs to fix now

These are the remaining bugs I recommend the next coding pass tackle.

### P1 - do first

#### 1. Company ownership transfer is still logically inverted

Files:

- `app/Http/Controllers/V1/Admin/Company/CompaniesController.php`

Verified current behavior:

- `transferOwnership()` rejects the target user when they already belong to the company:
  - `if ($user->hasCompany($company->id)) { ... "User does not belongs to this company." }`
- that means the transfer path only succeeds for a user who is not already in the company

Impact:

- legitimate ownership transfer to an internal teammate is blocked
- transfer to an outsider is incorrectly allowed

Why this should be first:

- verified
- localized
- high-value
- low-risk to fix

Safe fix shape:

- invert the membership check
- reject non-members
- add one focused feature test:
  - existing member succeeds
  - outsider fails

#### 2. User deletion is still unsafe and incomplete

Files:

- `app/Http/Requests/DeleteUserRequest.php`
- `app/Models/User.php`

Verified current behavior:

- `DeleteUserRequest` still does not validate `users` as an array or cap its size
- `User::deleteUsers()` still has no protection against deleting:
  - the current actor
  - the current company owner
- `User::deleteUsers()` still nulls creator links only on non-trashed relations:
  - `invoices()`
  - `estimates()`
  - `recurringInvoices()`
  - `expenses()`
  - `payments()`
  - `items()`
  - `customers()`
- several of those models use `SoftDeletes`, so trashed rows are skipped

Why that matters:

- self-delete can lock out the current admin/owner
- owner deletion can destabilize company ownership
- skipped trashed rows can either:
  - hard-delete historical business rows through FK cascade, or
  - fail delete flows on lingering FK references, especially for recurring invoices

Safe fix shape:

- `DeleteUserRequest`:
  - require `users` as `array`
  - cap size, e.g. `max:100`
- `User::deleteUsers()`:
  - explicitly block self-delete
  - explicitly block deleting the active company owner
  - null creator references with `withTrashed()` where supported, or use direct query builder updates by `creator_id`
- add focused tests for:
  - self delete blocked
  - owner delete blocked
  - user delete still succeeds when related records are soft-deleted

Complexity:

- moderate, but still localized enough for the next pass

#### 3. Invoice and estimate exchange-rate validation is still incomplete

Files:

- `app/Http/Requests/InvoicesRequest.php`
- `app/Http/Requests/EstimatesRequest.php`

Verified current behavior:

- default rule is `exchange_rate => ['nullable']`
- when customer currency differs from company currency, the rule becomes only `['required']`
- both request payload builders later multiply totals/tax/base fields by `exchange_rate`

Impact:

- non-numeric, zero, negative, or absurd exchange-rate input can still reach financial calculations on invoice/estimate create/update flows

Why now:

- verified
- validation-only
- low regression risk
- directly related to already-documented financial-integrity concerns

Safe fix shape:

- align both request classes with the already-hardened pattern used elsewhere:
  - `numeric`
  - `min:0.0001`
  - reasonable max
- add targeted tests for invoice/estimate foreign-currency create/update paths

#### 4. Create-and-send flows still have send-failure state bugs

Files:

- `app/Http/Requests/InvoicesRequest.php`
- `app/Http/Requests/EstimatesRequest.php`
- `app/Models/Invoice.php`
- `app/Models/Estimate.php`
- `app/Http/Controllers/V1/Admin/Invoice/InvoicesController.php`
- `app/Http/Controllers/V1/Admin/Estimate/EstimatesController.php`

Verified current behavior:

- invoice and estimate payload builders pre-set `status = SENT` when `invoiceSend` / `estimateSend` is present
- `Estimate::send()` also sets `status = SENT` before `Mail::send()`
- if mail sending throws, the request fails but the stored document can still remain marked as sent
- invoice flow can still end up with `status = SENT` while `sent = false` if creation stored `SENT` before the mail operation finished

Impact:

- document state can claim an email was sent when the send actually failed

Safe fix shape:

- do not pre-set `SENT` in payload builders just because a send flag is present
- transition to sent state only after successful mail send
- keep API/UI behavior unchanged on success
- add targeted tests that simulate mail failure and assert state stays unsent

Complexity:

- low to medium
- localized
- worth bundling with the validation fixes

### P2 - do after the above

#### 5. Customer deletion cascade is still only partially solved

Files:

- `app/Models/Customer.php`
- `app/Models/Invoice.php`
- `app/Models/Estimate.php`
- `app/Models/RecurringInvoice.php`

Verified current behavior:

- `Customer::booted()` now deletes related parent models one-by-one
- but `Invoice`, `Estimate`, and `RecurringInvoice` still do not own their full nested cleanup on soft delete
- invoice/estimate child tables (`invoice_items`, `estimate_items`, `taxes`) rely on DB `onDelete('cascade')`, which does not run when the parent is only soft-deleted
- direct deletes outside the customer path can still leave nested financial rows behind

Impact:

- incomplete cleanup on soft-delete flows
- potential orphaned nested rows
- duplicate cleanup logic still concentrated in `Customer::booted()`

Assessment:

- this is still the biggest remaining app-code item
- but it is no longer an undefined "big refactor"
- it is now a bounded model-lifecycle cleanup task if handled carefully

Recommended safe approach:

- do not keep expanding `Customer::booted()`
- move cleanup into parent-model deleting hooks:
  - `Invoice` deleting hook: transactions/items/taxes
  - `Estimate` deleting hook: items/taxes
  - `RecurringInvoice` deleting hook: items/taxes
- keep the hooks explicit and test-backed
- add tests for:
  - direct invoice delete
  - direct estimate delete
  - direct recurring invoice delete
  - customer delete still cascading correctly

Recommendation:

- only take this in the same pass if the P1 fixes are already done and green

### P3 - trivial bundle / cleanup

#### 6. Two March 26 "remaining bugs" are still real and easy

Files:

- `database/factories/ExchangeRateLogFactory.php`
- `database/factories/ItemFactory.php`

Verified current behavior:

- `ExchangeRateLogFactory` still assigns wrong ids to `company_id` / `base_currency_id`
- `ItemFactory` still assigns `creator_id` from a company id instead of a user id

Impact:

- misleading factories
- flaky or false-positive tests

Recommendation:

- safe to bundle in the same pass
- extremely low risk

#### 7. Optional tiny cleanups if the coding agent is already in those files

Files:

- `app/Models/RecurringInvoice.php`
- `app/Models/Invoice.php`
- `app/Http/Requests/TaxTypeRequest.php`

Verified current behavior:

- `RecurringInvoice::markStatusAsCompleted()` still contains the tautology `if ($this->status == $this->status)`
- `Invoice::getInvoiceStatusByAmount()` still uses `elseif ($amount == $this->total)` on money values
- `TaxTypeRequest` still accepts `percent` as merely `numeric` with no documented bounds

Recommendation:

- safe to bundle only after the main fixes above
- do not let these delay the higher-priority items

## Items that should stay out of the next pass

### Still real, but separate / higher-risk

- customer password reset storage-level tenant isolation
  - still uses a shared `password_reset_tokens` table
  - requires a more deliberate auth/broker design
- FileDisk runtime `config()` mutation / APP_KEY / endpoint-normalization concerns
  - real
  - architecture-level
  - too risky for a narrow bugfix pass
- DB/Redis TLS enforcement
  - deployment/config concern
- scheduler/cron/env/mail setup on this PC
  - operational concern
- broad pagination clamps / large report-validation sweep
  - useful, but not the highest-value next move

## Existing March docs: how to use them now

- `High-level code and app review 1.md`
  - useful historical context
  - not a current action list
- committed `High-level code and app review 2.md`
  - stale on its core priorities because `dbc5740c` already fixed most of the bugs it still says are open
  - should not be followed literally
- `Remaining unfixed discovered bugs.md`
  - still useful for the two factory bugs and general test-harness caution
  - UGX/dev-DB drift and risky-PHPUnit warnings are follow-up cleanup, not the next app bugfix pass

## Recommended implementation order

1. Add focused tests first for:

   - company ownership transfer
   - user delete protections
   - invoice/estimate exchange-rate validation
   - invoice/estimate send-failure state

2. Fix the highest-value localized bugs:

   - `CompaniesController::transferOwnership()`
   - `DeleteUserRequest` + `User::deleteUsers()`
   - `InvoicesRequest` + `EstimatesRequest` exchange-rate rules
   - invoice/estimate send-state consistency

3. Bundle the two factory fixes.

4. Only then decide whether to take the customer-delete/model-delete cleanup in the same pass.
   - It is implementable, but it still has the largest regression surface of the remaining app-code issues.

## Bottom line

The best next move is not another broad audit or another repeat of the March 26 controller fixes.

The best next move is a narrow, test-backed backend pass that:

- fixes company/user admin correctness
- closes the remaining invoice/estimate exchange-rate validation gap
- makes send-state behavior truthful on mail failure
- cleans up the two still-broken factories
- optionally takes the bounded delete-cascade cleanup if the first wave lands cleanly

That gives the highest risk reduction with the lowest chance of destabilizing the app.
