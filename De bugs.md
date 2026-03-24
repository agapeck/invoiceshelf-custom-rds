# De bugs.md - Additional Verified Bugs (NEW - Not Previously Reported)

**Report Date:** March 25, 2026  
**Repository Analyzed:** invoiceshelf-custom-rds (main branch)  
**Scope:** NEW bugs not reported in:
- Almost slipped through bugs.md
- Grok+GLM bug report.md
- Performance Optimization Report.md
- Grok bugreport 2.md
- Bugssss.md

**Note:** This report contains ONLY issues NOT covered in any previous report.

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 1 |
| High | 2 |
| Medium | 2 |
| **Total** | **5** |

---

## CRITICAL SEVERITY

### Bug 1: Customer Portal Middleware - Null User Access Crash
**Location:** `app/Http/Middleware/CustomerPortalMiddleware.php` (lines 20-26)  
**Code:**
```php
public function handle(Request $request, Closure $next): Response
{
    $user = Auth::guard('customer')->user();

    if (! $user->enable_portal) {  // ← CRASH if $user is null
        Auth::guard('customer')->logout();
        return response('Unauthorized.', 401);
    }

    return $next($request);
}
```
**Problem:** If `$user` is `null` (customer not logged in, or session expired/invalid), accessing `$user->enable_portal` throws a **PHP Error**: "Call to a member function enable_portal() on null". This crashes the entire customer portal for any unauthenticated request that passes through this middleware.

**Verification:** Direct file read confirms:
1. Line 20: `$user = Auth::guard('customer')->user();` - can return `null`
2. Line 22: `if (! $user->enable_portal)` - accesses property without null check

**Severity:** Critical - Application crash for unauthenticated requests  
**Fix:**
```php
if (!$user || !$user->enable_portal) {
    Auth::guard('customer')->logout();
    return response('Unauthorized.', 401);
}
```

---

## HIGH SEVERITY

### Bug 2: Bulk Exchange Rate - No Validation on User-Provided Exchange Rates
**Location:** `app/Http/Requests/BulkExchangeRateRequest.php` (lines 30-32)  
**Code:**
```php
'currencies.*.exchange_rate' => [
    'required',
    // Missing: 'numeric', 'min:0'
],
```
**Problem:** The `exchange_rate` in bulk update requests lacks:
- `'numeric'` validation - allows non-numeric input
- `'min:0'` bounds - allows negative exchange rates
- Maximum value limits - allows overflow values

This is a **mass data corruption vulnerability** because the `BulkExchangeRateController` bulk-updates **ALL existing invoices, estimates, payments, and taxes** with user-supplied exchange rates:

```php
// BulkExchangeRateController.php - lines 34-41
$invoice->update([
    'exchange_rate' => $currency['exchange_rate'],  // User input, unvalidated
    'base_discount_val' => $invoice->sub_total * $currency['exchange_rate'],
    'base_sub_total' => $invoice->sub_total * $currency['exchange_rate'],
    'base_total' => $invoice->total * $currency['exchange_rate'],
    'base_tax' => $invoice->tax * $currency['exchange_rate'],
    'base_due_amount' => $invoice->due_amount * $currency['exchange_rate'],
]);
```

A malicious actor could:
- Submit negative exchange rates to invert all base amounts (turning profits into losses)
- Submit zero to cause division issues
- Submit extremely large values to cause integer overflow

**Verification:** BulkExchangeRateRequest.php only has `'required'` validation. No numeric or min/max checks.

**Severity:** High - Mass financial data corruption  
**Fix:**
```php
'currencies.*.exchange_rate' => [
    'required',
    'numeric',
    'min:0.0001',
    'max:999999999999',
],
```

---

### Bug 3: ChangeInvoiceStatusController - No Status Validation
**Location:** `app/Http/Controllers/V1/Admin/Invoice/ChangeInvoiceStatusController.php` (lines 20-29)  
**Code:**
```php
public function __invoke(Request $request, Invoice $invoice)
{
    $this->authorize('send invoice', $invoice);

    if ($request->status == Invoice::STATUS_SENT) {
        $invoice->status = Invoice::STATUS_SENT;
        $invoice->sent = true;
        $invoice->save();
    } elseif ($request->status == Invoice::STATUS_COMPLETED) {
        $invoice->status = Invoice::STATUS_COMPLETED;
        $invoice->paid_status = Invoice::STATUS_PAID;
        $invoice->due_amount = 0;
        $invoice->save();
    }
    // No else clause - silently does nothing for invalid status
    // No validation on $request->status

    return response()->json([
        'success' => true,
    ]);
}
```
**Problem:** 
1. No validation on `$request->status` - any value is accepted
2. Only two specific status values are handled; invalid values are silently ignored
3. No validation that invoice is in correct state for status transition
4. Sets `due_amount = 0` for COMPLETED status without verifying total paid amount

**Verification:** Direct file read confirms no validation and the if/elseif pattern without else.

**Severity:** High - Business logic bypass / silent failures  
**Fix:**
```php
$request->validate([
    'status' => 'required|in:' . Invoice::STATUS_SENT . ',' . Invoice::STATUS_COMPLETED,
]);

// Add state machine validation
if ($request->status == Invoice::STATUS_SENT && $invoice->status !== Invoice::STATUS_DRAFT) {
    return response()->json(['error' => 'invalid_status_transition'], 422);
}
```

---

## MEDIUM SEVERITY

### Bug 4: ChangeEstimateStatusController - No Status Validation
**Location:** `app/Http/Controllers/V1/Admin/Estimate/ChangeEstimateStatusController.php` (lines 16-25)  
**Code:**
```php
public function __invoke(Request $request, Estimate $estimate)
{
    $this->authorize('send estimate', $estimate);

    $estimate->update($request->only('status'));
    // No validation on status value!

    return response()->json([
        'success' => true,
    ]);
}
```
**Problem:** 
1. `$request->only('status')` accepts ANY status value from user input
2. No validation against valid Estimate status constants (DRAFT, SENT, VIEWED, EXPIRED, ACCEPTED, REJECTED)
3. Could set invalid status values like 'hacked', 'invalid', or empty string
4. Could bypass business logic (e.g., setting status to 'ACCEPTED' without going through proper acceptance flow)

**Verification:** Direct file read confirms `$request->only('status')` passes any status value without validation.

**Severity:** Medium - Data integrity bypass  
**Fix:**
```php
$validated = $request->validate([
    'status' => 'required|in:DRAFT,SENT,VIEWED,EXPIRED,ACCEPTED,REJECTED',
]);
$estimate->update($validated);
```

---

### Bug 5: AcceptEstimateController (Customer Portal) - Customer Can Set Any Status
**Location:** `app/Http/Controllers/V1/Customer/Estimate/AcceptEstimateController.php` (line 42)  
**Code:**
```php
public function __invoke(Request $request, Company $company, $id)
{
    $estimate = $company->estimates()
        ->whereCustomer(Auth::guard('customer')->id())
        ->where('id', $id)
        ->first();

    if (! $estimate) {
        return response()->json(['error' => 'estimate_not_found'], 404);
    }

    $estimate->update($request->only('status'));  // ← Customer can set ANY status!
    // ...
}
```
**Problem:** 
1. Customer can set ANY status value via API
2. No validation on what status a customer is allowed to set
3. Could set status values that should only be admin-settable (like 'DRAFT', 'SENT')
4. Could set invalid status values
5. Could bypass acceptance business logic

A malicious customer could:
- Set status to 'DRAFT' to reset an accepted estimate
- Set status to 'EXPIRED' to invalidate their own estimate
- Set status to any arbitrary value

**Verification:** Direct file read confirms `$request->only('status')` without validation.

**Severity:** Medium - Authorization bypass  
**Fix:**
```php
// Customers should only be able to set 'accepted' or 'rejected'
$validated = $request->validate([
    'status' => 'required|in:accepted,rejected',
]);
$estimate->update($validated);
```

---

## Verification Summary

| Bug # | File | Line(s) | Verified |
|------|------|---------|----------|
| 1 | CustomerPortalMiddleware.php | 20-26 | ✅ Null check missing |
| 2 | BulkExchangeRateRequest.php | 30-32 | ✅ Only 'required' validation |
| 3 | ChangeInvoiceStatusController.php | 20-29 | ✅ No status validation |
| 4 | ChangeEstimateStatusController.php | 20 | ✅ $request->only('status') |
| 5 | AcceptEstimateController.php | 42 | ✅ $request->only('status') |

---

## Excluded Issues (False Positives or Already Reported)

1. **RecurringInvoiceController mass assignment** - Initially suspected, but verification shows it uses `$request->getRecurringInvoicePayload()` which returns validated data from the FormRequest.

2. **NextNumberController injection** - Initially suspected, but verification shows it uses a switch statement with explicit case values and route model binding, which is reasonably secure.

3. **All issues from previous reports** - Excluded to avoid duplication.

---

## Recommendations

### Immediate (This Week)
1. **Fix CustomerPortalMiddleware null check** (Bug #1) - Add null check before property access
2. **Add validation to BulkExchangeRateRequest** (Bug #2) - Add numeric, min, max validation

### Short-term (This Month)
3. **Add status validation to ChangeInvoiceStatusController** (Bug #3)
4. **Add status validation to ChangeEstimateStatusController** (Bug #4)
5. **Add status validation to AcceptEstimateController** (Bug #5)

---

**End of Report - De bugs.md**
