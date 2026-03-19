# High-level code and app review 1

Updated on 2026-03-19 after verifying the current working tree against:

- `Fresh eyes review.md`
- the earlier version of this report
- `addressing high-level issues.md`
- current host/runtime state on this machine
- git history since 2025-11-01

Scope note:

- This is now a follow-up verification report, not the original first-pass audit.
- I re-checked the claimed fixes directly in code before rewriting this file.
- I did not run the full end-to-end test suite in this pass; conclusions below are based on direct code inspection plus limited runtime checks on the local host.

## Executive summary

The coding agent has made real progress.

Many of the highest-risk code issues from both prior review documents are now genuinely addressed: estimate conversion data loss, overpayment handling, same-day appointment race mitigation, recurring scheduler boot explosion, dashboard/customer-stats aggregation bottlenecks, PDF job safety controls, public token expiry/type checks, fail-closed company middleware, disk credential masking, and several runtime bugs.

This report is materially different from the earlier same-day version because several issues previously listed as still open are no longer accurate.

That said, the work is not finished. The biggest remaining risks are now narrower and more concentrated:

1. Active-company isolation is still looser than it should be for some policy-gated admin/customer flows.
2. Financial/data-integrity edge cases still remain, especially payment deletion math and deletion cascades.
3. Serial-number hardening is improved but not uniformly applied across every number-allocation path.
4. Host/runtime deployment is still the weakest area: scheduler not wired, `/api/cron` unusable at runtime, live app still `local`+debug, and local proxy trust still wildcard.

## Current status against prior review docs

### `Fresh eyes review.md` status

1. Fixed: estimate-to-invoice base-currency data loss and non-atomic conversion.
   - Verified in `app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php`.
2. Fixed: overpayments can no longer push invoice balances negative through the old request/model path.
   - Verified in `app/Http/Requests/PaymentRequest.php` and `app/Models/Payment.php`.
3. Fixed for the original same-day empty-slot race: appointment creation/update now uses a cache lock plus transactional overlap checks.
   - Verified in `app/Http/Controllers/V1/Admin/Appointment/AppointmentsController.php`.
   - Separate remaining issue: cross-midnight overlap detection is still weak; see remaining issues section.
4. Fixed for the original duplicate-trigger concern: recurring invoice generation now row-locks inside a transaction.
   - Verified in `app/Models/RecurringInvoice.php`.
   - Separate remaining issue: serial locking is still inconsistent inside recurring invoice creation.
5. Fixed: per-recurring-invoice scheduler closure registration was replaced by one generic recurring-invoice command.
   - Verified in `routes/console.php` and `app/Console/Commands/GenerateRecurringInvoices.php`.
6. Fixed: dashboard 36-query monthly aggregate pattern was replaced with grouped monthly sums.
   - Verified in `app/Http/Controllers/V1/Admin/Dashboard/DashboardController.php`.
7. Fixed: PDF jobs now have timeout/tries/backoff/failed logging.
   - Verified in `app/Jobs/GenerateInvoicePdfJob.php`, `app/Jobs/GenerateEstimatePdfJob.php`, and `app/Jobs/GeneratePaymentPdfJob.php`.
8. Fixed: static serial formats now fail fast instead of looping until a DB collision.
   - Verified in `app/Services/SerialNumberFormatter.php`.
9. Partially fixed: native customer deletion now has a deleting hook, but the cascade still uses relation mass deletes that can bypass child model events.
   - Verified in `app/Models/Customer.php`.

### Earlier `High-level code and app review 1.md` status

Clearly fixed:

- Broadcast channel auth tightened in `routes/channels.php`.
- Public customer token endpoints now enforce both mailable type and expiry in `app/Http/Controllers/V1/Customer/*PdfController.php` and `app/Models/EmailLog.php`.
- Company middleware and Bouncer scoping now fail closed in `app/Http/Middleware/CompanyMiddleware.php` and `app/Http/Middleware/ScopeBouncer.php`.
- User list and update/delete boundaries are much better aligned to active company context in `app/Http/Controllers/V1/Admin/Users/UsersController.php`, `app/Policies/UserPolicy.php`, and `app/Models/User.php`.
- Disk credential leakage is fixed at the API layer in `app/Http/Resources/FileDiskResource.php`.
- Payment invoice/customer mismatch protection is now present in `app/Http/Requests/PaymentRequest.php` and `app/Models/Payment.php`.
- Estimate conversion is now transactional and repopulates base/item/tax currency fields in `app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php`.
- PDF media cleanup and locale-restore bugs are fixed in `app/Traits/GeneratesPdfTrait.php`.
- Company settings cache invalidation is fixed in `app/Models/CompanySetting.php`.
- Customer expense count scope bug is fixed in `app/Http/Controllers/V1/Customer/Expense/ExpensesController.php`.
- Create-and-send controller argument mismatches are fixed in `app/Http/Controllers/V1/Admin/Invoice/InvoicesController.php` and `app/Http/Controllers/V1/Admin/Estimate/EstimatesController.php`.
- Backup disk selection now respects the requested disk context in `app/Http/Controllers/V1/Admin/Backup/BackupsController.php` and `app/Http/Controllers/V1/Admin/Backup/DownloadBackupController.php`.
- Scheduled backup timestamps are now per-disk in `app/Console/Commands/ScheduledS3Backup.php` and `app/Jobs/CreateBackupJob.php`.
- Queue `after_commit` is enabled for async drivers in `config/queue.php`.
- `.env.example` is safer now: production defaults, debug off, strict mode env, empty trusted proxies, and cron auth token key.

Improved but still not fully closed:

- Admin PDF hash endpoints now authorize, but the policies still rely on company membership rather than strict active-company scoping.
- Customer direct-record policy checks are better, but still not strictly tied to the current company header.
- Customer password reset is route/cache-scoped now, but the underlying reset broker table is still global.
- File-disk scoping is company-aware now, but global disks (`company_id = null`) are still intentionally shareable across companies.
- Serial-number hardening is much better for invoice/estimate/payment create flows, but not every flow uses the same company lock.
- Custom-field cleanup no longer has the old trait boot collision, but deletion cascades still use relation mass deletes in places.
- Proxy trust defaults are safer in `.env.example`, but the current local host still trusts `*`.

## Remaining verified issues

### P0/P1 code issues still open

- Admin PDF routes still authorize on “user belongs to the target company” rather than “record belongs to the current active company”.

  - See `app/Http/Controllers/V1/PDF/InvoicePdfController.php`, `app/Http/Controllers/V1/PDF/EstimatePdfController.php`, `app/Http/Controllers/V1/PDF/PaymentPdfController.php`, `app/Http/Controllers/V1/PDF/AppointmentPdfController.php`.
  - See policies in `app/Policies/InvoicePolicy.php`, `app/Policies/EstimatePolicy.php`, `app/Policies/PaymentPolicy.php`, and `app/Policies/AppointmentPolicy.php`.

- Customer direct-record policy checks still use membership in the target company, not the active header company.

  - See `app/Policies/CustomerPolicy.php`.

- Customer password reset is improved but not fully tenant-scoped at storage level.

  - `config/auth.php` still uses the shared `password_reset_tokens` table for `customers`.
  - The route/cache layer in `app/Http/Controllers/V1/Customer/Auth/ForgotPasswordController.php` and `app/Http/Controllers/V1/Customer/Auth/ResetPasswordController.php` reduces risk but does not change the global broker table.

- Payment deletion still corrupts decimal balances because it casts amounts to int when restoring invoice due amounts.

  - Verified in `app/Models/Payment.php`.

- Customer deletion still relies on relation mass deletes for several child models.

  - Verified in `app/Models/Customer.php`.
  - This is much better than the old state, but relation `->delete()` can still bypass child deleting hooks and leave dependent cleanup incomplete.

- Serial-number locking is still bypassed in at least two important paths:

  - estimate conversion in `app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php`
  - recurring invoice creation in `app/Models/RecurringInvoice.php`

- Invoice create-and-send still has a state consistency bug.

  - `app/Http/Requests/InvoicesRequest.php` pre-sets `status = SENT` on create.
  - `app/Models/Invoice.php` only sets `sent = true` when sending from `DRAFT`.
  - Result: newly created-and-sent invoices can remain `sent = false`.

- Backup delete still lacks a null-safe missing-path guard.

  - `app/Http/Controllers/V1/Admin/Backup/BackupsController.php` still calls `->first(...)->delete()` without checking for null.

- There is still no in-app restore flow alongside the backup UI.

- Same-day appointment race mitigation is in place, but long appointments crossing midnight can still evade overlap detection because the check only queries the proposed appointment day.
  - Verified in `app/Http/Controllers/V1/Admin/Appointment/AppointmentsController.php`.

### Lower-priority / cleanup issues still worth addressing

- Some resource-route/controller mismatches still appear to remain, especially backup `apiResource` usage versus missing `show`/`update` implementations.

- Invoice/estimate PDF job dispatch still happens directly from controllers.
  - With `after_commit = true` on async drivers this is much safer than before.
  - It is still less explicit than the payment model’s `->afterCommit()` dispatch pattern.

## Current host / deployment status

### Code-level deployment posture improved

- The recurring scheduler topology is much better now.

  - `routes/console.php` now schedules one `invoices:generate-recurring` command.
  - `app/Console/Commands/GenerateRecurringInvoices.php` processes recurring invoices in chunks.

- Cron token support exists in code/templates now.

  - `config/services.php`
  - `.env.example`

- Template defaults are safer.
  - `.env.example` now defaults to production/debug-off and exposes `DB_STRICT_MODE` and `CRON_JOB_AUTH_TOKEN`.

### Host/runtime issues still open on this machine

- The scheduler is still not wired on this host.

  - `php artisan schedule:list` shows scheduled tasks exist.
  - I still found no `schedule:run`, `schedule:work`, or `/api/cron` trigger under `/etc`.

- `/api/cron` is still unusable at runtime on this host.

  - Current `config('services.cron_job.auth_token')` resolves to `NULL`.

- The live app is still running in local/debug mode.

  - Verified via `php artisan about` and `.env`.

- The live host still trusts all proxies.

  - `.env` still has `TRUSTED_PROXIES="*"`.

- `APP_URL` is still `http://localhost:3000` on the local host.

- Mail is still not explicitly configured in the visible local `.env`.

- Sensitive operational artifacts still remain beside the repo.
  - including the DB recovery dump directory noted in the earlier report.

## Confidence notes

- I directly re-read the changed code before rewriting this report.
- I ran limited runtime checks on the host (`php artisan about`, `php artisan schedule:list`, config lookup, local env review).
- I did not independently rerun the full test suite.
- Targeted tests do exist in the working tree for several fixes, including:
  - `tests/Feature/Admin/PaymentTest.php`
  - `tests/Feature/Admin/EstimateTest.php`
  - `tests/Feature/Hardening/SecurityBoundaryTest.php`
  - `tests/Unit/SerialNumberFormatterTest.php`

## Recommended next verification order

1. Host/runtime

   - wire scheduler properly
   - set a real `CRON_JOB_AUTH_TOKEN`
   - move host to production-safe env flags
   - narrow trusted proxies
   - correct `APP_URL`
   - confirm mail transport

2. Data integrity

   - fix payment deletion math in `app/Models/Payment.php`
   - tighten customer deletion cascade behavior in `app/Models/Customer.php`
   - fix invoice create-and-send `sent` flag consistency

3. Tenant isolation

   - tighten policy checks from “belongs to any company” to “current active company” where appropriate

4. Numbering

   - apply the same company serial-lock pattern to estimate conversion and recurring invoice generation

5. Backups
   - make backup delete null-safe
   - decide explicitly whether restore support is out of scope or missing work

## Key files to check first

- `app/Models/Payment.php`
- `app/Models/Customer.php`
- `app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php`
- `app/Models/RecurringInvoice.php`
- `app/Policies/CustomerPolicy.php`
- `app/Policies/InvoicePolicy.php`
- `app/Policies/EstimatePolicy.php`
- `app/Policies/PaymentPolicy.php`
- `app/Policies/AppointmentPolicy.php`
- `app/Http/Controllers/V1/Admin/Backup/BackupsController.php`
- `config/auth.php`
- `routes/console.php`
- `.env`

## Bottom line

This codebase is in meaningfully better shape than it was when the first version of this report was written.

The biggest code-level wins are real.
The biggest remaining risks are now concentrated in policy strictness, deletion-cascade integrity, edge-case financial math, incomplete serial-lock coverage, and the still-unfinished host/deployment setup.

If this app is serving real patient/financial data, the next round should focus less on broad codebase triage and more on:

- finishing the host/runtime hardening
- tightening active-company boundaries
- cleaning up the last financial/deletion edge cases
