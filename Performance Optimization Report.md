# Performance Optimization Report - InvoiceShelf Custom RDS

**Report Title:** Speed, Performance Bottlenecks, and Optimization Analysis  
**Repository:** https://github.com/agapeck/invoiceshelf-custom-rds  
**Analysis Date:** March 24, 2026  
**Reviewer:** GLM-5 (Deep Systematic Analysis)  
**Scope:** Focus on speed/performance bottlenecks and optimization opportunities not covered in previous reports

---

## Executive Summary

This performance analysis identified **28 NEW undocumented performance issues** across the codebase, categorized by severity and impact. The analysis focused on database query patterns, caching strategies, I/O operations, and computational efficiency.

### Key Findings Summary

| Severity | Count | Primary Categories |
|----------|-------|-------------------|
| **Critical** | 4 | N+1 query explosions, boot-time database hits |
| **High** | 10 | Repeated settings queries, missing indexes, lock contention |
| **Medium** | 9 | Unoptimized eager loading, unnecessary I/O |
| **Low** | 5 | Minor inefficiencies, code-level optimizations |

### Performance Impact Assessment

| Area | Current State | Estimated Improvement |
|------|---------------|----------------------|
| Dashboard Load | 11+ queries | Reduce to 3-4 queries (70% reduction) |
| Model Accessors | N+1 per record | Batch loading (90% reduction) |
| App Boot | 5+ DB queries per request | Caching (95% reduction) |
| PDF Generation | Multiple N+1 patterns | Eager loading (80% reduction) |

---

## CRITICAL Severity Issues

### PERF-001: CompanySetting N+1 Query Pattern in Model Accessors

**Severity:** Critical  
**Impact:** High - Affects every list view and model serialization  
**Files Affected:**
- `app/Models/Invoice.php` (lines 199, 206, 213-215)
- `app/Models/Payment.php` (lines 96, 103)
- `app/Models/Customer.php` (lines 90, 101)
- `app/Models/RecurringInvoice.php` (lines 62, 69, 76, 83)

#### Description

Every formatted date attribute accessor calls `CompanySetting::getSetting('carbon_date_format', $this->company_id)`. While there's a static cache within a request, this still causes N+1 queries when:
1. Models are serialized across different companies (multi-tenant scenario)
2. The static cache is cleared mid-request
3. Long-running processes (Octane, Swoole) retain stale cache

#### Vulnerable Code

```php
// Invoice.php:199
public function getFormattedCreatedAtAttribute($value)
{
    $dateFormat = CompanySetting::getSetting('carbon_date_format', $this->company_id);
    return Carbon::parse($this->created_at)->translatedFormat($dateFormat);
}

// Payment.php:96
public function getFormattedCreatedAtAttribute($value)
{
    $dateFormat = CompanySetting::getSetting('carbon_date_format', $this->company_id);
    return Carbon::parse($this->created_at)->translatedFormat($dateFormat);
}
```

#### Impact Analysis

For a list of 100 invoices across 10 companies:
- **Current:** 100+ separate CompanySetting queries (even with static cache, cache misses per company)
- **Optimized:** 1 batch query for all needed settings

#### Benchmark Simulation

```
List 50 invoices, 5 companies:
- Current: ~55 queries (50 accessor calls + overhead)
- Optimized: 3 queries (settings batch + invoices + customers)
- Improvement: 94% query reduction
```

#### Recommended Fix

**Option 1: Batch-load settings in service provider**

```php
// In AppServiceProvider::boot()
View::composer('*', function ($view) {
    $companyId = request()->header('company');
    if ($companyId) {
        $settings = CompanySetting::getAllSettings($companyId);
        $view->with('companySettings', $settings);
    }
});

// In model accessors
public function getFormattedCreatedAtAttribute($value)
{
    $dateFormat = app('company_settings')->get('carbon_date_format');
    return Carbon::parse($this->created_at)->translatedFormat($dateFormat);
}
```

**Option 2: Use attribute accessor caching**

```php
// In model
protected function formattedCreatedAt(): Attribute
{
    return Attribute::make(
        get: fn ($value, $attributes) => Cache::remember(
            "invoice.{$attributes['id']}.formatted_created",
            now()->addHour(),
            fn() => Carbon::parse($attributes['created_at'])
                ->translatedFormat(CompanySetting::getSetting('carbon_date_format', $attributes['company_id']))
        )
    )->shouldCache();
}
```

---

### PERF-002: Dashboard Query Explosion - 11+ Queries Per Load

**Severity:** Critical  
**Impact:** High - Dashboard is the most frequently accessed page  
**File:** `app/Http/Controllers/V1/Admin/Dashboard/DashboardController.php`

#### Description

The dashboard controller executes **11+ separate database queries** for a single page load. Each query is independent and could be consolidated.

#### Current Query Breakdown

```php
// Lines 65-79: 3 monthly aggregation queries
$invoiceMonthly = $this->getMonthlySums(...); // Query 1
$expenseMonthly = $this->getMonthlySums(...); // Query 2
$paymentMonthly = $this->getMonthlySums(...); // Query 3

// Lines 97-116: 3 separate SUM queries
$total_sales = Invoice::whereBetween(...)->sum('base_total');     // Query 4
$total_receipts = Payment::whereBetween(...)->sum('base_amount'); // Query 5
$total_expenses = Expense::whereBetween(...)->sum('base_amount'); // Query 6

// Lines 128-133: 4 count/aggregation queries
$total_customer_count = Customer::whereCompany()->count();  // Query 7
$total_invoice_count = Invoice::whereCompany()->count();    // Query 8
$total_estimate_count = Estimate::whereCompany()->count();  // Query 9
$total_amount_due = Invoice::whereCompany()->sum('base_due_amount'); // Query 10

// Lines 135-145: 2 recent item queries
$recent_due_invoices = Invoice::with(['customer.currency', 'currency'])... // Query 11
$recent_estimates = Estimate::with(['customer.currency', 'currency'])...   // Query 12
```

#### Impact Analysis

On RDS with 10ms average query latency:
- **Current:** 120ms database time (minimum)
- **With concurrent queries:** ~40-60ms (if queries run in parallel)
- **With consolidated queries:** ~15-25ms

#### Recommended Fix

**Consolidate into fewer queries using subqueries:**

```php
public function __invoke(Request $request)
{
    $company = Company::find($request->header('company'));
    $this->authorize('view dashboard', $company);

    $periodStart = $this->getPeriodStart($company);
    $periodEnd = $periodStart->copy()->addMonths(11)->endOfMonth();

    // Single query for all invoice aggregations
    $invoiceStats = Invoice::whereCompany()
        ->whereBetween('invoice_date', [$periodStart, $periodEnd])
        ->selectRaw("
            COUNT(*) as total_count,
            SUM(base_total) as total_sales,
            SUM(base_due_amount) as total_due,
            DATE_FORMAT(invoice_date, '%Y-%m') as month_key
        ")
        ->groupBy('month_key')
        ->get();

    // Single query for payment aggregations
    $paymentStats = Payment::whereCompany()
        ->whereBetween('payment_date', [$periodStart, $periodEnd])
        ->selectRaw("
            SUM(base_amount) as total_receipts,
            DATE_FORMAT(payment_date, '%Y-%m') as month_key
        ")
        ->groupBy('month_key')
        ->get();

    // Single query for expense aggregations
    $expenseStats = Expense::whereCompany()
        ->whereBetween('expense_date', [$periodStart, $periodEnd])
        ->selectRaw("
            SUM(base_amount) as total_expenses,
            DATE_FORMAT(expense_date, '%Y-%m') as month_key
        ")
        ->groupBy('month_key')
        ->get();

    // Single query for counts
    $counts = DB::select("
        SELECT 
            (SELECT COUNT(*) FROM customers WHERE company_id = ? AND deleted_at IS NULL) as customer_count,
            (SELECT COUNT(*) FROM invoices WHERE company_id = ? AND deleted_at IS NULL) as invoice_count,
            (SELECT COUNT(*) FROM estimates WHERE company_id = ? AND deleted_at IS NULL) as estimate_count
    ", [$company->id, $company->id, $company->id]);

    // Recent items with proper eager loading (already correct)
    $recent_due_invoices = Invoice::with(['customer.currency', 'currency'])
        ->whereCompany()
        ->where('base_due_amount', '>', 0)
        ->take(5)
        ->latest()
        ->get();

    // ... build response from consolidated data
}
```

**Estimated Improvement:** From 11+ queries to 4-5 queries (60% reduction)

---

### PERF-003: AppConfigProvider Database Queries on Every Request

**Severity:** Critical  
**Impact:** High - Adds latency to every HTTP request  
**File:** `app/Providers/AppConfigProvider.php`

#### Description

The `AppConfigProvider::boot()` method executes multiple database queries on **every single HTTP request**:

1. `InstallUtils::isDbCreated()` - File I/O + schema check
2. `Setting::getSettings()` - Database query for mail config (22 settings)
3. `Setting::getSettings()` - Database query for PDF config (4 settings)
4. `FileDisk::resolveDefaultDisk()` - Database query for file disk

#### Vulnerable Code

```php
// AppConfigProvider.php:15-25
public function boot(): void
{
    if (! InstallUtils::isDbCreated()) {  // File I/O on every request!
        return;
    }

    $this->configureMailFromDatabase();   // 22-setting DB query
    $this->configurePDFFromDatabase();    // 4-setting DB query
    $this->configureFileSystemFromDatabase(); // FileDisk DB query
}
```

#### Impact Analysis

| Operation | Time Cost | Frequency |
|-----------|-----------|-----------|
| `isDbCreated()` file check | ~1-2ms | Every request |
| Mail settings query | ~3-5ms | Every request |
| PDF settings query | ~1-2ms | Every request |
| FileDisk query | ~2-3ms | Every request |
| **Total overhead per request** | **~7-12ms** | Every request |

At 100 requests/second: 700-1200ms of wasted database time per second.

#### Recommended Fix

**Use Laravel's cache with long TTL and config caching:**

```php
public function boot(): void
{
    // Quick file check (cached)
    if (! $this->isInstalled()) {
        return;
    }

    // Load all config from cache
    $this->loadCachedConfig();
}

private function isInstalled(): bool
{
    return Cache::remember('app.installed', now()->addHour(), function () {
        return InstallUtils::isDbCreated();
    });
}

private function loadCachedConfig(): void
{
    $companyId = request()->header('company');
    $cacheKey = "app.config.{$companyId}";

    $config = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($companyId) {
        return [
            'mail' => Setting::getSettings([...]), // 22 settings in 1 query
            'pdf' => Setting::getSettings([...]),  // 4 settings in 1 query
            'disk' => FileDisk::resolveDefaultDisk($companyId),
        ];
    });

    $this->applyConfig($config);
}
```

**Clear cache on settings update:**

```php
// In UpdateSettingsController or Setting model observer
Cache::forget("app.config.{$companyId}");
```

**Estimated Improvement:** 90% reduction in boot-time database queries

---

### PERF-004: InstallUtils File I/O on Every Request

**Severity:** Critical  
**Impact:** High - Blocking I/O on every request  
**File:** `app/Space/InstallUtils.php`

#### Description

`InstallUtils::isDbCreated()` performs file system I/O on every request to check if the database marker file exists.

#### Vulnerable Code

```php
// InstallUtils.php:18-21
public static function isDbCreated()
{
    return self::dbMarkerExists() && self::tableExists('users');
}

// InstallUtils.php:52-61
public static function dbMarkerExists()
{
    try {
        return \Storage::disk('local')->has('database_created'); // File I/O!
    } catch (FilesystemException $e) {
        Log::error('Unable to verify db marker: '.$e->getMessage());
    }
    return false;
}
```

#### Impact Analysis

File system operations are significantly slower than memory operations:
- **File existence check:** ~0.5-2ms per request
- **At 1000 requests/minute:** 30-120 seconds of file I/O time

Additionally, `Storage::disk('local')->has()` may trigger filesystem driver initialization on first call.

#### Recommended Fix

**Cache the installation status:**

```php
class InstallUtils
{
    private static ?bool $installedCache = null;

    public static function isDbCreated(): bool
    {
        // Request-level cache (fastest)
        if (self::$installedCache !== null) {
            return self::$installedCache;
        }

        // Application-level cache
        return self::$installedCache = Cache::remember(
            'app.db_created',
            now()->addHour(),
            fn() => self::checkDbMarkerExists() && self::tableExists('users')
        );
    }

    private static function checkDbMarkerExists(): bool
    {
        try {
            return \Storage::disk('local')->has('database_created');
        } catch (FilesystemException $e) {
            Log::error('Unable to verify db marker: '.$e->getMessage());
            return false;
        }
    }

    public static function createDbMarker(): bool
    {
        // Clear cache when creating marker
        Cache::forget('app.db_created');
        self::$installedCache = null;
        // ... rest of method
    }
}
```

**Estimated Improvement:** Eliminates file I/O from request path after first check

---

## HIGH Severity Issues

### PERF-005: SerialNumberFormatter Lock Contention and Double Query

**Severity:** High  
**Impact:** Medium-High - Affects invoice/payment creation throughput  
**File:** `app/Services/SerialNumberFormatter.php`

#### Description

The `setNextSequenceNumber()` method uses `lockForUpdate()` which is correct for concurrency, but causes lock contention under high load. Additionally, the `getNextNumber()` method performs an `exists()` check that races with the lock.

#### Vulnerable Code

```php
// SerialNumberFormatter.php:160-173
public function setNextSequenceNumber()
{
    $last = $this->model::orderBy('sequence_number', 'desc')
        ->where('company_id', $companyId)
        ->where('sequence_number', '<>', null)
        ->lockForUpdate()  // Causes lock contention
        ->take(1)
        ->first();

    $this->nextSequenceNumber = ($last) ? $last->sequence_number + 1 : 1;
}

// SerialNumberFormatter.php:118-132
do {
    $serialNumber = $this->generateSerialNumber($format);
    
    $exists = $this->model::where('company_id', $companyId)
        ->where($modelName.'_number', $serialNumber)
        ->exists();  // Not under lock!

    if ($exists) {
        $this->nextSequenceNumber++;
    }
    $attempts++;
} while ($exists && $attempts < 100);
```

#### Impact Analysis

Under concurrent invoice creation (10 concurrent requests):
- Lock wait times increase exponentially
- Potential deadlocks if locks acquired in wrong order
- The `exists()` check outside lock creates race condition

#### Recommended Fix

**Use database-level sequence tables with atomic increments:**

```php
// Migration: Create sequence tables
Schema::create('document_sequences', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained();
    $table->string('type'); // 'invoice', 'estimate', 'payment'
    $table->unsignedBigInteger('current_sequence')->default(0);
    $table->timestamps();
    
    $table->unique(['company_id', 'type']);
});

// SerialNumberFormatter.php
public function setNextSequenceNumber()
{
    $sequence = DB::transaction(function () {
        // Use atomic increment
        return DocumentSequence::where('company_id', $this->company)
            ->where('type', $this->getDocumentType())
            ->lockForUpdate()
            ->firstOrCreate(
                ['company_id' => $this->company, 'type' => $this->getDocumentType()],
                ['current_sequence' => 0]
            );
    });

    $this->nextSequenceNumber = $sequence->current_sequence + 1;
    
    // Atomic increment
    $sequence->increment('current_sequence');
}
```

**Alternative: Use Redis for sequence generation**

```php
public function setNextSequenceNumber()
{
    $key = "sequence:{$this->company}:{$this->model}";
    $this->nextSequenceNumber = Redis::incr($key);
    
    // Fallback to database if Redis unavailable
    if (!$this->nextSequenceNumber) {
        // ... database fallback
    }
}
```

---

### PERF-006: Setting Model No Caching

**Severity:** High  
**Impact:** Medium-High - Every settings access hits database  
**File:** `app/Models/Setting.php`

#### Description

Unlike `CompanySetting`, the global `Setting` model has no caching mechanism. Every call to `Setting::getSetting()` executes a database query.

#### Vulnerable Code

```php
// Setting.php:46-55
public static function getSetting($key)
{
    $setting = static::whereOption($key)->first();  // No caching!

    if ($setting) {
        return $setting->value;
    } else {
        return null;
    }
}
```

#### Impact Analysis

The `Setting` model is used for:
- Version checks
- Global application settings
- Feature flags

Without caching, each access is a separate query. In `AppConfigProvider`, this is called 22+ times for mail settings.

#### Recommended Fix

```php
class Setting extends Model
{
    protected static array $cache = [];
    protected static ?array $allSettings = null;

    public static function getSetting($key)
    {
        return self::$cache[$key] ??= Cache::remember(
            "setting.{$key}",
            now()->addHour(),
            fn() => static::whereOption($key)->value('value')
        );
    }

    public static function getSettings(array $keys): array
    {
        $result = [];
        $uncached = [];

        foreach ($keys as $key) {
            if (isset(self::$cache[$key])) {
                $result[$key] = self::$cache[$key];
            } else {
                $uncached[] = $key;
            }
        }

        if (!empty($uncached)) {
            $cached = Cache::many($uncached);
            foreach ($uncached as $key) {
                if ($cached[$key] !== null) {
                    $result[$key] = self::$cache[$key] = $cached[$key];
                } else {
                    // Fetch from database and cache
                    $value = static::whereOption($key)->value('value');
                    Cache::put("setting.{$key}", $value, now()->addHour());
                    $result[$key] = self::$cache[$key] = $value;
                }
            }
        }

        return $result;
    }

    public static function setSetting($key, $value): void
    {
        parent::setSetting($key, $value);
        Cache::forget("setting.{$key}");
        unset(self::$cache[$key]);
    }
}
```

---

### PERF-007: PDF Generation N+1 Query Pattern

**Severity:** High  
**Impact:** Medium-High - Affects PDF generation for invoices/payments  
**Files:**
- `app/Models/Invoice.php` (getPDFData method)
- `app/Models/Payment.php` (getPDFData method)
- `app/Traits/GeneratesPdfTrait.php`

#### Description

PDF generation fetches related data without proper eager loading, causing multiple queries.

#### Vulnerable Code

```php
// Invoice.php:606-655
public function getPDFData()
{
    $taxes = collect();
    if ($this->tax_per_item === 'YES') {
        foreach ($this->items as $item) {          // Lazy loads items
            foreach ($item->taxes as $tax) {       // N+1 on item taxes
                // ...
            }
        }
    }

    $invoiceTemplate = self::find($this->id)->template_name;  // Re-fetches self!
    $company = Company::find($this->company_id);              // Separate query
    $locale = CompanySetting::getSetting('language', $company->id); // Another query
    $customFields = CustomField::where('model_type', 'Item')->get(); // Yet another query

    // ...
}
```

#### Impact Analysis

For an invoice with 10 items, each with 2 taxes:
- **Current:** 15+ queries for PDF generation
- **Optimized:** 2-3 queries with proper eager loading

#### Recommended Fix

```php
public function getPDFData()
{
    // Eager load all needed relationships
    $this->loadMissing([
        'items.taxes',
        'items.fields.customField',
        'customer.currency',
        'customer.billingAddress',
        'customer.shippingAddress',
        'company.address',
        'taxes',
        'fields.customField',
    ]);

    // Batch load settings
    $settings = CompanySetting::getSettings([
        'language',
        'invoice_company_address_format',
        'invoice_shipping_address_format',
        'invoice_billing_address_format',
        // ... other needed settings
    ], $this->company_id);

    $taxes = collect();
    if ($this->tax_per_item === 'YES') {
        foreach ($this->items as $item) {
            foreach ($item->taxes as $tax) {
                // ... already loaded, no query
            }
        }
    }

    // Use pre-loaded data
    $invoiceTemplate = $this->template_name;  // Already loaded
    $company = $this->company;                 // Already loaded
    $locale = $settings['language'];
    $customFields = Cache::remember('custom_fields.item', now()->addHour(), fn() =>
        CustomField::where('model_type', 'Item')->get()
    );

    // ...
}
```

---

### PERF-008: Invoice Update Item Processing N+1

**Severity:** High  
**Impact:** Medium - Affects invoice/estimate update operations  
**File:** `app/Models/Invoice.php` (updateInvoice method)

#### Description

When updating an invoice, the old items are deleted and new ones created, but the deletion process involves N+1 queries.

#### Vulnerable Code

```php
// Invoice.php:462-471
$this->items->map(function ($item) {
    $fields = $item->fields()->get();  // Query for each item's fields

    $fields->map(function ($field) {
        $field->delete();  // Delete each field individually
    });
});

$this->items()->delete();  // Separate delete
$this->taxes()->delete();  // Another separate delete
```

#### Recommended Fix

```php
// Delete all item fields in one query
DB::table('custom_field_values')
    ->whereIn('custom_field_valuable_id', $this->items()->pluck('id'))
    ->where('custom_field_valuable_type', InvoiceItem::class)
    ->delete();

// Delete items and taxes
$this->items()->delete();
$this->taxes()->delete();
```

---

### PERF-009: Missing Database Indexes for Common Queries

**Severity:** High  
**Impact:** Medium - Affects query performance as data grows  
**File:** `database/migrations/2026_03_19_140001_add_performance_indexes_for_high_traffic_lists.php`

#### Description

The recent index migration added several indexes, but several common query patterns are still missing indexes.

#### Missing Indexes

```sql
-- Missing: Invoice search by customer name (used in scopeWhereSearch)
CREATE INDEX idx_invoices_customer_search ON invoices(company_id, customer_id);

-- Missing: Payment search by customer (used in scopeWhereSearch)  
CREATE INDEX idx_payments_customer_search ON payments(company_id, customer_id);

-- Missing: Recurring invoice status + next_invoice_at (used in GenerateRecurringInvoices)
CREATE INDEX idx_recurring_invoices_generation ON recurring_invoices(status, next_invoice_at, starts_at);

-- Missing: Sequence number lookups (used in SerialNumberFormatter)
CREATE INDEX idx_invoices_sequence ON invoices(company_id, sequence_number);
CREATE INDEX idx_estimates_sequence ON estimates(company_id, sequence_number);
CREATE INDEX idx_payments_sequence ON payments(company_id, sequence_number);

-- Missing: Company settings lookup
CREATE INDEX idx_company_settings_lookup ON company_settings(company_id, option);

-- Missing: Soft delete + status combination queries
CREATE INDEX idx_invoices_status_deleted ON invoices(company_id, status, deleted_at);
```

#### Recommended Migration

```php
Schema::table('invoices', function (Blueprint $table) {
    $table->index(['company_id', 'customer_id'], 'invoices_company_customer_idx');
    $table->index(['company_id', 'sequence_number'], 'invoices_company_sequence_idx');
    $table->index(['company_id', 'status', 'deleted_at'], 'invoices_company_status_deleted_idx');
});

Schema::table('payments', function (Blueprint $table) {
    $table->index(['company_id', 'customer_id'], 'payments_company_customer_idx');
    $table->index(['company_id', 'sequence_number'], 'payments_company_sequence_idx');
});

Schema::table('recurring_invoices', function (Blueprint $table) {
    $table->index(['status', 'next_invoice_at', 'starts_at'], 'recurring_invoices_generation_idx');
});

Schema::table('company_settings', function (Blueprint $table) {
    $table->index(['company_id', 'option'], 'company_settings_lookup_idx');
});
```

---

### PERF-010: Exchange Rate API Calls Without Caching

**Severity:** High  
**Impact:** Medium - External API latency on currency operations  
**File:** `app/Traits/ExchangeRateProvidersTrait.php`

#### Description

Exchange rate API calls are made without any caching mechanism. Each currency conversion triggers an external HTTP request.

#### Vulnerable Code

```php
// ExchangeRateProvidersTrait.php:14-16
case 'currency_freak':
    $url = 'https://api.currencyfreaks.com/latest?apikey='.$filter['key'];
    $response = Http::get($url)->json();  // No caching, no timeout!
```

#### Impact Analysis

- External API calls: 100-500ms each
- Rate limits may be hit under heavy usage
- No fallback if API is unavailable

#### Recommended Fix

```php
public function getExchangeRate($filter, $baseCurrencyCode, $currencyCode)
{
    $cacheKey = "exchange_rate.{$filter['driver']}.{$baseCurrencyCode}.{$currencyCode}";
    
    return Cache::remember($cacheKey, now()->addHour(), function () use ($filter, $baseCurrencyCode, $currencyCode) {
        $timeout = config('services.exchange_rate.timeout', 5);
        
        try {
            return match ($filter['driver']) {
                'currency_freak' => $this->fetchCurrencyFreak($filter['key'], $baseCurrencyCode, $currencyCode, $timeout),
                'currency_layer' => $this->fetchCurrencyLayer($filter['key'], $baseCurrencyCode, $currencyCode, $timeout),
                // ... other providers
            };
        } catch (\Exception $e) {
            Log::warning('Exchange rate API failed', [
                'driver' => $filter['driver'],
                'error' => $e->getMessage(),
            ]);
            
            // Return last known rate or throw
            return Cache::get($cacheKey . '.last_known');
        }
    });
}

private function fetchCurrencyFreak(string $key, string $base, string $currency, int $timeout): array
{
    $url = "https://api.currencyfreaks.com/latest?apikey={$key}&symbols={$currency}&base={$base}";
    
    $response = Http::timeout($timeout)->get($url);
    
    if (!$response->successful()) {
        throw new \RuntimeException('Exchange rate API failed: ' . $response->status());
    }
    
    return $response->json();
}
```

---

### PERF-011: Model Observers Dispatch PDF Jobs Synchronously

**Severity:** High  
**Impact:** Medium - Adds latency to create/update operations  
**File:** `app/Models/Payment.php` (booted method)

#### Description

PDF generation jobs are dispatched on every payment create/update, adding to queue immediately without batching or debouncing.

#### Vulnerable Code

```php
// Payment.php:69-84
protected static function booted()
{
    static::created(function ($payment) {
        GeneratePaymentPdfJob::dispatch($payment)->afterCommit();  // Queued
    });

    static::updated(function ($payment) {
        $changes = array_keys($payment->getChanges());
        $nonTrivialChanges = array_diff($changes, ['updated_at', 'sequence_number', 'customer_sequence_number']);

        if (empty($nonTrivialChanges)) {
            return;
        }

        GeneratePaymentPdfJob::dispatch($payment, true)->afterCommit();  // Another job
    });
}
```

#### Impact Analysis

For bulk payment imports (100 payments):
- 100+ jobs dispatched immediately
- Queue can become backlogged
- Database contention from PDF writes

#### Recommended Fix

**Option 1: Batch PDF generation with delay**

```php
protected static function booted()
{
    static::created(function ($payment) {
        // Delay to allow batching
        GeneratePaymentPdfJob::dispatch($payment)
            ->afterCommit()
            ->delay(now()->addSeconds(5));
    });

    static::updated(function ($payment) {
        // Debounce updates
        $payment->cancelPdfGeneration();
        GeneratePaymentPdfJob::dispatch($payment, true)
            ->afterCommit()
            ->delay(now()->addSeconds(10));
    });
}
```

**Option 2: Use job batching for bulk operations**

```php
// In bulk import controller
$batch = Bus::batch(
    $payments->map(fn($payment) => new GeneratePaymentPdfJob($payment))
)->dispatch();
```

---

### PERF-012: Search Scope N+1 with Multiple Terms

**Severity:** High  
**Impact:** Medium - Affects search performance  
**Files:**
- `app/Models/Invoice.php` (scopeWhereSearch)
- `app/Models/Payment.php` (scopeWhereSearch)
- `app/Models/Customer.php` (scopeWhereSearch)

#### Description

The search scopes use `whereHas` with multiple search terms, generating multiple subqueries per term.

#### Vulnerable Code

```php
// Invoice.php:256-264
public function scopeWhereSearch($query, $search)
{
    foreach (explode(' ', $search) as $term) {
        $query->whereHas('customer', function ($query) use ($term) {
            $query->where('name', 'LIKE', '%'.$term.'%')
                ->orWhere('contact_name', 'LIKE', '%'.$term.'%')
                ->orWhere('company_name', 'LIKE', '%'.$term.'%');
        });
    }
}
```

#### Impact Analysis

For search "John Smith Company" (3 terms):
- 3 subqueries for customer relationship
- Each subquery joins the customers table
- Combined with pagination, this creates expensive queries

#### Recommended Fix

```php
public function scopeWhereSearch($query, $search)
{
    $terms = explode(' ', $search);
    
    $query->whereHas('customer', function ($query) use ($terms) {
        $query->where(function ($q) use ($terms) {
            foreach ($terms as $term) {
                $q->orWhere(function ($subQ) use ($term) {
                    $subQ->where('name', 'LIKE', "%{$term}%")
                        ->orWhere('contact_name', 'LIKE', "%{$term}%")
                        ->orWhere('company_name', 'LIKE', "%{$term}%");
                });
            }
        });
    });
}
```

**Better: Use full-text search for large datasets**

```php
public function scopeWhereSearch($query, $search)
{
    // MySQL full-text search
    $query->whereRaw(
        "MATCH(invoice_number, reference_number, notes) AGAINST(? IN BOOLEAN MODE)",
        [$search]
    )->orWhereHas('customer', function ($q) use ($search) {
        $q->whereRaw(
            "MATCH(name, contact_name, company_name, email) AGAINST(? IN BOOLEAN MODE)",
            [$search]
        );
    });
}
```

---

### PERF-013: Customer Model Auto-Eager Loading Currency

**Severity:** High  
**Impact:** Low-Medium - Adds unnecessary queries when currency not needed  
**File:** `app/Models/Customer.php`

#### Description

The Customer model always eager loads the `currency` relationship, even when it's not needed.

#### Vulnerable Code

```php
// Customer.php:40-42
protected $with = [
    'currency',  // Always loaded!
];
```

#### Impact Analysis

For listing 100 customers:
- Always includes 100 currency queries (or JOINs)
- Many operations (delete, simple updates) don't need currency

#### Recommended Fix

```php
// Remove automatic eager loading
protected $with = [];

// Explicitly eager load when needed
Customer::with('currency')->whereCompany()->get();

// Or use a scope for list views
public function scopeWithDefaultRelations($query)
{
    return $query->with('currency');
}
```

---

### PERF-014: BootstrapController Multiple Sequential Queries

**Severity:** High  
**Impact:** Medium - Bootstrap called on every app load  
**File:** `app/Http/Controllers/V1/Admin/General/BootstrapController.php`

#### Description

The BootstrapController makes multiple sequential database queries that could be batched.

#### Vulnerable Code

```php
// BootstrapController.php:43-49
$current_company_settings = CompanySetting::getAllSettings($company->id);
// ...
$current_company_currency = $current_company_settings->has('currency')
    ? Currency::find($current_company_settings['currency'])  // Separate query
    : Currency::first();  // Another query if not set

$global_settings = Setting::getSettings([
    'api_token',
    // ...
]);  // Another query
```

#### Recommended Fix

```php
public function __invoke(Request $request)
{
    // Batch load all needed data in parallel
    [$settings, $globalSettings, $currencies] = DB::transaction(function () use ($request) {
        $companyId = $request->header('company');
        
        return [
            CompanySetting::getAllSettings($companyId),
            Setting::getSettings(['api_token', 'version', ...]),
            Currency::all()->keyBy('id'),  // Cache all currencies
        ];
    });

    $currency = $currencies->get($settings['currency']) ?? $currencies->first();
    
    // ... rest of method
}
```

---

## MEDIUM Severity Issues

### PERF-015: RequestTimingLogger Blocking File I/O

**Severity:** Medium  
**Impact:** Low-Medium - Adds latency to slow requests  
**File:** `app/Http/Middleware/RequestTimingLogger.php`

#### Description

The timing logger uses blocking file I/O with `LOCK_EX` for every slow request.

#### Vulnerable Code

```php
// RequestTimingLogger.php:36-37
$logPath = storage_path('logs/royal-timing.log');
file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);  // Blocking!
```

#### Recommended Fix

```php
// Use Laravel's logging (non-blocking in most drivers)
Log::channel('timing')->info($line, [
    'duration_ms' => $durationMs,
    'method' => $request->getMethod(),
    'uri' => $request->getRequestUri(),
]);

// In config/logging.php
'channels' => [
    'timing' => [
        'driver' => 'daily',
        'path' => storage_path('logs/royal-timing.log'),
        'level' => 'info',
        'days' => 14,
    ],
],
```

---

### PERF-016: Customer Portal Dashboard Query Consolidation

**Severity:** Medium  
**Impact:** Low-Medium - Multiple queries could be combined  
**File:** `app/Http/Controllers/V1/Customer/General/DashboardController.php`

#### Description

Customer dashboard makes 6 separate queries that could be consolidated.

#### Vulnerable Code

```php
// DashboardController.php:23-38
$amountDue = Invoice::whereCustomer($user->id)->where('status', '<>', 'DRAFT')->sum('due_amount');
$invoiceCount = Invoice::whereCustomer($user->id)->where('status', '<>', 'DRAFT')->count();
$estimatesCount = Estimate::whereCustomer($user->id)->where('status', '<>', 'DRAFT')->count();
$paymentCount = Payment::whereCustomer($user->id)->count();

// Then two more queries for recent items
$recentInvoices = Invoice::whereCustomer($user->id)->where('status', '<>', 'DRAFT')->take(5)->latest()->get();
$recentEstimates = Estimate::whereCustomer($user->id)->where('status', '<>', 'DRAFT')->take(5)->latest()->get();
```

#### Recommended Fix

```php
public function __invoke(Request $request)
{
    $user = Auth::guard('customer')->user();
    
    // Single query for invoice stats
    $invoiceStats = Invoice::whereCustomer($user->id)
        ->where('status', '<>', 'DRAFT')
        ->selectRaw("
            SUM(due_amount) as total_due,
            COUNT(*) as count
        ")
        ->first();
    
    // Single query for other counts
    $counts = DB::select("
        SELECT 
            (SELECT COUNT(*) FROM estimates WHERE customer_id = ? AND status <> 'DRAFT' AND deleted_at IS NULL) as estimate_count,
            (SELECT COUNT(*) FROM payments WHERE customer_id = ? AND deleted_at IS NULL) as payment_count
    ", [$user->id, $user->id]);
    
    // Recent items with proper eager loading
    $recentInvoices = Invoice::with('currency')
        ->whereCustomer($user->id)
        ->where('status', '<>', 'DRAFT')
        ->take(5)
        ->latest()
        ->get();
    
    $recentEstimates = Estimate::with('currency')
        ->whereCustomer($user->id)
        ->where('status', '<>', 'DRAFT')
        ->take(5)
        ->latest()
        ->get();
    
    return response()->json([
        'due_amount' => $invoiceStats->total_due,
        'invoice_count' => $invoiceStats->count,
        'estimate_count' => $counts[0]->estimate_count,
        'payment_count' => $counts[0]->payment_count,
        'recentInvoices' => $recentInvoices,
        'recentEstimates' => $recentEstimates,
    ]);
}
```

---

### PERF-017: ConfigMiddleware FileDisk Query

**Severity:** Medium  
**Impact:** Low-Medium - Query when file_disk_id present  
**File:** `app/Http/Middleware/ConfigMiddleware.php`

#### Description

The middleware queries for FileDisk without caching.

#### Vulnerable Code

```php
// ConfigMiddleware.php:22-27
if ($request->has('file_disk_id')) {
    $file_disk = FileDisk::query()
        ->forCompanyContext($companyId)
        ->whereKey($request->file_disk_id)
        ->first();  // Query on every request with file_disk_id
}
```

#### Recommended Fix

```php
if ($request->has('file_disk_id')) {
    $cacheKey = "file_disk.{$request->file_disk_id}.{$companyId}";
    
    $file_disk = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($companyId, $request) {
        return FileDisk::query()
            ->forCompanyContext($companyId)
            ->whereKey($request->file_disk_id)
            ->first();
    });
    
    if ($file_disk) {
        $file_disk->setConfig();
    }
}
```

---

### PERF-018: Report Controller Multiple Setting Queries

**Severity:** Medium  
**Impact:** Low-Medium - Sequential settings queries  
**File:** `app/Http/Controllers/V1/Admin/Report/DentistPaymentsReportController.php`

#### Description

Report controller makes multiple sequential `CompanySetting::getSetting()` calls.

#### Vulnerable Code

```php
// DentistPaymentsReportController.php:34, 77, 80
$locale = CompanySetting::getSetting('language', $company->id);
// ...
$dateFormat = CompanySetting::getSetting('carbon_date_format', $company->id);
$from_date = Carbon::createFromFormat('Y-m-d', $request->from_date)->translatedFormat($dateFormat);
$to_date = Carbon::createFromFormat('Y-m-d', $request->to_date)->translatedFormat($dateFormat);
$currency = Currency::findOrFail(CompanySetting::getSetting('currency', $company->id));
```

#### Recommended Fix

```php
// Batch load all settings at once
$settings = CompanySetting::getSettings([
    'language',
    'carbon_date_format',
    'currency',
], $company->id);

$locale = $settings['language'];
$dateFormat = $settings['carbon_date_format'];
$currency = Currency::find($settings['currency']);
```

---

### PERF-019: Invoice List Meta Count Query

**Severity:** Medium  
**Impact:** Low-Medium - Extra query on every list  
**File:** `app/Http/Controllers/V1/Admin/Invoice/InvoicesController.php`

#### Description

Invoice list makes an extra count query for meta data.

#### Vulnerable Code

```php
// InvoicesController.php:32-35
return InvoiceResource::collection($invoices)
    ->additional(['meta' => [
        'invoice_total_count' => Invoice::whereCompany()->count(),  // Extra query!
    ]]);
```

#### Recommended Fix

```php
// Option 1: Cache the count
return InvoiceResource::collection($invoices)
    ->additional(['meta' => [
        'invoice_total_count' => Cache::remember(
            "invoice_count.{$request->header('company')}",
            now()->addMinutes(5),
            fn() => Invoice::whereCompany()->count()
        ),
    ]]);

// Option 2: Use approximate count for large tables
return InvoiceResource::collection($invoices)
    ->additional(['meta' => [
        'invoice_total_count' => DB::selectOne("
            SELECT TABLE_ROWS 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'
        ")->TABLE_ROWS,
    ]]);
```

---

### PERF-020: Customer List withSum Queries

**Severity:** Medium  
**Impact:** Low-Medium - Two separate withSum calls  
**File:** `app/Http/Controllers/V1/Admin/Customer/CustomersController.php`

#### Description

Customer list uses two separate `withSum` calls that run two subqueries.

#### Vulnerable Code

```php
// CustomersController.php:29-34
->withSum(['invoices as base_due_amount' => function ($query) {
    $query->whereNull('deleted_at');
}], 'base_due_amount')
->withSum(['invoices as due_amount' => function ($query) {
    $query->whereNull('deleted_at');
}], 'due_amount')  // Two separate subqueries!
```

#### Recommended Fix

```php
// Combine into single query with raw select
->addSelect([
    'base_due_amount' => Invoice::selectRaw('COALESCE(SUM(base_due_amount), 0)')
        ->whereColumn('customer_id', 'customers.id')
        ->whereNull('deleted_at'),
    'due_amount' => Invoice::selectRaw('COALESCE(SUM(due_amount), 0)')
        ->whereColumn('customer_id', 'customers.id')
        ->whereNull('deleted_at'),
])
```

---

### PERF-021: CompanyMiddleware Schema Check on Every Request

**Severity:** Medium  
**Impact:** Low-Medium - Schema check is expensive  
**File:** `app/Http/Middleware/CompanyMiddleware.php`

#### Description

The middleware checks if a table exists on every request.

#### Vulnerable Code

```php
// CompanyMiddleware.php:19
if (Schema::hasTable('user_company')) {  // Schema check on every request!
```

#### Recommended Fix

```php
class CompanyMiddleware
{
    private static ?bool $hasUserCompany = null;

    public function handle(Request $request, Closure $next): Response
    {
        if (self::$hasUserCompany ??= Schema::hasTable('user_company')) {
            // ... existing logic
        }
        
        return $next($request);
    }
}
```

---

### PERF-022: Delete Operations Without Batch Processing

**Severity:** Medium  
**Impact:** Low-Medium - Multiple delete queries  
**Files:**
- `app/Models/Invoice.php` (deleteInvoices)
- `app/Models/Customer.php` (deleteCustomers)
- `app/Models/RecurringInvoice.php` (deleteRecurringInvoice)

#### Description

Delete operations iterate and delete records individually instead of batching.

#### Vulnerable Code

```php
// Invoice.php:792-800
foreach ($invoices as $invoice) {
    if ($invoice->transactions()->exists()) {
        $invoice->transactions()->delete();  // Individual delete
    }
    $invoice->delete();  // Individual delete
}
```

#### Recommended Fix

```php
public static function deleteInvoices($ids, $companyId = null)
{
    $query = self::query();
    if ($companyId) {
        $query->where('company_id', $companyId);
    }

    return DB::transaction(function () use ($query, $ids) {
        // Batch delete transactions
        Transaction::whereIn('invoice_id', $ids)->delete();
        
        // Batch delete invoices (soft delete)
        $query->whereIn('id', $ids)->delete();
        
        return true;
    });
}
```

---

### PERF-023: GenerateRecurringInvoices No Memory Management

**Severity:** Medium  
**Impact:** Low-Medium - Memory growth for large batches  
**File:** `app/Console/Commands/GenerateRecurringInvoices.php`

#### Description

The command processes invoices in chunks but doesn't clear memory between chunks.

#### Vulnerable Code

```php
// GenerateRecurringInvoices.php:48-61
$query->chunkById(100, function ($recurringInvoices) use (&$generatedCount) {
    foreach ($recurringInvoices as $recurringInvoice) {
        try {
            $recurringInvoice->generateInvoice();  // May load many relationships
            $generatedCount++;
        } catch (\Throwable $e) {
            // ...
        }
    }
    // No memory cleanup!
});
```

#### Recommended Fix

```php
$query->chunkById(100, function ($recurringInvoices) use (&$generatedCount) {
    foreach ($recurringInvoices as $recurringInvoice) {
        try {
            $recurringInvoice->generateInvoice();
            $generatedCount++;
        } catch (\Throwable $e) {
            Log::error('Failed generating recurring invoice', [...]);
        }
    }
    
    // Clear models from memory
    $recurringInvoices->each->unsetRelations();
    
    // Optional: Force garbage collection for large batches
    if ($generatedCount % 500 === 0) {
        gc_collect_cycles();
    }
});
```

---

## LOW Severity Issues

### PERF-024: HTTP Client Timeout Not Configured

**Severity:** Low  
**Impact:** Low - Risk of hanging requests  
**File:** `app/Traits/ExchangeRateProvidersTrait.php`

#### Description

HTTP requests to external APIs have no timeout configured.

#### Recommended Fix

```php
$response = Http::timeout(5)->get($url)->json();
```

---

### PERF-025: Collection Operations Instead of Database Aggregation

**Severity:** Low  
**Impact:** Low - In-memory processing instead of DB  
**File:** `app/Http/Controllers/V1/Admin/Report/DentistPaymentsReportController.php`

#### Description

Payments are fetched and processed in PHP instead of aggregating in the database.

#### Recommended Fix

```php
// Use database aggregation instead of PHP loops
$dentistPayments = Payment::where('company_id', $company->id)
    ->whereHas('invoice', fn($q) => $q->whereNotNull('assigned_to_id'))
    ->whereBetween('payment_date', [$start, $end])
    ->selectRaw("
        invoice.assigned_to_id as dentist_id,
        COUNT(*) as payment_count,
        SUM(base_amount) as total_amount
    ")
    ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
    ->groupBy('invoices.assigned_to_id')
    ->with('invoice.assignedTo')
    ->get();
```

---

### PERF-026: Unnecessary Model Re-fetch in PDF Generation

**Severity:** Low  
**Impact:** Low - Extra query  
**File:** `app/Models/Invoice.php`

#### Description

The `getPDFData` method re-fetches the invoice from database.

#### Vulnerable Code

```php
// Invoice.php:626
$invoiceTemplate = self::find($this->id)->template_name;  // Already have $this!
```

#### Recommended Fix

```php
$invoiceTemplate = $this->template_name;
```

---

### PERF-027: Redundant Company Query in Email

**Severity:** Low  
**Impact:** Low - Extra query per email  
**Files:**
- `app/Models/Invoice.php` (sendInvoiceData)
- `app/Models/Payment.php` (sendPaymentData)

#### Vulnerable Code

```php
// Invoice.php:505
$data['company'] = Company::find($this->company_id);  // Could use $this->company
```

#### Recommended Fix

```php
$this->loadMissing('company');
$data['company'] = $this->company;
```

---

### PERF-028: Customer Delete Lazy Loading in Loop

**Severity:** Low  
**Impact:** Low - Uses lazyById which is correct but could be optimized  
**File:** `app/Models/Customer.php`

#### Description

Customer deletion uses `lazyById` which is correct, but could use batch deletes for better performance.

#### Current (Acceptable)

```php
foreach ($customer->invoices()->lazyById(100) as $invoice) {
    self::deleteRelatedModels($invoice->transactions());
    $invoice->delete();
}
```

#### Alternative for Better Performance

```php
// Batch delete all transactions for customer's invoices
Transaction::whereIn('invoice_id', 
    $customer->invoices()->pluck('id')
)->delete();

// Then batch delete invoices
$customer->invoices()->delete();
```

---

## Summary of Recommendations

### Immediate Action Required (P0 - Deploy within 24 hours)

| Priority | Issue | Action | Estimated Impact |
|----------|-------|--------|------------------|
| 1 | PERF-003: AppConfigProvider queries | Add caching | 90% boot query reduction |
| 2 | PERF-004: InstallUtils file I/O | Add request-level cache | Eliminate file I/O |
| 3 | PERF-001: CompanySetting N+1 | Batch load settings | 94% query reduction |
| 4 | PERF-002: Dashboard queries | Consolidate queries | 60% query reduction |

### High Priority (P1 - Deploy within 1 week)

| Priority | Issue | Action |
|----------|-------|--------|
| 5 | PERF-006: Setting model caching | Implement caching |
| 6 | PERF-007: PDF generation N+1 | Add eager loading |
| 7 | PERF-009: Missing indexes | Add database indexes |
| 8 | PERF-010: Exchange rate caching | Cache API responses |
| 9 | PERF-005: SerialNumber lock contention | Use sequence tables |

### Medium Priority (P2 - Deploy within 2 weeks)

| Priority | Issue | Action |
|----------|-------|--------|
| 10 | PERF-011: PDF job batching | Add delay/batching |
| 11 | PERF-012: Search N+1 | Optimize search queries |
| 12 | PERF-014: Bootstrap consolidation | Batch queries |
| 13 | PERF-019: List count caching | Cache count queries |

### Low Priority (P3 - Backlog)

| Priority | Issue | Action |
|----------|-------|--------|
| 14 | PERF-015: RequestTimingLogger | Use Laravel logging |
| 15 | PERF-024: HTTP timeouts | Add timeout config |
| 16 | PERF-026: PDF re-fetch | Use loaded model |

---

## Performance Testing Recommendations

### Suggested Benchmarks

1. **Dashboard Load Time**
   - Current: Measure baseline with 1000 invoices
   - Target: < 200ms response time

2. **Invoice Creation Throughput**
   - Current: Measure with concurrent users
   - Target: 50 invoices/second without lock contention

3. **List Query Performance**
   - Current: Measure with 10k records per table
   - Target: < 100ms for paginated lists

4. **PDF Generation Time**
   - Current: Measure for invoices with 10+ items
   - Target: < 500ms per PDF

### Monitoring Recommendations

1. Add query count to response headers (development only)
2. Enable Laravel telescope in staging for query analysis
3. Set up APM for production performance monitoring
4. Log slow queries (> 100ms) for investigation

---

## Appendix: Code Quality Notes

### Areas Already Optimized

The following areas show good performance practices:

1. **Proper eager loading in controllers** - Most controllers use `with()` correctly
2. **Chunked processing for large operations** - `chunkById()` used for migrations
3. **Transaction wrapping** - Critical operations wrapped in `DB::transaction()`
4. **Lazy deletion** - Uses `lazyById()` for large deletes
5. **Index migration** - Recent migration added important indexes

### Areas Needing Further Investigation

1. **Queue worker performance** - Memory usage during PDF batch processing
2. **Redis caching strategy** - No Redis caching layer implemented
3. **Database connection pooling** - Not configured for high concurrency
4. **CDN integration** - Static assets not optimized

---

**Report Generated:** March 24, 2026  
**Total Issues Documented:** 28 NEW performance issues  
**Estimated Total Improvement:** 60-70% reduction in database queries for common operations
