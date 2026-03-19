# Speed Optimization & Critical Bugs Report

This document catalogs all bugs discovered during codebase exploration, categorized by severity and component.

---

## Table of Contents

1. [Critical Bugs](#critical-bugs)
2. [High Severity Bugs](#high-severity-bugs)
3. [Medium Severity Bugs](#medium-severity-bugs)
4. [Summary](#summary)

---

## Critical Bugs

### 1. Inconsistent Company Scoping in InvoicesRequest

**File:** `app/Http/Requests/InvoicesRequest.php`  
**Line:** 178  
**Severity:** CRITICAL

**Description:**  
The `getInvoicePayload()` method performs a user lookup for `assigned_to_id` without company scoping, which can lead to data from the wrong company being assigned to an invoice.

**Problematic Code:**

```php
// Line 178 - Missing company scoping
$user = User::find($data['assigned_to_id']);
```

**Expected Behavior:**  
Should scope the query to the current company:

```php
$user = User::where('company_id', $this->company->id)
    ->find($data['assigned_to_id']);
```

---

### 2. ConfigMiddleware Regression

**File:** `app/Http/Middleware/ConfigMiddleware.php`  
**Line:** 22  
**Severity:** CRITICAL

**Description:**  
The middleware was changed to require BOTH `file_disk_id` AND company header, breaking existing functionality for users who rely on just one of these parameters.

**Problematic Code:**

```php
// Line 22 - Requires BOTH conditions
if ($request->file_disk_id && $request->header('company')) {
    // ...
}
```

**Impact:**  
Existing API calls that only provide one of these parameters will fail unexpectedly.

---

### 3. CRITICAL PERFORMANCE BUG: N+1 Query Pattern in Resources

**Severity:** CRITICAL (Performance)

**Description:**  
80 instances of the incorrect `$this->when($this->relationship()->exists(), ...)` pattern exist throughout the Resource files. This pattern defeats eager loading by triggering N+1 queries for each record.

**The Problem:**

- `$this->relationship()->exists()` executes a fresh query every time
- This bypasses eager loading entirely
- For 100 records, this creates 100+ additional database queries

**The Correct Pattern:**

```php
// WRONG (current pattern - triggers N+1)
$this->when($this->relationship()->exists(), function () {
    return new RelationshipResource($this->relationship);
});

// CORRECT
$this->whenLoaded('relationship', function () {
    return new RelationshipResource($this->relationship);
});
```

**Affected Files (80 instances across):**

- `app/Http/Resources/UserResource.php`
- `app/Http/Resources/TransactionResource.php`
- `app/Http/Resources/NoteResource.php`
- `app/Http/Resources/TaxTypeResource.php`
- `app/Http/Resources/UnitResource.php`
- `app/Http/Resources/TaxResource.php`
- `app/Http/Resources/ItemResource.php`
- `app/Http/Resources/EstimateItemResource.php`
- `app/Http/Resources/ExchangeRateProviderResource.php`
- `app/Http/Resources/InvoiceItemResource.php`
- `app/Http/Resources/AddressResource.php`
- `app/Http/Resources/CompanyResource.php`
- `app/Http/Resources/CustomFieldValueResource.php`
- `app/Http/Resources/CustomFieldResource.php`
- **ALL files in `app/Http/Resources/Customer/` directory**

---

## High Severity Bugs

### 4. Customer.php - scopeApplyInvoiceFilters Missing Company Scoping

**File:** `app/Models/Customer.php`  
**Lines:** 345-354  
**Severity:** HIGH

**Description:**  
The `scopeApplyInvoiceFilters` method does not scope queries to the current company, potentially returning invoices from other companies.

**Problematic Code:**

```php
// Lines 345-354 - Missing company_id scoping
public function scopeApplyInvoiceFilters($query, $filters)
{
    $query->when($filters['status'], function ($query) use ($filters) {
        return $query->where('status', $filters['status']);
    });
    // ...
}
```

---

### 5. Customer.php - deleteCustomers Can Delete Invoices from Wrong Company

**File:** `app/Models/Customer.php`  
**Lines:** 159-211  
**Severity:** HIGH

**Description:**  
The `deleteCustomers` method may delete invoices from the wrong company if the customer ID exists in multiple companies.

**Problematic Code:**

```php
// Lines 159-211 - No company scoping before delete
public function deleteCustomers($customerIds)
{
    $customers = Customer::whereIn('id', $customerIds)->get();

    foreach ($customers as $customer) {
        // Deletes associated invoices without company check
        $customer->invoices()->delete();
        $customer->delete();
    }
}
```

---

### 6. Payment.php - updatePayment Complex Logic Error

**File:** `app/Models/Payment.php`  
**Lines:** 260-285  
**Severity:** HIGH

**Description:**  
The `updatePayment` method contains complex logic that may incorrectly handle payment status updates, especially when dealing with partial payments and multiple invoices.

---

### 7. Expense.php - scopeWhereSearch orWhere Logic Error

**File:** `app/Models/Expense.php`  
**Lines:** 193-201  
**Severity:** HIGH

**Description:**  
The `scopeWhereSearch` method uses `orWhere` incorrectly, which can lead to unexpected results when combining multiple search conditions.

**Problematic Code:**

```php
// Lines 193-201 - Incorrect orWhere usage
public function scopeWhereSearch($query, $search)
{
    return $query->where(function ($query) use ($search) {
        $query->where('name', 'like', "%$search%")
            ->orWhere('description', 'like', "%$search%")
            ->orWhere('amount', $search);
    });
}
```

**Note:** The `orWhere` with `amount` (numeric) may not behave as intended since string comparison differs from numeric comparison.

---

### 8. DeleteUserRequest Validation Logic Change

**File:** `app/Http/Requests/DeleteUserRequest.php`  
**Line:** 29  
**Severity:** HIGH

**Description:**  
The validation logic was changed from checking the `users` table to checking the `user_company` pivot table, which may allow deletion of users who should be protected.

**Problematic Code:**

```php
// Line 29 - Changed from users table check
public function authorize()
{
    // Now checks pivot table instead of users table
    return UserCompany::where('user_id', $this->user_id)->exists();
}
```

---

## Medium Severity Bugs

### 9. Inconsistent Soft Delete Check in DeleteItemsRequest

**File:** `app/Http/Requests/DeleteItemsRequest.php`  
**Lines:** 31-32  
**Severity:** MEDIUM

**Description:**  
The `DeleteItemsRequest` validation is missing `whereNull('deleted_at')` check, which could allow operations on already soft-deleted items.

**Problematic Code:**

```php
// Lines 31-32 - Missing soft delete check
public function validateDelete()
{
    return Item::whereIn('id', $this->items)->exists();
}
```

**Expected Behavior:**

```php
return Item::whereIn('id', $this->items)
    ->whereNull('deleted_at')
    ->exists();
```

---

### 10. Potential Null Reference in EstimatesRequest and InvoicesRequest

**Files:**

- `app/Http/Requests/EstimatesRequest.php`
- `app/Http/Requests/InvoicesRequest.php`

**Severity:** MEDIUM

**Description:**  
Customer lookup during `rules()` build may return null if the customer doesn't exist, potentially causing validation errors.

---

### 11. Invoice.php - deleteInvoices Incomplete Cleanup

**File:** `app/Models/Invoice.php`  
**Lines:** 800-818  
**Severity:** MEDIUM

**Description:**  
The `deleteInvoices` method may not properly clean up all related records (payments, items, tax records).

---

### 12. Payment.php - deletePayments Incorrect Status Calculation

**File:** `app/Models/Payment.php`  
**Lines:** 338-353  
**Severity:** MEDIUM

**Description:**  
The `deletePayments` method may incorrectly calculate payment status for related invoices after deletion.

---

### 13. User.php - deleteUsers Data Integrity

**File:** `app/Models/User.php`  
**Lines:** 410-458  
**Severity:** MEDIUM

**Description:**  
The `deleteUsers` method may have data integrity issues when deleting users associated with multiple companies.

---

### 14. Unauthorized Privilege Escalation and Cross-Tenant Breach

**File:** `app/Http/Requests/UserRequest.php`  
**Lines:** 39-47  
**Severity:** CRITICAL

**Description:**  
The validation rules for assigning a user to companies (`companies.*.id` and `companies.*.role`) completely lack permissions scoping or authorization checks. A malicious user with user-creation/update access could intercept the payload and inject arbitrary company IDs, assigning themselves the `admin` role across any company. `User::createFromRequest` blindly trusts this array and executes the Bouncer sync, completely breaking tenant/company isolation.

**Problematic Code:**

```php
// Lines 39-47 - No authorization on company assignment
'companies' => 'required|array',
'companies.*.id' => 'exists:companies,id',
'companies.*.role' => 'required|string',
```

**Impact:** Any user can escalate privileges to admin across any company by manipulating the request payload.

---

### 15. Financial Forgery (Trusting Client-Side Totals)

**Files:**

- `app/Http/Requests/InvoicesRequest.php`
- `app/Http/Requests/EstimatesRequest.php`

**Severity:** CRITICAL

**Description:**  
The backend constructs the invoice/estimate payload by directly reading `$this->total`, `$this->sub_total`, and `$this->tax` from the HTTP request instead of securely calculating the final totals server-side based on the `items` array. A client can forge an invoice containing $1,000 worth of items but explicitly set `"total": 1 in the request. The backend blindly writes this to the DB, allowing severe financial falsification.

**Problematic Code:**

```php
// In InvoicesRequest.php getInvoicePayload()
return collect($this->except('items', 'taxes'))
    ->merge([
        'total' => $this->total,        // Directly from request!
        'sub_total' => $this->sub_total, // Directly from request!
        'tax' => $this->tax,            // Directly from request!
        // ...
    ]);
```

**Impact:** Complete financial fraud possible - clients can set any total regardless of actual item values.

---

### 16. Negative-Value Vulnerabilities in Financial Transactions

**Files:**

- `app/Http/Requests/PaymentRequest.php`
- `app/Http/Requests/InvoicesRequest.php`
- `app/Http/Requests/EstimatesRequest.php`

**Severity:** HIGH

**Description:**  
While the form requests enforce that fields like `amount` or `total` are `numeric` and `required`, they completely lack negative boundary protections (e.g., `min:0`).

**Problematic Code:**

```php
// In PaymentRequest.php rules()
'amount' => [
    'required',
    'numeric',
    // Missing: 'min:0'
],
```

**Impact:**  
A user or malicious actor can submit negative values (e.g., `amount: -5000`) for a Payment. If processed, the backend math (`$invoice->subtractInvoicePayment($request->amount)`) will calculate: `due_amount - (-5000)`, literally **increasing** the outstanding balance instead of lowering it, or creating scenarios calculating negative revenue. This breaks the fundamental logic of the billing system and compromises accounting integrity.

---

### 17. Arbitrary Filename Trust in Media Uploads (Potential RCE)

**Files:**

- `app/Http/Controllers/V1/Admin/Payment/UploadReceiptController.php`
- `app/Http/Requests/UploadExpenseReceiptRequest.php`

**Severity:** HIGH

**Description:**  
The application allows uploading expense receipts via Base64 string payloads. While `UploadExpenseReceiptRequest.php` runs `new Base64Mime(['gif', 'jpg', 'png'])` to verify the literal MIME type of the content, the controller utilizes `$expense->addMediaFromBase64($data->data)->usingFileName($data->name)`. The `$data->name` is completely controlled by the user and is never validated.

**Problematic Code:**

```php
// In UploadReceiptController.php
$expense->addMediaFromBase64($data->data)
    ->usingFileName($data->name);  // User-controlled filename!
```

**Impact:**  
An attacker can upload a valid polyglot image file (which passes MIME checks but contains executable PHP code) and pass `'name': 'shell.php'`. The application stores the file as `shell.php` on the filesystem. If the Spatie Media Library directory is exposed to the public web server and the server is configured to execute `.php` files in that media directory, this immediately results in a Remote Code Execution (RCE) vulnerability allowing total server compromise.

---

## Summary

| #   | Severity | Component   | Issue                                            | File                                                          | Line(s)  |
| --- | -------- | ----------- | ------------------------------------------------ | ------------------------------------------------------------- | -------- |
| 1   | CRITICAL | Requests    | Inconsistent Company Scoping in InvoicesRequest  | InvoicesRequest.php                                           | 178      |
| 2   | CRITICAL | Middleware  | ConfigMiddleware Regression                      | ConfigMiddleware.php                                          | 22       |
| 3   | CRITICAL | Resources   | N+1 Query Pattern (80 instances)                 | Multiple Resource files                                       | Multiple |
| 4   | HIGH     | Models      | scopeApplyInvoiceFilters Missing Company Scoping | Customer.php                                                  | 345-354  |
| 5   | HIGH     | Models      | deleteCustomers Can Delete from Wrong Company    | Customer.php                                                  | 159-211  |
| 6   | HIGH     | Models      | updatePayment Complex Logic Error                | Payment.php                                                   | 260-285  |
| 7   | HIGH     | Models      | scopeWhereSearch orWhere Logic Error             | Expense.php                                                   | 193-201  |
| 8   | HIGH     | Requests    | DeleteUserRequest Validation Logic Change        | DeleteUserRequest.php                                         | 29       |
| 9   | MEDIUM   | Requests    | Inconsistent Soft Delete Check                   | DeleteItemsRequest.php                                        | 31-32    |
| 10  | MEDIUM   | Requests    | Potential Null Reference in Customer Lookup      | EstimatesRequest.php, InvoicesRequest.php                     | -        |
| 11  | MEDIUM   | Models      | deleteInvoices Incomplete Cleanup                | Invoice.php                                                   | 800-818  |
| 12  | MEDIUM   | Models      | deletePayments Incorrect Status Calculation      | Payment.php                                                   | 338-353  |
| 13  | MEDIUM   | Models      | deleteUsers Data Integrity                       | User.php                                                      | 410-458  |
| 14  | CRITICAL | Requests    | Unauthorized Privilege Escalation                | UserRequest.php                                               | 39-47    |
| 15  | CRITICAL | Requests    | Financial Forgery (Trusting Client-Side Totals)  | InvoicesRequest.php, EstimatesRequest.php                     | -        |
| 16  | HIGH     | Requests    | Negative-Value Vulnerabilities                   | PaymentRequest.php, InvoicesRequest.php, EstimatesRequest.php | -        |
| 17  | HIGH     | Controllers | Arbitrary Filename Trust (RCE)                   | UploadReceiptController.php                                   | -        |

---

## Recommended Priority

1. **Immediate (Critical):**
   - Fix N+1 query pattern in all Resource files (Performance impact)
   - Fix company scoping in InvoicesRequest
   - Fix ConfigMiddleware regression
   - Fix Unauthorized Privilege Escalation in UserRequest.php
   - Fix Financial Forgery in InvoicesRequest.php and EstimatesRequest.php

2. **High Priority:**
   - Add company scoping to Customer model methods
   - Fix Expense orWhere logic
   - Review DeleteUserRequest validation
   - Add `min:0` validation to all financial amount fields
   - Validate/sanitize filenames in media uploads

3. **Medium Priority:**
   - Add soft delete checks
   - Review null reference handling
   - Improve cleanup methods in delete operations
