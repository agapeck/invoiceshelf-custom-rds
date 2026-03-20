# Response to remaining high-level review

Date: 2026-03-20

## Git direction (Jan 2026 onward)

- Repository activity shifted from point fixes into a hardening-and-correctness track, with emphasis on tenant boundaries, financial integrity, and concurrency safety.
- Delivery style became review-driven and traceable: each remediation pass is paired with focused documentation and verification notes.
- `.md` artifacts are now a first-class part of execution (review docs, implementation plans, optimization reports, handoff notes), used to document intent, risk, and validation state alongside code changes.

## Fixed in this pass

- **Policy active-company strictness + query fallback for PDF links**
  - Tightened policy checks to fail closed on active-company mismatches and improved list/query behavior used by PDF-related access paths so legitimate links resolve without weakening tenant isolation.
- **Payment deletion decimals**
  - Corrected amount recalculation/rounding behavior during payment delete flows to prevent decimal drift in invoice totals and balances.
- **Invoice sent flag consistency**
  - Normalized sent-status updates so invoice `sent` state remains accurate across send/view/update paths.
- **Serial lock hardening in estimate conversion + recurring**
  - Strengthened locking and transaction ordering for estimate conversion and recurring generation paths to reduce duplicate/contended document-number issuance.
- **Customer deletion cascade**
  - Hardened delete behavior so dependent records are handled consistently and safely, avoiding orphaned/partially detached data.
- **Cross-midnight appointment overlap**
  - Fixed overlap checks for appointments spanning date boundaries to correctly block conflicting slots across midnight.
- **Backup delete null-safety + route mismatch + frontend typo + restore clarity**
  - Added null-safe backup delete handling, aligned API routing and frontend calls, corrected UI text/typo issues, and clarified restore messaging/expectations.

## Files changed by area

- **Authorization/policy boundaries**
  - `app/Policies/AppointmentPolicy.php`
  - `app/Policies/CustomerPolicy.php`
  - `app/Policies/EstimatePolicy.php`
  - `app/Policies/InvoicePolicy.php`
  - `app/Policies/PaymentPolicy.php`
- **Core business logic (billing, serials, deletes, concurrency)**
  - `app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php`
  - `app/Http/Controllers/V1/Admin/Appointment/AppointmentsController.php`
  - `app/Models/Invoice.php`
  - `app/Models/Payment.php`
  - `app/Models/RecurringInvoice.php`
  - `app/Models/Customer.php`
- **Backup flow and routing**
  - `app/Http/Controllers/V1/Admin/Backup/BackupsController.php`
  - `routes/api.php`
- **Frontend/admin UX alignment**
  - `resources/scripts/admin/views/settings/BackupSetting.vue`
  - `resources/scripts/admin/views/invoices/View.vue`
  - `resources/scripts/admin/views/invoices/create/InvoiceCreate.vue`
  - `resources/scripts/admin/views/estimates/View.vue`
  - `resources/scripts/admin/views/estimates/create/EstimateCreate.vue`
  - `resources/scripts/admin/views/payments/View.vue`
  - `resources/scripts/admin/views/recurring-invoices/create/RecurringInvoiceCreate.vue`
  - `resources/scripts/admin/components/dropdowns/InvoiceIndexDropdown.vue`
  - `resources/scripts/admin/components/dropdowns/EstimateIndexDropdown.vue`
  - `resources/scripts/admin/components/dropdowns/PaymentIndexDropdown.vue`
  - `lang/en.json`

## Validation and testing outcomes

- PHP lint checks passed for modified backend files.
- Frontend build passed for updated Vue/admin surfaces.
- Backup route verification passed (delete/list flow and route alignment validated).
- Full feature-suite execution remains blocked by pre-existing local environment/seeding constraints unrelated to this change set.

## Regression tests updated

- `tests/Feature/Admin/InvoiceTest.php`
- `tests/Feature/Admin/PaymentTest.php`
- `tests/Feature/Hardening/SecurityBoundaryTest.php`
- `tests/Feature/MultiUserConcurrencyTest.php`
- `tests/Feature/RaceConditionTest.php`
- `tests/Unit/CustomerTest.php`

## Intentionally untouched (deployment/runtime host scope)

- No host-level deployment operations were changed in this pass.
- Scheduler/process manager wiring (cron/systemd/supervisor), production env/runtime tuning, and secret/mail transport handling remain operational tasks for deployment owners.
- Backup restore execution at host/infrastructure level remains outside this code pass; only API/UI clarity and safety checks were adjusted.
