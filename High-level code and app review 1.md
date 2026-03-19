# High-level code and app review 1

Independent review completed on 2026-03-19.

Scope:

- Re-read `Fresh eyes review.md` first, then re-audited the current codebase independently.
- Read git history from 2025-11-01 onward.
- Checked the local host/runtime setup actually serving this app.
- Did not change application code; findings below are limited to issues I could verify from current code, current config, current git history, and current host state.

## Executive summary

The earlier fresh-eyes review did not overstate the risk; if anything, it understated it. All of its listed issues are still present, and there are additional app-wide problems in four clusters:

1. Tenant isolation and privacy boundaries are still incomplete.
2. Financial/document integrity still has multiple corruption paths.
3. Scheduler/backup/PDF infrastructure is brittle in both code and deployment.
4. A few controller/runtime bugs are severe enough to fail immediately when the wrong path is hit.

The local host is also not fully wired for this app's background work: the queue worker is running, but the Laravel scheduler is not wired through cron/systemd, and the `/api/cron` fallback is currently unusable because its auth token config is null.

## Git history read: 2025-11-01 onward

The history shows repeated reactive hardening in the same areas now failing again or still only partially fixed:

- `06502ae1` / `9ff060cf` / `7ba222cb` / `8d419e6f`: document-number collision, retry, transaction, and uniqueness hardening.
- `39274c6c`: appointment/customer concurrency hardening claimed on 2025-12-01.
- `9205b7b6` / `52f6f7a7` / `2417b0ff`: secret cleanup and file-disk credential encryption work.
- `8fee1679` / `c7a22f75` / `f5144b7e` / `7e51fcbe`: backup and R2/S3 work.
- `00ba8789` / `c6383e9a` / `01ee113e` / `8a759aef` / `531fc39b`: recent tenant-scoping, query-hardening, eager-loading, and performance work.

History takeaway: this codebase has had many targeted fixes, but the same risk surfaces keep reopening - especially tenant scoping, document sequencing, recurring/scheduled work, and storage/backup behavior.

## Fresh eyes review re-verification

Every item from `Fresh eyes review.md` remains present in current code:

1. Estimate-to-invoice conversion still drops item base-currency fields in `app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php:94` and `app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php:100`.
2. Overpayments can still drive invoice balances negative via `app/Http/Requests/PaymentRequest.php:38`, `app/Models/Payment.php:194`, and `app/Models/Invoice.php:748`.
3. Appointment empty-slot double-booking race is still present in `app/Http/Controllers/V1/Admin/Appointment/AppointmentsController.php:49` and `app/Http/Controllers/V1/Admin/Appointment/AppointmentsController.php:79`.
4. Recurring invoice generation still lacks transaction/row locking in `app/Models/RecurringInvoice.php:295`, `app/Models/RecurringInvoice.php:305`, and `app/Models/RecurringInvoice.php:312`.
5. Scheduler still loads/registers every active recurring invoice individually in `routes/console.php:27` and `routes/console.php:31`.
6. Dashboard still runs 36 monthly aggregate queries in `app/Http/Controllers/V1/Admin/Dashboard/DashboardController.php:61`.
7. PDF jobs still have no per-job timeout/tries/failed handling in `app/Jobs/GenerateInvoicePdfJob.php:11`, `app/Jobs/GenerateEstimatePdfJob.php:11`, and `app/Jobs/GeneratePaymentPdfJob.php:11`.
8. Serial formatter still has the no-progress static-format retry bug in `app/Services/SerialNumberFormatter.php:112`.
9. Native customer deletion still bypasses cascade cleanup because cleanup only exists in `app/Models/Customer.php:159` and there is no deleting hook on `app/Models/Customer.php`.

## Additional verified issues

### Critical: security, privacy, and tenant isolation

- Broadcast channel auth is effectively disabled in `routes/channels.php:5`, `routes/channels.php:9`, and `routes/channels.php:13`. Any authenticated broadcast client can subscribe to arbitrary `conversation.*`, `user.*`, and `company.*` channels. Current host note: `php artisan about` shows broadcasting is `null`, so this is latent until broadcasting is enabled - but the code is unsafe as-is.

- Logged-in users can fetch other users' PDFs if they know a `unique_hash`. The only gate is `app/Http/Middleware/PdfMiddleware.php:20`, and the handlers in `app/Http/Controllers/V1/PDF/InvoicePdfController.php:16`, `app/Http/Controllers/V1/PDF/EstimatePdfController.php:16`, `app/Http/Controllers/V1/PDF/PaymentPdfController.php:16`, and `app/Http/Controllers/V1/PDF/AppointmentPdfController.php:15` do not authorize ownership/company membership before streaming.

- Public customer document-token JSON endpoints do not enforce expiry, and invoice/estimate token handlers do not verify `mailable_type`. See `routes/web.php:114`, `app/Http/Controllers/V1/Customer/InvoicePdfController.php:18`, `app/Http/Controllers/V1/Customer/InvoicePdfController.php:57`, `app/Http/Controllers/V1/Customer/EstimatePdfController.php:18`, `app/Http/Controllers/V1/Customer/EstimatePdfController.php:49`, and `app/Http/Controllers/V1/Customer/PaymentPdfController.php:22`.

- Customer password reset is not company-scoped even though duplicate customer emails across companies are explicitly allowed. The public route is company-slug based in `routes/api.php:501`, but both `app/Http/Controllers/V1/Customer/Auth/ForgotPasswordController.php:25` and `app/Http/Controllers/V1/Customer/Auth/ResetPasswordController.php:35` use the shared `customers` broker/table from `config/auth.php:92`.

- Direct customer record routes are not policy-scoped to company membership. `app/Policies/CustomerPolicy.php:33`, `app/Policies/CustomerPolicy.php:61`, and `app/Policies/CustomerPolicy.php:75` only defer to Bouncer and never check `$user->hasCompany($customer->company_id)`. Routes/controllers like `app/Http/Controllers/V1/Admin/Customer/CustomersController.php:72`, `app/Http/Controllers/V1/Admin/Customer/CustomerStatsController.php:22`, and `app/Http/Controllers/V1/Admin/Customer/UpdatePatientInfoController.php:15` accept direct route-bound `Customer $customer` objects.

- User management is still effectively global from the perspective of an owner of the currently selected company. `app/Policies/UserPolicy.php:37`, `app/Policies/UserPolicy.php:65`, and `app/Policies/UserPolicy.php:79` only check `isOwner()`, while `app/Http/Controllers/V1/Admin/Users/UsersController.php:27` lists users globally and `app/Models/User.php:378` syncs company memberships globally.

- File-disk management leaks decrypted credentials and is not company-scoped. `app/Http/Controllers/V1/Admin/Settings/DiskController.php:22` lists all disks without company filtering, `app/Http/Resources/FileDiskResource.php:22` returns `credentials`, and `app/Models/FileDisk.php:39` transparently decrypts them on read. Update/delete route-model binding in `app/Http/Controllers/V1/Admin/Settings/DiskController.php:49` and `app/Http/Controllers/V1/Admin/Settings/DiskController.php:154` is also unscoped.

- Company context silently falls back to the user's first company instead of failing closed. See `app/Http/Middleware/CompanyMiddleware.php:22` and `app/Http/Middleware/ScopeBouncer.php:35`. This turns missing/invalid company headers into writes against whichever company is first in the relation, rather than rejecting the request.

- Proxy trust is fully open by default. `app/Http/Middleware/TrustProxies.php:36` trusts `env('TRUSTED_PROXIES', '*')`, and both `.env.example:19` and the local `.env:19` set it to `*`. If this app is exposed behind any untrusted proxy chain, forwarded IP/host/scheme data becomes too easy to spoof.

### Critical: financial integrity and destructive data bugs

- A payment can still be attached to the wrong customer's invoice. `app/Http/Requests/PaymentRequest.php:29` validates `customer_id`, and `app/Http/Requests/PaymentRequest.php:50` validates `invoice_id`, but there is no rule tying them together. `app/Models/Payment.php:189` loads the invoice only by company+invoice ID and `app/Models/Payment.php:198` then stores the payment using the request's customer payload.

- Estimate-to-invoice conversion is not atomic. `app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php:55` creates the invoice, `app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php:91` and `app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php:113` create items/taxes, and `app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php:125` mutates the source estimate - all without a transaction.

- Sequence counters are still race-prone even after document-number uniqueness hardening. `app/Services/SerialNumberFormatter.php:150` and `app/Services/SerialNumberFormatter.php:169` use `lockForUpdate()`, but callers compute sequence values before entering their write transaction in `app/Models/Invoice.php:343`, `app/Models/Payment.php:175`, and `app/Models/Estimate.php:243`. The database uniqueness added in `database/migrations/2025_11_27_050736_add_unique_constraints_to_document_numbers.php:18` only protects human-readable document numbers, not `sequence_number` / `customer_sequence_number`.

- Customer deletion cleanup is incomplete even when the custom helper is used. `app/Models/Customer.php:113` defines appointments, but `app/Models/Customer.php:159` never deletes them; the appointment FK cascade in `database/migrations/2025_11_15_190401_create_appointments_table.php:49` only helps on hard delete. `app/Models/Customer.php:197` also deletes recurring invoice items but not recurring invoice taxes, and `database/migrations/2021_10_06_100539_add_recurring_invoice_id_to_taxes_table.php:16` does not cascade that FK.

- Payment custom fields can be orphaned on delete. The trait cleanup is registered in `app/Traits/HasCustomFieldsTrait.php:15`, but `app/Models/Payment.php:67` defines its own `booted()` and therefore overrides the trait boot method for payments.

### High: scheduler, backup, storage, and background-processing faults

- Payment PDF generation is dispatched twice on create and dispatched before transaction commit. `app/Models/Payment.php:69` dispatches on `created`, `app/Models/Payment.php:74` dispatches on `updated`, and `app/Models/Payment.php:198` then `app/Models/Payment.php:205` trigger both inside the same DB transaction. Queue connections explicitly use `after_commit => false` in `config/queue.php:43`, `config/queue.php:52`, `config/queue.php:63`, and `config/queue.php:72`.

- PDF regeneration deletes the wrong media collection, so old PDFs accumulate instead of being replaced. `app/Traits/GeneratesPdfTrait.php:85` calls `clearMediaCollection($this->id)` instead of clearing the actual collection name used at `app/Traits/GeneratesPdfTrait.php:101`.

- PDF generation mutates process-global locale and does not restore it. `app/Traits/GeneratesPdfTrait.php:79` sets `App::setLocale($locale)` and never restores the previous locale. This matters in long-lived queue workers.

- File-disk defaults are still global even though the schema now records `company_id`. The migration `database/migrations/2026_03_19_145000_add_company_id_to_file_disks_table.php:13` makes disks company-owned, but reset/default lookup logic still ignores company in `app/Models/FileDisk.php:216`, `app/Traits/GeneratesPdfTrait.php:89`, `app/Providers/AppConfigProvider.php:166`, and `app/Models/Company.php:41`. One company can silently change the storage target for another company's PDFs/logos/backups.

- Backup listing/download/delete paths use the process-global default disk instead of the requested disk. See `app/Http/Controllers/V1/Admin/Backup/BackupsController.php:30`, `app/Http/Controllers/V1/Admin/Backup/BackupsController.php:86`, and `app/Http/Controllers/V1/Admin/Backup/DownloadBackupController.php:24`. `file_disk_id` is only used in the cache key at `app/Http/Controllers/V1/Admin/Backup/BackupsController.php:32`.

- Scheduled backup cadence is global, not per disk/company. `app/Console/Commands/ScheduledS3Backup.php:45` and `app/Jobs/CreateBackupJob.php:98` use one shared `last_s3_backup.txt`. A success on one disk suppresses retries for other disks until the global interval expires.

- Company settings cache invalidation is wrong. Values are cached under `"{$company_id}.{$key}"` in `app/Models/CompanySetting.php:64`, but updates only unset `static::$settingsCache[$company_id]` in `app/Models/CompanySetting.php:43`. In long-lived processes, settings can stay stale.

- There is no in-app restore flow alongside the backup UI. Routes exist for list/create/download in `routes/api.php:355` and `routes/api.php:359`, but there is no restore controller/route under `app/Http/Controllers/V1/Admin/Backup`.

### High: runtime failures / reckless bugs

- The create-and-send invoice path is broken by argument mismatch. `app/Http/Controllers/V1/Admin/Invoice/InvoicesController.php:50` calls `$invoice->send($request->subject, $request->body)`, but the model only accepts one parameter in `app/Models/Invoice.php:539`.

- The create-and-send estimate path has the same bug. `app/Http/Controllers/V1/Admin/Estimate/EstimatesController.php:53` calls `$estimate->send($request->title, $request->body)`, but `app/Models/Estimate.php:429` only accepts one parameter.

- The customer expense portal count query uses the wrong column and will query a nonexistent `customer` field. `app/Http/Controllers/V1/Customer/Expense/ExpensesController.php:43` calls `Expense::whereCustomer(...)`, but the model only defines `scopeWhereUser()` in `app/Models/Expense.php:150`. Verified SQL on this host resolves to `where customer = ?`.

### Medium: performance and scale bottlenecks

- The customer stats endpoint duplicates the dashboard's monthly N+1 aggregate pattern. `app/Http/Controllers/V1/Admin/Customer/CustomerStatsController.php:55` loops 12 months and runs 3 sums per iteration, producing the same 36-query pattern already seen in the main dashboard.

- `routes/console.php:27` still builds the recurring invoice schedule in PHP memory at boot time with one closure per active row. This is both a scheduler boot-time cost and an operational scaling ceiling.

- The app is still running MySQL with non-strict mode in `config/database.php:59`. That is not an exploit by itself, but it does widen the blast radius of bad writes and truncation bugs in a system already carrying financial-data issues.

- Resource route definitions do not match controller implementations in a few places. Example: `routes/api.php:254` uses `Route::resource('customers', ...)` even though `app/Http/Controllers/V1/Admin/Customer/CustomersController.php` does not implement `create`, `edit`, or `destroy`; `routes/api.php:355` uses `Route::apiResource('backups', ...)` even though `app/Http/Controllers/V1/Admin/Backup/BackupsController.php` lacks `show` and `update`. These are lower-impact than the issues above, but they are still avoidable footguns.

## Local host / deployment review (verified on this system)

### Current host state

- The app is live on nginx at `0.0.0.0:3000` / `[::]:3000`. `curl -I http://127.0.0.1:3000` returned `200 OK`.
- Active services: nginx, php8.3-fpm, redis-server, mysql, cron, and `invoiceshelf-queue.service`.
- Docker is not installed/active on this host, so the repo's Docker files are not the active deployment path here.
- The queue worker is active via `/etc/systemd/system/invoiceshelf-queue.service` and runs `php artisan queue:work redis --sleep=1 --tries=3 --timeout=120 --max-time=3600 --queue=default`.
- `php artisan about` reports: `Environment=local`, `Debug Mode=ENABLED`, `Database=mysql`, `Queue=redis`, `Session=redis`, `Mail=mail`, `public/storage=LINKED`.

### Local host issues

- The scheduler is not wired. `php artisan schedule:list` shows scheduled work exists, but there is no matching `schedule:run`, `schedule:work`, or `/api/cron` trigger in user crontab, `/etc/cron.d`, or `/etc/systemd/system`. Result: invoice/estimate status checks, recurring invoices, scheduled backups, and monthly reminders will not run automatically on this host.

- The `/api/cron` fallback is currently unusable. `config('services.cron_job.auth_token')` resolves to `null` on this host, `config/services.php` has no `cron_job` block, and both `curl http://127.0.0.1:3000/api/cron` and the same call with `x-authorization-token: test` return `401`.

- The host is serving a debug/local app on a network-bound socket. Local `.env` sets `APP_ENV=local` and `APP_DEBUG=true` while nginx listens on `0.0.0.0:3000`. Any exception path on a reachable network could expose debug information.

- The local `.env` still contains live plaintext runtime secrets (for example `APP_KEY` and DB credentials), and the repo root currently contains a raw recovery dump directory: `DB recovery - to be deleted after successful db import.` with both a zip and SQL dump. These are operationally sensitive artifacts, even if ignored by git.

- `APP_URL` is still `http://localhost:3000` in local `.env`, while the server is listening on all interfaces. If this host is accessed by IP/domain instead of localhost, generated links, reset URLs, or mail URLs will be wrong.

- Mail is not explicitly configured in local `.env`; the app falls back to the `mail` transport from `config/mail.php:17`, and this host does not show an obvious repo-level mail configuration. This is a deployment risk for password resets, invoice mail, estimate mail, and backup notifications.

### Deployment-file drift and traps inside the repo

- The active nginx site is `/etc/nginx/sites-enabled/invoiceshelf` pointing to `/home/hp/invoiceshelf-custom-rds/public` on port 3000, but the repo sample `invoiceshelf-nginx.conf:7` still points at `/home/hp/invoiceshelf-custom/public` on port 8000. That config is stale for this repo.

- `docker/custom-production/docker-compose.yml:21` and `docker/custom-production/docker-compose.yml:22` still contain fallback DB/root passwords.

- `docker/custom-production/docker-compose.yml` has a queue worker but no scheduler service.

- `docker/production/docker-compose.mysql.yml:34` and `docker/production/docker-compose.mysql.yml:35` still ship `APP_ENV=local` and `APP_DEBUG=true`, and `docker/production/docker-compose.mysql.yml:14` allows an empty MariaDB root password.

- `.env.example:26` still defaults `QUEUE_CONNECTION=sync`, which is dangerous in any deployment that expects background PDF/backup work but has not explicitly enabled a worker.

## What the coding agent should check first

### P0: security / privacy / irreversible corruption

1. `routes/channels.php`
2. `routes/web.php`
3. `app/Http/Middleware/PdfMiddleware.php`
4. `app/Http/Controllers/V1/PDF/InvoicePdfController.php`
5. `app/Http/Controllers/V1/PDF/EstimatePdfController.php`
6. `app/Http/Controllers/V1/PDF/PaymentPdfController.php`
7. `app/Http/Controllers/V1/PDF/AppointmentPdfController.php`
8. `app/Http/Controllers/V1/Customer/InvoicePdfController.php`
9. `app/Http/Controllers/V1/Customer/EstimatePdfController.php`
10. `app/Http/Controllers/V1/Customer/PaymentPdfController.php`
11. `app/Policies/CustomerPolicy.php`
12. `app/Policies/UserPolicy.php`
13. `app/Http/Controllers/V1/Admin/Settings/DiskController.php`
14. `app/Models/FileDisk.php`
15. `app/Http/Requests/PaymentRequest.php`
16. `app/Models/Payment.php`
17. `app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php`
18. `app/Models/Customer.php`

### P1: scheduler / backup / runtime correctness

1. `routes/console.php`
2. `app/Console/Commands/ScheduledS3Backup.php`
3. `app/Jobs/CreateBackupJob.php`
4. `app/Traits/GeneratesPdfTrait.php`
5. `app/Models/CompanySetting.php`
6. `app/Http/Controllers/V1/Admin/Invoice/InvoicesController.php`
7. `app/Http/Controllers/V1/Admin/Estimate/EstimatesController.php`
8. `config/queue.php`
9. `config/services.php`
10. local host wiring for `schedule:run`

### P2: scale / performance

1. `app/Http/Controllers/V1/Admin/Dashboard/DashboardController.php`
2. `app/Http/Controllers/V1/Admin/Customer/CustomerStatsController.php`
3. `app/Models/RecurringInvoice.php`
4. `app/Services/SerialNumberFormatter.php`

## Bottom line

The codebase is not in a "single fatal flaw" state; it is in a "many partially hardened systems stacked on top of each other" state. The biggest real risks right now are:

- cross-tenant data access
- document/payment data corruption
- storage/backup misrouting across companies
- scheduled work not actually running on this host
- runtime failures on send/create paths that look normal from the UI/API contract

If this is going to production or serving real patient/financial data, the P0 and local-host scheduler/debug findings should be treated as first-order work, not cleanup.
