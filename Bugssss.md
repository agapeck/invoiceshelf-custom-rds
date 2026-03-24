# Bugssss.md - Additional Verified Bugs (NEW - Not Previously Reported)

**Report Date:** March 25, 2026  
**Repository Analyzed:** invoiceshelf-custom-rds (main branch)  
**Scope:** NEW bugs not reported in:
- Almost slipped through bugs.md
- Grok+GLM bug report.md  
- Performance Optimization Report.md
- Grok bugreport 2.md

**Note:** Several issues I initially found were already documented in Grok bugreport 2.md. This report contains ONLY issues not covered in any previous report.

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 1 |
| High | 2 |
| Medium | 4 |
| Low | 1 |
| **Total** | **8** |

---

## CRITICAL SEVERITY

### Bug 1: Customer Password Created Without Validation Requirements
**Location:** `app/Http/Requests/CustomerRequest.php` (lines 34-36)  
**Code:**
```php
'password' => [
    'nullable',
],
```
**Problem:** The admin-facing CustomerRequest has NO password validation rules whatsoever. This is different from:
- `UserRequest.php`: Has `'min:8'` validation
- `ProfileRequest.php`: Has `'min:8'` validation
- `CustomerProfileRequest.php`: Has `'nullable', 'min:8'` validation

An admin creating a customer account can set:
- Empty passwords (security gap)
- 1-character passwords
- Passwords without confirmation (typos go undetected)

Combined with the mutator issue (reported in Grok bugreport 2.md), this creates a compound security problem: validation allows empty strings, mutator skips hashing for empty strings.

**Verification:** Direct comparison of validation rules across request classes confirms this is the only password field without length requirements.

**Severity:** Critical - Authentication security vulnerability  
**Fix:** Add `'min:8', 'confirmed'` validation rules

---

## HIGH SEVERITY

### Bug 2: Expense Amount Lacks Numeric/Min Validation
**Location:** `app/Http/Requests/ExpenseRequest.php` (lines 43-45)  
**Code:**
```php
'amount' => [
    'required',
],
```
**Problem:** No `'numeric'` or `'min:0'` validation on expense amount. Compare with PaymentRequest:
```php
'amount' => [
    'required',
    'numeric',
    'min:0.01',
    'max:999999999999',
],
```

Malicious actors can submit:
- Negative amounts (creates phantom credits)
- Non-numeric strings (causes downstream errors)
- Extremely large values (potential overflow)

**Verification:** Direct file read confirms lack of validation.

**Severity:** High - Financial data integrity at risk  
**Fix:** Add `'numeric', 'min:0', 'max:999999999999'` validation rules

---

### Bug 3: Payment Exchange Rate Lacks Type Validation When Required
**Location:** `app/Http/Requests/PaymentRequest.php` (lines 37-39, 88-91)  
**Code:**
```php
// Default rule - no type checking
'exchange_rate' => [
    'nullable',
],

// When required (lines 88-91) - STILL no type checking
$rules['exchange_rate'] = [
    'required',
];
```
**Problem:** While EstimatesRequest's exchange_rate issue was reported earlier, the SAME issue exists in PaymentRequest. When a customer uses a different currency, the exchange_rate becomes required but has:
- No `'numeric'` validation
- No `'min:0'` bounds check

Negative exchange rates would invert payment calculations, corrupting the AR ledger.

**Verification:** This is a DIFFERENT file from the previously reported EstimatesRequest issue.

**Severity:** High - Financial data corruption risk  
**Fix:** Add `'numeric', 'min:0.0001'` validation rules

---

## MEDIUM SEVERITY

### Bug 4: Trailing Space in Tax Per Item Fallback - InvoicesRequest
**Location:** `app/Http/Requests/InvoicesRequest.php` (line 166)  
**Code:**
```php
'tax_per_item' => CompanySetting::getSetting('tax_per_item', $this->header('company')) ?? 'NO ',
```
**Problem:** The same trailing space bug exists in InvoicesRequest (a DIFFERENT file from EstimatesRequest which was previously reported). The fallback `'NO '` has a trailing space, causing:
- Mismatch with `'NO'` comparisons in conditionals
- UI toggle state issues
- Data inconsistency across invoice types

**Verification:** This is InvoicesRequest, not EstimatesRequest - separate file, same bug pattern.

**Severity:** Medium - Data consistency issue  
**Fix:** Change `'NO '` to `'NO'`

---

### Bug 5: Trailing Space in Tax Per Item Fallback - RecurringInvoiceRequest
**Location:** `app/Http/Requests/RecurringInvoiceRequest.php` (line 119)  
**Code:**
```php
'tax_per_item' => CompanySetting::getSetting('tax_per_item', $this->header('company')) ?? 'NO ',
```
**Problem:** Same trailing space bug in RecurringInvoiceRequest - a third file affected by this pattern.

**Verification:** Separate file from both EstimatesRequest and InvoicesRequest.

**Severity:** Medium - Data consistency issue  
**Fix:** Change `'NO '` to `'NO'`

---

### Bug 6: Duplicate Array Key in RecurringInvoiceRequest Rules
**Location:** `app/Http/Requests/RecurringInvoiceRequest.php` (lines 42-44 and 68-70)  
**Code:**
```php
$rules = [
    // ...
    'exchange_rate' => [
        'nullable',
    ],
    // ... 20 lines of other rules ...
    'exchange_rate' => [
        'nullable',
    ],
];
```
**Problem:** The `exchange_rate` key is defined TWICE in the rules array. PHP silently overwrites the first definition with the second. This causes:
- Confusion for maintainers
- Risk that changes to first definition are silently ignored
- Potential bugs if conditional logic modifies either definition

**Verification:** Direct file read confirms two identical key definitions.

**Severity:** Medium - Code quality/maintenance issue  
**Fix:** Remove the duplicate key definition

---

### Bug 7: RecurringInvoice Validation Differs from Invoice
**Location:** `app/Http/Requests/RecurringInvoiceRequest.php` (lines 49-61)  
**Code:**
```php
'discount_val' => [
    'integer',
    'required',
    // Missing: 'min:0'
],
'sub_total' => [
    'integer',   // ← InvoicesRequest uses 'numeric'
    'required',
],
'total' => [
    'integer',   // ← InvoicesRequest uses 'numeric'
    'max:999999999999',
    'required',
],
```
**Problem:** Validation rules differ significantly from InvoicesRequest:
1. `discount_val` lacks `'min:0'` - allows negative discounts
2. `sub_total` and `total` use `'integer'` instead of `'numeric'` - this REJECTS decimal amounts

A recurring invoice with `total: 100.50` would fail validation, even though the generated invoices support decimals.

**Verification:** Direct comparison with InvoicesRequest validation rules.

**Severity:** Medium - Functional limitation and potential data issues  
**Fix:** Add `'min:0'` to discount_val, change `'integer'` to `'numeric'` for sub_total/total

---

## LOW SEVERITY

### Bug 8: Unbounded Delete Arrays - Multiple Additional Request Classes
**Location:** Multiple files (different from DeletePaymentsRequest which was previously reported):
- `app/Http/Requests/DeleteCustomersRequest.php`
- `app/Http/Requests/DeleteInvoiceRequest.php`
- `app/Http/Requests/DeleteEstimatesRequest.php`
- `app/Http/Requests/DeleteExpensesRequest.php`
- `app/Http/Requests/DeleteItemsRequest.php`

**Code Pattern:**
```php
'ids' => [
    'required',
    // Missing: 'array', 'max:100'
],
'ids.*' => [
    'required',
    Rule::exists('table', 'id')...
],
```
**Problem:** While DeletePaymentsRequest was previously reported, these FIVE additional delete endpoints have the same vulnerability. All allow:
- No `'array'` type validation
- No maximum size limit
- Unbounded EXISTS queries (50,000 IDs = 50,000 SELECT EXISTS queries)

**Verification:** Each file follows the same pattern.

**Severity:** Low - Same pattern as previously reported, but affects additional endpoints  
**Fix:** Add `'array', 'max:100'` to ids validation in all affected files

---

## Verification Summary

| Bug # | File | Verified | Notes |
|-------|------|----------|-------|
| 1 | CustomerRequest.php | ✅ | Line 34-36: password = ['nullable'] only |
| 2 | ExpenseRequest.php | ✅ | Line 43-45: amount = ['required'] only |
| 3 | PaymentRequest.php | ✅ | Lines 37-39, 88-91: No numeric validation |
| 4 | InvoicesRequest.php | ✅ | Line 166: Contains 'NO ' with space |
| 5 | RecurringInvoiceRequest.php | ✅ | Line 119: Contains 'NO ' with space |
| 6 | RecurringInvoiceRequest.php | ✅ | Lines 42-44, 68-70: Duplicate keys |
| 7 | RecurringInvoiceRequest.php | ✅ | Lines 49-61: Integer vs numeric mismatch |
| 8 | Multiple DeleteRequest.php | ✅ | All lack array/max validation |

---

## Excluded Issues (Already Reported in Grok bugreport 2.md)

The following issues I initially identified were already documented and thus excluded from this report:
- Customer.php creator() relationship pointing to wrong model
- Customer.php setPasswordAttribute empty string handling
- Customer.php getAvatarAttribute returning 0
- Customer.php updateCustomer address deletion
- Invoice.php float comparison in status logic

---

## Recommendations

### Immediate (This Week)
1. Add password validation to CustomerRequest (Bug #1 - Critical)
2. Add numeric/min validation to ExpenseRequest amount (Bug #2 - High)

### Short-term (This Month)
3. Add numeric/min validation to PaymentRequest exchange_rate (Bug #3)
4. Fix trailing spaces in InvoicesRequest and RecurringInvoiceRequest (Bugs #4, #5)

### Medium-term (This Quarter)
5. Fix duplicate key in RecurringInvoiceRequest (Bug #6)
6. Align RecurringInvoiceRequest validation with InvoicesRequest (Bug #7)
7. Add array bounds to remaining delete requests (Bug #8)

---

**End of Report - Bugssss.md**