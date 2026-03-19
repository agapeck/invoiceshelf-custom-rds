# Addressing High-Level Issues (Session Handoff)

Date: 2026-03-19  
Context source docs: `Fresh eyes review.md`, `High-level code and app review 1.md`

## What has been addressed so far

### A) Critical financial integrity fixes
- Fixed estimate->invoice conversion data loss and made conversion atomic:
  - `app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php`
  - Corrected base-currency item/tax assignments, added transaction + row lock.
- Added overpayment protection at request + model layers:
  - `app/Http/Requests/PaymentRequest.php`
  - `app/Models/Payment.php`
  - `app/Models/Invoice.php`
- Added invoice/customer coupling validation for payments to prevent cross-customer invoice attachment.
- Hardened invoice due-amount math to prevent negative balance corruption paths.

### B) Concurrency and race-condition hardening
- Appointment empty-slot race mitigated with distributed locking + transactional overlap checks:
  - `app/Http/Controllers/V1/Admin/Appointment/AppointmentsController.php`
- Recurring invoice generation made lock-safe and transaction-safe:
  - `app/Models/RecurringInvoice.php`
- Sequence generation race reduced by serializing number generation per company:
  - `app/Models/Invoice.php`
  - `app/Models/Estimate.php`
  - `app/Models/Payment.php`
  - `app/Services/SerialNumberFormatter.php`

### C) Scheduler/performance bottleneck fixes
- Replaced per-recurring-invoice scheduler closure registration with one generic command:
  - `routes/console.php`
  - `app/Console/Commands/GenerateRecurringInvoices.php` (new)
- Reworked dashboard and customer stats 12-month loops from 36 query pattern to grouped monthly aggregates:
  - `app/Http/Controllers/V1/Admin/Dashboard/DashboardController.php`
  - `app/Http/Controllers/V1/Admin/Customer/CustomerStatsController.php`

### D) Tenant isolation and authorization fixes
- Broadcast channel authorization tightened:
  - `routes/channels.php`
- Added authorization checks to PDF hash endpoints:
  - `app/Http/Controllers/V1/PDF/*PdfController.php`
- Public customer token endpoints now enforce both mailable type and expiry for PDF + JSON fetch paths:
  - `app/Http/Controllers/V1/Customer/*PdfController.php`
  - `app/Models/EmailLog.php`
- Company context now fails closed instead of silently defaulting to first company:
  - `app/Http/Middleware/CompanyMiddleware.php`
  - `app/Http/Middleware/ScopeBouncer.php`
  - `app/Http/Requests/AppointmentRequest.php`
- Customer and user policy checks tightened for active-company boundaries:
  - `app/Policies/CustomerPolicy.php`
  - `app/Policies/UserPolicy.php`
  - `app/Http/Controllers/V1/Admin/Users/UsersController.php`
  - `app/Models/User.php`

### E) Storage/backup isolation and reliability fixes
- File disk handling now supports company context and scoped default resolution:
  - `app/Models/FileDisk.php`
  - `app/Providers/AppConfigProvider.php`
  - `app/Models/Company.php`
  - `app/Traits/GeneratesPdfTrait.php`
- Disk settings endpoints scoped and protected; disk credentials masked in API response:
  - `app/Http/Controllers/V1/Admin/Settings/DiskController.php`
  - `app/Http/Resources/FileDiskResource.php`
  - `app/Http/Middleware/ConfigMiddleware.php`
- Backup list/create/download/delete now validate disk context before operations:
  - `app/Http/Controllers/V1/Admin/Backup/BackupsController.php`
  - `app/Http/Controllers/V1/Admin/Backup/DownloadBackupController.php`
- Scheduled backup cadence changed from global timestamp to per-disk timestamp:
  - `app/Console/Commands/ScheduledS3Backup.php`
  - `app/Jobs/CreateBackupJob.php`

### F) Queue/PDF robustness and runtime bug fixes
- Added queue-job safety controls for PDF generation (`timeout`, `tries`, `backoff`, `failed` logging):
  - `app/Jobs/GenerateInvoicePdfJob.php`
  - `app/Jobs/GenerateEstimatePdfJob.php`
  - `app/Jobs/GeneratePaymentPdfJob.php`
- Fixed payment PDF dispatch timing (after commit) and duplicate-dispatch behavior on trivial updates:
  - `app/Models/Payment.php`
- Fixed PDF media cleanup bug and locale restore bug:
  - `app/Traits/GeneratesPdfTrait.php`
- Fixed create-and-send controller argument mismatch:
  - `app/Http/Controllers/V1/Admin/Invoice/InvoicesController.php`
  - `app/Http/Controllers/V1/Admin/Estimate/EstimatesController.php`
- Fixed customer expense count wrong scope:
  - `app/Http/Controllers/V1/Customer/Expense/ExpensesController.php`
- Fixed custom-field trait boot collision causing orphan cleanup misses:
  - `app/Traits/HasCustomFieldsTrait.php`
- Added company settings cache invalidation fix:
  - `app/Models/CompanySetting.php`

### G) Security posture/config hardening
- Proxy trust now safer by default (no wildcard in `.env.example` by default):
  - `app/Http/Middleware/TrustProxies.php`
  - `.env.example`
- Added cron auth token config wiring:
  - `config/services.php`
  - `.env.example`
- MySQL strict mode made environment-driven and default-on:
  - `config/database.php`
  - `.env.example`
- Queue `after_commit` enabled for async drivers:
  - `config/queue.php`

## Tests added/updated in this session
- `tests/Feature/Admin/PaymentTest.php`
  - overpayment rejection
  - invoice/customer mismatch rejection
- `tests/Feature/Admin/EstimateTest.php`
  - base-currency fields preserved on estimate conversion
- `tests/Feature/Hardening/SecurityBoundaryTest.php` (new)
  - customer token expiry enforcement on JSON path
  - customer blocked from other customer invoice PDF hash
  - disk listing excludes foreign-company disk
- `tests/Unit/SerialNumberFormatterTest.php`
  - static format collision no-progress failure

## Remaining gaps / next-session priorities
- Complete full feature+unit test run end-to-end (execution was started but not fully finalized in this session).
- Re-verify any host-level deployment concerns separately from app code:
  - scheduler wiring (`schedule:run` / systemd / cron),
  - production-safe env flags (`APP_ENV`, `APP_DEBUG`),
  - mail transport and secrets handling.
- Confirm whether backup restore API/UI flow is still intentionally out-of-scope or needs implementation (review flagged missing restore flow).
- Review lower-priority route/controller signature mismatches flagged in high-level review and either align routes or add explicit `only(...)` restrictions.

## Current state summary
- Most P0/P1 issues from both review docs have code-level fixes in working tree.
- Core focus this session was correctness + tenant boundaries + race-condition hardening + query/load-path optimization.
- Final verification pass (full tests + runtime smoke in host context) is still required before declaring completion.
