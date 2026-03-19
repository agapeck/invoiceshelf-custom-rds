# Speed Optimization Plan — Remaining Work (M8 partial → M13)

## Audit Summary

After deep-reading the git history and comparing every file in [`vps_commits.md`](vps_commits.md) against the current codebase:

**Fully completed:** M1, M2, M4, M5, M6, M7  
**Partially completed:** M3 (Invoice model missed), M8 (3 items remain)  
**Not started:** M9, M10, M11, M12, M13

---

## Detailed Changes Per Milestone

### Fixes for M3 & M8 (Incomplete Earlier Work)

**M3-fix: Invoice model eager-loads** — 2 locations in [`app/Models/Invoice.php`](app/Models/Invoice.php)

Both `createInvoice` and `updateInvoice` still use partial eager-loading:
```php
// CURRENT (line ~386, ~494)
Invoice::with(['items', 'items.fields', 'items.fields.customField', 'customer', 'taxes'])

// TARGET — match VPS diff
Invoice::with([
    'items', 'items.fields', 'items.fields.customField',
    'customer.currency', 'taxes', 'creator', 'assignedTo',
    'fields', 'company', 'currency',
])
```

**M8-fix: [`AppointmentRequest`](app/Http/Requests/AppointmentRequest.php:27)** — line 27 uses `$this->input('company_id')` but VPS uses `$this->header('company')`. Change to match.

**M8-fix: [`InvoicesRequest`](app/Http/Requests/InvoicesRequest.php:94) assigned_to_id** — currently uses unscoped `User::find($value)`, needs company scoping:
```php
// TARGET
$user = User::where('id', $value)
    ->whereHas('companies', fn($q) => $q->where('company_id', $this->header('company')))
    ->first();
if (! $user) { $fail('Selected user is not part of this company.'); return; }
if (! $user->isA('dentist')) { $fail(__('must_be_dentist')); }
```

**M8-fix: [`InvoicesRequest`](app/Http/Requests/InvoicesRequest.php:160) patient snapshot** — [`getInvoicePayload()`](app/Http/Requests/InvoicesRequest.php:133) still uses `$customer->age` etc. without null-safe operator. Change all patient snapshot fields to use `$customer?->` syntax.

---

### M9: Delete Request Scoping (7 files)

Scope all bulk-delete ID validation rules to the current company. Pattern:

```php
// BEFORE
Rule::exists('TABLE', 'id'),

// AFTER
Rule::exists('TABLE', 'id')
    ->where('company_id', $this->header('company'))
    ->whereNull('deleted_at'),  // except items (no soft deletes)
```

| File | Table | Notes |
|---|---|---|
| [`DeleteCustomersRequest`](app/Http/Requests/DeleteCustomersRequest.php:29) | customers | + whereNull deleted_at |
| [`DeleteEstimatesRequest`](app/Http/Requests/DeleteEstimatesRequest.php:29) | estimates | + whereNull deleted_at |
| [`DeleteExpensesRequest`](app/Http/Requests/DeleteExpensesRequest.php:29) | expenses | + whereNull deleted_at |
| [`DeleteInvoiceRequest`](app/Http/Requests/DeleteInvoiceRequest.php:31) | invoices | + whereNull deleted_at |
| [`DeleteItemsRequest`](app/Http/Requests/DeleteItemsRequest.php:31) | items | No whereNull |
| [`DeletePaymentsRequest`](app/Http/Requests/DeletePaymentsRequest.php:29) | payments | + whereNull deleted_at |
| [`DeleteUserRequest`](app/Http/Requests/DeleteUserRequest.php:29) | user_company, user_id | Different table + no deleted_at |

**M9 model-level delete scoping** — defense-in-depth:

| Model Method | Change |
|---|---|
| [`Invoice::deleteInvoices()`](app/Models/Invoice.php:792) | Add `$companyId` param, batch query with `whereIn` |
| [`User::deleteUsers()`](app/Models/User.php:410) | Add `$companyId` param, batch query with `whereHas('companies')` |
| [`RecurringInvoice::deleteRecurringInvoice()`](app/Models/RecurringInvoice.php:426) | Add `$companyId` param, batch query with `whereIn` |

---

### M10: Utility Hardening (6 files)

| File | Change |
|---|---|
| [`RelationNotExist`](app/Rules/RelationNotExist.php:32) | Add `use Schema;`, scope query by company_id if column exists, null-guard record |
| [`HasCustomFieldsTrait`](app/Traits/HasCustomFieldsTrait.php:30) | Both `addCustomFields` and `updateCustomFields`: `CustomField::find()` → company-scoped query + null guard |
| [`SerialNumberFormatter`](app/Services/SerialNumberFormatter.php:46) | `setModelObject`: scope `$this->model::find()` by company; `setCustomer`: scope `Customer::find()` by company + handle null |
| [`ConfigMiddleware`](app/Http/Middleware/ConfigMiddleware.php:22) | Require `company` header, scope `FileDisk::find()` to company_id |
| [`PaymentMethod::getSettings()`](app/Models/PaymentMethod.php:105) | Scope to company via request header + null-safe access |

**M10 model-level Invoice/Customer scoping in Payment and RecurringInvoice:**

| Location | Change |
|---|---|
| [`Payment::createPayment()`](app/Models/Payment.php:189) | `Invoice::find()` → `Invoice::where('company_id', ...)->find()` + null guard |
| [`Payment::deletePayments()`](app/Models/Payment.php:336) | `Invoice::find($payment->invoice_id)` → `Invoice::where('company_id', $payment->company_id)->find()` |
| [`RecurringInvoice::generateInvoice()`](app/Models/RecurringInvoice.php:345) | `Customer::find()` → `Customer::where('company_id', $this->company_id)->find()` + null-safe |

---

### M11: PDF Controller Scoping (3 files)

| File | Change |
|---|---|
| [`EstimatePdfController`](app/Http/Controllers/V1/Customer/EstimatePdfController.php:31) | `Customer::find()` → company-scoped + null guard; `getEstimate()` add full eager-load |
| [`InvoicePdfController`](app/Http/Controllers/V1/Customer/InvoicePdfController.php:32) | `Customer::find()` → company-scoped + null guard |
| [`PaymentPdfController`](app/Http/Controllers/V1/Customer/PaymentPdfController.php:24) | `Payment::find()` → `Payment::with([...])->find()` with full eager-load |

---

### M12: RequestTimingLogger (2 files)

1. Create new file [`app/Http/Middleware/RequestTimingLogger.php`](app/Http/Middleware/RequestTimingLogger.php) — logs requests ≥50ms to `storage/logs/royal-timing.log`
2. Register in [`bootstrap/app.php`](bootstrap/app.php:40) — add `require_once` and `appendToGroup('api', ...)`

---

### M13: Documentation (2 files)

1. Create [`RDS_OPTIMIZATIONS.md`](RDS_OPTIMIZATIONS.md) — performance notes covering DB tuning, Redis/cache, PHP-FPM, observability
2. Update [`INSTALLATION_GUIDE.md`](INSTALLATION_GUIDE.md) — add Cloud/VPS Production Notes section before Troubleshooting

---

## Execution Order

Each milestone should be committed separately for easy rollback:

1. **Commit: M3-fix + M8-fix** — Complete the incomplete earlier milestones
2. **Commit: M9** — All 7 delete request files + 3 model delete methods
3. **Commit: M10** — All 6 utility files + 3 model method changes
4. **Commit: M11** — 3 PDF controller files
5. **Commit: M12** — RequestTimingLogger middleware + bootstrap registration
6. **Commit: M13** — RDS_OPTIMIZATIONS.md + INSTALLATION_GUIDE.md update

## Verification

App is not installed locally — verification is code-review only. Each diff is derived directly from [`vps_commits.md`](vps_commits.md) and confirmed against current file state.
