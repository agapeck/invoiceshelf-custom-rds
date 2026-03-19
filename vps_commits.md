diff --git a/INSTALLATION_GUIDE.md b/INSTALLATION_GUIDE.md
index e690d365..f26bdbc1 100644
--- a/INSTALLATION_GUIDE.md
+++ b/INSTALLATION_GUIDE.md
@@ -383,6 +383,122 @@ sudo ufw allow 80/tcp
 
 ---
 
+## Cloud/VPS Production Notes (Cloudflare + UFW + Redis)
+
+This section is for public cloud hosting (AWS, Hetzner, DigitalOcean, etc.) where traffic may pass through Cloudflare and Redis is used for cache/session/queue.
+
+### 1) Choose DNS/SSL mode first
+
+Your TLS certificate and firewall policy must match your Cloudflare DNS mode:
+
+- **Orange cloud (proxied)**:
+  - NGINX certificate can be **Cloudflare Origin Certificate**
+  - Cloudflare SSL mode: **Full (strict)**
+  - UFW for `80/443` should allow **Cloudflare IP ranges only**
+- **Grey cloud (DNS only)**:
+  - NGINX certificate must be **publicly trusted** (for example Let's Encrypt)
+  - Cloudflare Origin cert will show browser TLS warnings in DNS-only mode
+  - UFW for `80/443` must allow **public client IPs**
+
+If DNS mode and cert type do not match, the app may look broken even when Laravel itself is healthy.
+
+### 2) Cloudflare + wizard troubleshooting
+
+If browser console shows CSP report-only errors like `script-src 'none'` / `connect-src 'none'`, or Cloudflare challenge responses, check edge behavior first.
+
+- Temporarily disable challenge/protection for:
+  - `/installation*`
+  - `/api/v1/installation*`
+  - `/sanctum/csrf-cookie`
+- Confirm origin behavior directly from server:
+
+```bash
+curl -k -I -H 'Host: your-domain.com' https://127.0.0.1/installation
+curl -k -s -H 'Host: your-domain.com' https://127.0.0.1/api/v1/installation/wizard-step
+```
+
+If origin is healthy but public URL fails, the blocker is edge/WAF/DNS mode, not Laravel core.
+
+### 3) Mandatory preflight checks before running the wizard
+
+Run these checks first:
+
+```bash
+cd /var/www/invoiceshelf
+
+# Verify DB credentials exactly match MySQL user/password
+mysql -u invoiceshelf -p -h 127.0.0.1 -e "select 1;"
+
+# Verify app can run artisan without DB/auth failures
+sudo -u www-data php artisan about
+
+# Verify write paths
+sudo chown -R www-data:www-data storage bootstrap/cache
+sudo chmod -R 775 storage bootstrap/cache
+
+# Ensure APP_KEY exists
+grep '^APP_KEY=' .env
+```
+
+### 4) Redis production profile (recommended)
+
+For this repository's production setup:
+
+```dotenv
+SESSION_DRIVER=redis
+CACHE_DRIVER=redis
+QUEUE_CONNECTION=redis
+```
+
+Quick health checks:
+
+```bash
+cd /var/www/invoiceshelf
+sudo -u www-data env HOME=/tmp php artisan tinker --execute="echo 'redis='.(\Illuminate\Support\Facades\Redis::ping()).PHP_EOL; echo 'db='.(\Illuminate\Support\Facades\DB::select('select 1 as ok')[0]->ok).PHP_EOL;"
+```
+
+### 5) Known installer blocker in this custom branch
+
+If Step 3 (Site URL & Database) crashes during migration with `file_disks.credentials` JSON/check constraint errors:
+
+- Ensure migration `database/migrations/2020_12_02_090527_update_crater_version_400.php` seeds `file_disks` with `DB::table()->insert(...)` (raw JSON), not model writes that may encrypt credentials too early.
+
+### 6) Manual non-wizard recovery (fresh install fallback)
+
+If wizard flow remains blocked and you need a clean install immediately:
+
+```bash
+cd /var/www/invoiceshelf
+sudo -u www-data php artisan down
+sudo -u www-data env HOME=/tmp php artisan migrate:fresh --seed --force
+```
+
+Then set:
+- admin user details
+- company name/address/contact
+- `settings.profile_complete = COMPLETED`
+- `settings.profile_language = en` (or your language)
+- `storage/app/database_created` marker file
+
+Finally:
+
+```bash
+sudo -u www-data php artisan optimize:clear
+sudo -u www-data php artisan up
+```
+
+Use this fallback only when you explicitly want a fresh/empty dataset.
+
+### 7) Post-install security closeout (cloud)
+
+- Change default admin password immediately
+- Keep `APP_DEBUG=false`
+- If SMTP is not ready, set `MAIL_MAILER=log` until configured
+- Keep Cloudflare mode/certificate/UFW aligned
+- Keep `/installation` inaccessible in normal operation (app should redirect when completed)
+
+---
+
 ## Troubleshooting
 
 ### Common Issues:
@@ -839,4 +955,3 @@ Once services are running, access InvoiceShelf at:
 - **LAN**: `http://your-computer-ip:8000`
 
 ---
-
diff --git a/RDS_OPTIMIZATIONS.md b/RDS_OPTIMIZATIONS.md
new file mode 100644
index 00000000..345bdb67
--- /dev/null
+++ b/RDS_OPTIMIZATIONS.md
@@ -0,0 +1,18 @@
+# RDS App Performance Notes
+
+## Database
+- MariaDB tuned for the Royal Dental Services workload: `innodb_buffer_pool_size` bumped to ~1 GB and both `tmp_table_size`/`max_heap_table_size` set to 64 MB so larger joins and sorts stay in-memory instead of spilling to disk.
+- Added selective indexes on `created_at` for invoices, expenses, payments, and estimates plus lookup indexes on expense categories/payment methods, which keeps the high-traffic lists performant.
+- `opcache.validate_timestamps` is disabled on this instance (`0`) so PHP-FPM must be reloaded after deployments (the manual `systemctl restart php8.2-fpm` step ensures opcode caches see code changes).
+
+## Redis / Cache / OPCache
+- Redis powers sessions, cache, and queues (`SESSION_DRIVER`, `CACHE_DRIVER`, `QUEUE_CONNECTION` are all `redis` per the production `.env` workflow). `config/queue.php` and `config/session.php` already point to the configured `phpredis` connection, and the extra caching was exercised with `php artisan config:cache`, `route:cache`, and `view:cache` runs to populate optimized files.
+- Request timing logging (`App\Http\Middleware\RequestTimingLogger`) is enabled; logs land in `storage/logs/royal-timing.log` so we can spot slow endpoints and regressions without adding more instrumentation.
+- Eager-loading improvements across invoices/payments/estimates/recurring invoices reduce round-trips, allowing Redis-backed caches to stay warm and time-sensitive pages to return faster.
+
+## PHP-FPM
+- The app runs on system `php8.2-fpm`; with OPCache time-stamping disabled, every deployment explicitly restarts the service to flush stale bytecode and pick up the newly edited controllers/requests/resources.
+- Restarting `php8.2-fpm` also ensures the MariaDB connection pool reuses the optimized settings, so both PHP and the database benefit from the same maintenance window.
+
+## Observability
+- Beyond the timing log, Laravel cache tools remain in place via `php artisan optimize`, which rebuilds the compiled container, routes, and config for each release; this keeps Redis-backed caches in sync with code changes.
diff --git a/app/Http/Controllers/V1/Admin/Customer/CustomerStatsController.php b/app/Http/Controllers/V1/Admin/Customer/CustomerStatsController.php
index 1476a407..7ae30391 100755
--- a/app/Http/Controllers/V1/Admin/Customer/CustomerStatsController.php
+++ b/app/Http/Controllers/V1/Admin/Customer/CustomerStatsController.php
@@ -132,7 +132,7 @@ class CustomerStatsController extends Controller
             'totalExpenses' => $totalExpenses,
         ];
 
-        $customer = Customer::find($customer->id);
+        $customer->load(['billingAddress', 'shippingAddress', 'fields', 'company', 'currency', 'creator']);
 
         return (new CustomerResource($customer))
             ->additional(['meta' => [
diff --git a/app/Http/Controllers/V1/Admin/Customer/CustomersController.php b/app/Http/Controllers/V1/Admin/Customer/CustomersController.php
index b51a0206..46f9aba2 100755
--- a/app/Http/Controllers/V1/Admin/Customer/CustomersController.php
+++ b/app/Http/Controllers/V1/Admin/Customer/CustomersController.php
@@ -8,7 +8,6 @@ use App\Http\Requests\DeleteCustomersRequest;
 use App\Http\Resources\CustomerResource;
 use App\Models\Customer;
 use Illuminate\Http\Request;
-use Illuminate\Support\Facades\DB;
 
 class CustomersController extends Controller
 {
@@ -23,19 +22,15 @@ class CustomersController extends Controller
 
         $limit = $request->has('limit') ? $request->limit : 10;
 
-        $customers = Customer::with('creator')
+        $customers = Customer::with('creator', 'currency')
             ->whereCompany()
             ->applyFilters($request->all())
-            ->select(
-                'customers.*',
-                DB::raw('sum(invoices.base_due_amount) as base_due_amount'),
-                DB::raw('sum(invoices.due_amount) as due_amount'),
-            )
-            ->groupBy('customers.id')
-            ->leftJoin('invoices', function ($join) {
-                $join->on('customers.id', '=', 'invoices.customer_id')
-                    ->whereNull('invoices.deleted_at');
-            })
+            ->withSum(['invoices as base_due_amount' => function ($query) {
+                $query->whereNull('deleted_at');
+            }], 'base_due_amount')
+            ->withSum(['invoices as due_amount' => function ($query) {
+                $query->whereNull('deleted_at');
+            }], 'due_amount')
             ->paginateData($limit);
 
         return CustomerResource::collection($customers)
@@ -77,6 +72,8 @@ class CustomersController extends Controller
     {
         $this->authorize('view', $customer);
 
+        $customer->load(['billingAddress', 'shippingAddress', 'fields', 'company', 'currency', 'creator']);
+
         return new CustomerResource($customer);
     }
 
@@ -109,7 +106,7 @@ class CustomersController extends Controller
     {
         $this->authorize('delete multiple customers');
 
-        Customer::deleteCustomers($request->ids);
+        Customer::deleteCustomers($request->ids, $request->header('company'));
 
         return response()->json([
             'success' => true,
diff --git a/app/Http/Controllers/V1/Admin/Customer/UpdatePatientInfoController.php b/app/Http/Controllers/V1/Admin/Customer/UpdatePatientInfoController.php
index 6b7190d5..5a47b2bf 100644
--- a/app/Http/Controllers/V1/Admin/Customer/UpdatePatientInfoController.php
+++ b/app/Http/Controllers/V1/Admin/Customer/UpdatePatientInfoController.php
@@ -28,6 +28,8 @@ class UpdatePatientInfoController extends Controller
 
         $customer->update($validated);
 
+        $customer->load(['billingAddress', 'shippingAddress', 'fields', 'company', 'currency', 'creator']);
+
         return new CustomerResource($customer);
     }
 }
diff --git a/app/Http/Controllers/V1/Admin/Dashboard/DashboardController.php b/app/Http/Controllers/V1/Admin/Dashboard/DashboardController.php
index cb21ecae..97b1c1f7 100755
--- a/app/Http/Controllers/V1/Admin/Dashboard/DashboardController.php
+++ b/app/Http/Controllers/V1/Admin/Dashboard/DashboardController.php
@@ -138,13 +138,17 @@ class DashboardController extends Controller
         $total_amount_due = Invoice::whereCompany()
             ->sum('base_due_amount');
 
-        $recent_due_invoices = Invoice::with(['customer', 'currency'])
+        $recent_due_invoices = Invoice::with(['customer.currency', 'currency'])
             ->whereCompany()
             ->where('base_due_amount', '>', 0)
             ->take(5)
             ->latest()
             ->get();
-        $recent_estimates = Estimate::with(['customer', 'currency'])->whereCompany()->take(5)->latest()->get();
+        $recent_estimates = Estimate::with(['customer.currency', 'currency'])
+            ->whereCompany()
+            ->take(5)
+            ->latest()
+            ->get();
 
         return response()->json([
             'total_amount_due' => $total_amount_due,
diff --git a/app/Http/Controllers/V1/Admin/Estimate/CloneEstimateController.php b/app/Http/Controllers/V1/Admin/Estimate/CloneEstimateController.php
index f5fdcf5e..4dc0cbb8 100755
--- a/app/Http/Controllers/V1/Admin/Estimate/CloneEstimateController.php
+++ b/app/Http/Controllers/V1/Admin/Estimate/CloneEstimateController.php
@@ -125,6 +125,19 @@ class CloneEstimateController extends Controller
             $newEstimate->addCustomFields($customFields);
         }
 
+        $newEstimate->load([
+            'items',
+            'items.taxes',
+            'items.fields',
+            'items.fields.customField',
+            'customer.currency',
+            'taxes',
+            'creator',
+            'fields',
+            'company',
+            'currency',
+        ]);
+
         return new EstimateResource($newEstimate);
     }
 }
diff --git a/app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php b/app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php
index 6044b0f8..a1656c4f 100755
--- a/app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php
+++ b/app/Http/Controllers/V1/Admin/Estimate/ConvertEstimateController.php
@@ -125,6 +125,18 @@ class ConvertEstimateController extends Controller
         $estimate->checkForEstimateConvertAction();
 
         $invoice = Invoice::find($invoice->id);
+        $invoice->load([
+            'items',
+            'items.fields',
+            'items.fields.customField',
+            'customer.currency',
+            'taxes',
+            'creator',
+            'assignedTo',
+            'fields',
+            'company',
+            'currency',
+        ]);
 
         return new InvoiceResource($invoice);
     }
diff --git a/app/Http/Controllers/V1/Admin/Estimate/EstimatesController.php b/app/Http/Controllers/V1/Admin/Estimate/EstimatesController.php
index 31a415da..2b037c3f 100755
--- a/app/Http/Controllers/V1/Admin/Estimate/EstimatesController.php
+++ b/app/Http/Controllers/V1/Admin/Estimate/EstimatesController.php
@@ -19,6 +19,7 @@ class EstimatesController extends Controller
         $limit = $request->has('limit') ? $request->limit : 10;
 
         $estimates = Estimate::whereCompany()
+            ->with(['customer.currency', 'currency'])
             ->join('customers', 'customers.id', '=', 'estimates.customer_id')
             ->applyFilters($request->all())
             ->select('estimates.*', 'customers.name')
@@ -36,6 +37,18 @@ class EstimatesController extends Controller
         $this->authorize('create', Estimate::class);
 
         $estimate = Estimate::createEstimate($request);
+        $estimate->load([
+            'items',
+            'items.taxes',
+            'items.fields',
+            'items.fields.customField',
+            'customer.currency',
+            'taxes',
+            'creator',
+            'fields',
+            'company',
+            'currency',
+        ]);
 
         if ($request->has('estimateSend')) {
             $estimate->send($request->title, $request->body);
@@ -50,6 +63,19 @@ class EstimatesController extends Controller
     {
         $this->authorize('view', $estimate);
 
+        $estimate->load([
+            'items',
+            'items.taxes',
+            'items.fields',
+            'items.fields.customField',
+            'customer.currency',
+            'taxes',
+            'creator',
+            'fields',
+            'company',
+            'currency',
+        ]);
+
         return new EstimateResource($estimate);
     }
 
@@ -58,6 +84,18 @@ class EstimatesController extends Controller
         $this->authorize('update', $estimate);
 
         $estimate = $estimate->updateEstimate($request);
+        $estimate->load([
+            'items',
+            'items.taxes',
+            'items.fields',
+            'items.fields.customField',
+            'customer.currency',
+            'taxes',
+            'creator',
+            'fields',
+            'company',
+            'currency',
+        ]);
 
         GenerateEstimatePdfJob::dispatch($estimate, true);
 
@@ -68,7 +106,12 @@ class EstimatesController extends Controller
     {
         $this->authorize('delete multiple estimates');
 
-        Estimate::destroy($request->ids);
+        $companyId = $request->header('company');
+        Estimate::where('company_id', $companyId)
+            ->whereIn('id', $request->ids)
+            ->get()
+            ->each
+            ->delete();
 
         return response()->json([
             'success' => true,
diff --git a/app/Http/Controllers/V1/Admin/Expense/ExpenseCategoriesController.php b/app/Http/Controllers/V1/Admin/Expense/ExpenseCategoriesController.php
index e56dc3f5..a453505f 100755
--- a/app/Http/Controllers/V1/Admin/Expense/ExpenseCategoriesController.php
+++ b/app/Http/Controllers/V1/Admin/Expense/ExpenseCategoriesController.php
@@ -23,6 +23,8 @@ class ExpenseCategoriesController extends Controller
 
         $categories = ExpenseCategory::applyFilters($request->all())
             ->whereCompany()
+            ->with(['company'])
+            ->withSum('expenses as amount', 'amount')
             ->latest()
             ->paginateData($limit);
 
@@ -40,6 +42,7 @@ class ExpenseCategoriesController extends Controller
         $this->authorize('create', ExpenseCategory::class);
 
         $category = ExpenseCategory::create($request->getExpenseCategoryPayload());
+        $category->load('company');
 
         return new ExpenseCategoryResource($category);
     }
@@ -53,6 +56,8 @@ class ExpenseCategoriesController extends Controller
     {
         $this->authorize('view', $category);
 
+        $category->load('company');
+
         return new ExpenseCategoryResource($category);
     }
 
@@ -68,6 +73,7 @@ class ExpenseCategoriesController extends Controller
         $this->authorize('update', $category);
 
         $category->update($request->getExpenseCategoryPayload());
+        $category->load('company');
 
         return new ExpenseCategoryResource($category);
     }
diff --git a/app/Http/Controllers/V1/Admin/Expense/ExpensesController.php b/app/Http/Controllers/V1/Admin/Expense/ExpensesController.php
index 165f04e8..27140d9b 100755
--- a/app/Http/Controllers/V1/Admin/Expense/ExpensesController.php
+++ b/app/Http/Controllers/V1/Admin/Expense/ExpensesController.php
@@ -22,7 +22,7 @@ class ExpensesController extends Controller
 
         $limit = $request->has('limit') ? $request->limit : 10;
 
-        $expenses = Expense::with('category', 'creator', 'fields')
+        $expenses = Expense::with('category', 'creator', 'fields', 'customer', 'paymentMethod', 'company', 'currency')
             ->whereCompany()
             ->leftJoin('customers', 'customers.id', '=', 'expenses.customer_id')
             ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
@@ -47,6 +47,8 @@ class ExpensesController extends Controller
 
         $expense = Expense::createExpense($request);
 
+        $expense->load(['category', 'customer', 'creator', 'fields', 'company', 'currency', 'paymentMethod']);
+
         return new ExpenseResource($expense);
     }
 
@@ -59,6 +61,8 @@ class ExpensesController extends Controller
     {
         $this->authorize('view', $expense);
 
+        $expense->load(['category', 'customer', 'creator', 'fields', 'company', 'currency', 'paymentMethod']);
+
         return new ExpenseResource($expense);
     }
 
@@ -73,6 +77,8 @@ class ExpensesController extends Controller
 
         $expense->updateExpense($request);
 
+        $expense->load(['category', 'customer', 'creator', 'fields', 'company', 'currency', 'paymentMethod']);
+
         return new ExpenseResource($expense);
     }
 
@@ -80,7 +86,12 @@ class ExpensesController extends Controller
     {
         $this->authorize('delete multiple expenses');
 
-        Expense::destroy($request->ids);
+        $companyId = $request->header('company');
+        Expense::where('company_id', $companyId)
+            ->whereIn('id', $request->ids)
+            ->get()
+            ->each
+            ->delete();
 
         return response()->json([
             'success' => true,
diff --git a/app/Http/Controllers/V1/Admin/Invoice/CloneInvoiceController.php b/app/Http/Controllers/V1/Admin/Invoice/CloneInvoiceController.php
index 73d1dc5e..46c95c15 100755
--- a/app/Http/Controllers/V1/Admin/Invoice/CloneInvoiceController.php
+++ b/app/Http/Controllers/V1/Admin/Invoice/CloneInvoiceController.php
@@ -136,6 +136,19 @@ class CloneInvoiceController extends Controller
             $newInvoice->addCustomFields($customFields);
         }
 
+        $newInvoice->load([
+            'items',
+            'items.fields',
+            'items.fields.customField',
+            'customer.currency',
+            'taxes',
+            'creator',
+            'assignedTo',
+            'fields',
+            'company',
+            'currency',
+        ]);
+
         return new InvoiceResource($newInvoice);
     }
 }
diff --git a/app/Http/Controllers/V1/Admin/Invoice/InvoicesController.php b/app/Http/Controllers/V1/Admin/Invoice/InvoicesController.php
index 6be25f29..638cb0a4 100755
--- a/app/Http/Controllers/V1/Admin/Invoice/InvoicesController.php
+++ b/app/Http/Controllers/V1/Admin/Invoice/InvoicesController.php
@@ -25,7 +25,7 @@ class InvoicesController extends Controller
 
         $invoices = Invoice::whereCompany()
             ->applyFilters($request->all())
-            ->with(['customer', 'currency'])
+            ->with(['customer.currency', 'currency'])
             ->latest()
             ->paginateData($limit);
 
@@ -65,6 +65,19 @@ class InvoicesController extends Controller
     {
         $this->authorize('view', $invoice);
 
+        $invoice->load([
+            'items',
+            'items.fields',
+            'items.fields.customField',
+            'customer.currency',
+            'taxes',
+            'creator',
+            'assignedTo',
+            'fields',
+            'company',
+            'currency',
+        ]);
+
         return new InvoiceResource($invoice);
     }
 
@@ -99,7 +112,7 @@ class InvoicesController extends Controller
     {
         $this->authorize('delete multiple invoices');
 
-        Invoice::deleteInvoices($request->ids);
+        Invoice::deleteInvoices($request->ids, $request->header('company'));
 
         return response()->json([
             'success' => true,
diff --git a/app/Http/Controllers/V1/Admin/Item/ItemsController.php b/app/Http/Controllers/V1/Admin/Item/ItemsController.php
index 17274382..df7daa1c 100755
--- a/app/Http/Controllers/V1/Admin/Item/ItemsController.php
+++ b/app/Http/Controllers/V1/Admin/Item/ItemsController.php
@@ -89,7 +89,12 @@ class ItemsController extends Controller
     {
         $this->authorize('delete multiple items');
 
-        Item::destroy($request->ids);
+        $companyId = $request->header('company');
+        Item::where('company_id', $companyId)
+            ->whereIn('id', $request->ids)
+            ->get()
+            ->each
+            ->delete();
 
         return response()->json([
             'success' => true,
diff --git a/app/Http/Controllers/V1/Admin/Payment/PaymentMethodsController.php b/app/Http/Controllers/V1/Admin/Payment/PaymentMethodsController.php
index 2c02b4ed..ebc1f160 100755
--- a/app/Http/Controllers/V1/Admin/Payment/PaymentMethodsController.php
+++ b/app/Http/Controllers/V1/Admin/Payment/PaymentMethodsController.php
@@ -24,6 +24,7 @@ class PaymentMethodsController extends Controller
         $paymentMethods = PaymentMethod::applyFilters($request->all())
             ->where('type', PaymentMethod::TYPE_GENERAL)
             ->whereCompany()
+            ->with(['company'])
             ->latest()
             ->paginateData($limit);
 
@@ -41,6 +42,7 @@ class PaymentMethodsController extends Controller
         $this->authorize('create', PaymentMethod::class);
 
         $paymentMethod = PaymentMethod::createPaymentMethod($request);
+        $paymentMethod->load('company');
 
         return new PaymentMethodResource($paymentMethod);
     }
@@ -54,6 +56,8 @@ class PaymentMethodsController extends Controller
     {
         $this->authorize('view', $paymentMethod);
 
+        $paymentMethod->load('company');
+
         return new PaymentMethodResource($paymentMethod);
     }
 
@@ -68,6 +72,7 @@ class PaymentMethodsController extends Controller
         $this->authorize('update', $paymentMethod);
 
         $paymentMethod->update($request->getPaymentMethodPayload());
+        $paymentMethod->load('company');
 
         return new PaymentMethodResource($paymentMethod);
     }
diff --git a/app/Http/Controllers/V1/Admin/Payment/PaymentsController.php b/app/Http/Controllers/V1/Admin/Payment/PaymentsController.php
index e25630d8..f0c1ac9f 100755
--- a/app/Http/Controllers/V1/Admin/Payment/PaymentsController.php
+++ b/app/Http/Controllers/V1/Admin/Payment/PaymentsController.php
@@ -23,6 +23,7 @@ class PaymentsController extends Controller
         $limit = $request->has('limit') ? $request->limit : 10;
 
         $payments = Payment::whereCompany()
+            ->with(['customer.currency', 'invoice', 'paymentMethod', 'company', 'currency'])
             ->join('customers', 'customers.id', '=', 'payments.customer_id')
             ->leftJoin('invoices', 'invoices.id', '=', 'payments.invoice_id')
             ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
@@ -56,6 +57,8 @@ class PaymentsController extends Controller
     {
         $this->authorize('view', $payment);
 
+        $payment->load(['customer.currency', 'invoice', 'paymentMethod', 'fields', 'company', 'currency', 'transaction']);
+
         return new PaymentResource($payment);
     }
 
diff --git a/app/Http/Controllers/V1/Admin/RecurringInvoice/RecurringInvoiceController.php b/app/Http/Controllers/V1/Admin/RecurringInvoice/RecurringInvoiceController.php
index 6a02c25b..7024c987 100755
--- a/app/Http/Controllers/V1/Admin/RecurringInvoice/RecurringInvoiceController.php
+++ b/app/Http/Controllers/V1/Admin/RecurringInvoice/RecurringInvoiceController.php
@@ -22,6 +22,7 @@ class RecurringInvoiceController extends Controller
         $limit = $request->has('limit') ? $request->limit : 10;
 
         $recurringInvoices = RecurringInvoice::whereCompany()
+            ->with(['customer.currency', 'currency'])
             ->applyFilters($request->all())
             ->paginateData($limit);
 
@@ -42,6 +43,7 @@ class RecurringInvoiceController extends Controller
         $this->authorize('create', RecurringInvoice::class);
 
         $recurringInvoice = RecurringInvoice::createFromRequest($request);
+        $recurringInvoice->load(['customer.currency', 'currency']);
 
         return new RecurringInvoiceResource($recurringInvoice);
     }
@@ -55,6 +57,20 @@ class RecurringInvoiceController extends Controller
     {
         $this->authorize('view', $recurringInvoice);
 
+        $recurringInvoice->load([
+            'items',
+            'items.fields',
+            'items.fields.customField',
+            'fields',
+            'taxes',
+            'customer.currency',
+            'creator',
+            'company',
+            'currency',
+            'invoices.customer.currency',
+            'invoices.currency',
+        ]);
+
         return new RecurringInvoiceResource($recurringInvoice);
     }
 
@@ -69,6 +85,7 @@ class RecurringInvoiceController extends Controller
         $this->authorize('update', $recurringInvoice);
 
         $recurringInvoice->updateFromRequest($request);
+        $recurringInvoice->load(['customer.currency', 'currency']);
 
         return new RecurringInvoiceResource($recurringInvoice);
     }
@@ -83,7 +100,7 @@ class RecurringInvoiceController extends Controller
     {
         $this->authorize('delete multiple recurring invoices');
 
-        RecurringInvoice::deleteRecurringInvoice($request->ids);
+        RecurringInvoice::deleteRecurringInvoice($request->ids, $request->header('company'));
 
         return response()->json([
             'success' => true,
diff --git a/app/Http/Controllers/V1/Admin/Role/RolesController.php b/app/Http/Controllers/V1/Admin/Role/RolesController.php
index bfe42b7f..54b89113 100755
--- a/app/Http/Controllers/V1/Admin/Role/RolesController.php
+++ b/app/Http/Controllers/V1/Admin/Role/RolesController.php
@@ -22,7 +22,11 @@ class RolesController extends Controller
         $this->authorize('viewAny', Role::class);
 
         $roles = Role::when($request->has('orderByField'), function ($query) use ($request) {
-            return $query->orderBy($request['orderByField'], $request['orderBy']);
+            $allowed = ['id', 'name', 'title', 'created_at'];
+            $field = in_array($request['orderByField'], $allowed, true) ? $request['orderByField'] : 'name';
+            $direction = strtolower($request['orderBy'] ?? 'asc');
+            $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';
+            return $query->orderBy($field, $direction);
         })
             ->when($request->company_id, function ($query) use ($request) {
                 return $query->where('scope', $request->company_id);
diff --git a/app/Http/Controllers/V1/Admin/Users/UsersController.php b/app/Http/Controllers/V1/Admin/Users/UsersController.php
index d825a9db..af664aac 100755
--- a/app/Http/Controllers/V1/Admin/Users/UsersController.php
+++ b/app/Http/Controllers/V1/Admin/Users/UsersController.php
@@ -88,7 +88,7 @@ class UsersController extends Controller
         $this->authorize('delete multiple users', User::class);
 
         if ($request->users) {
-            User::deleteUsers($request->users);
+            User::deleteUsers($request->users, $request->header('company'));
         }
 
         return response()->json([
diff --git a/app/Http/Controllers/V1/Customer/EstimatePdfController.php b/app/Http/Controllers/V1/Customer/EstimatePdfController.php
index be2b59ed..8265931d 100755
--- a/app/Http/Controllers/V1/Customer/EstimatePdfController.php
+++ b/app/Http/Controllers/V1/Customer/EstimatePdfController.php
@@ -28,7 +28,9 @@ class EstimatePdfController extends Controller
 
                 if ($notifyEstimateViewed == 'YES') {
                     $data['estimate'] = Estimate::findOrFail($estimate->id)->toArray();
-                    $data['user'] = Customer::find($estimate->customer_id)->toArray();
+                    $customer = Customer::where('company_id', $estimate->company_id)
+                        ->find($estimate->customer_id);
+                    $data['user'] = $customer ? $customer->toArray() : [];
                     $notificationEmail = CompanySetting::getSetting(
                         'notification_email',
                         $estimate->company_id
@@ -47,6 +49,20 @@ class EstimatePdfController extends Controller
     public function getEstimate(EmailLog $emailLog)
     {
         $estimate = Estimate::find($emailLog->mailable_id);
+        if ($estimate) {
+            $estimate->load([
+                'items',
+                'items.taxes',
+                'items.fields',
+                'items.fields.customField',
+                'customer.currency',
+                'taxes',
+                'creator',
+                'fields',
+                'company',
+                'currency',
+            ]);
+        }
 
         return new EstimateResource($estimate);
     }
diff --git a/app/Http/Controllers/V1/Customer/InvoicePdfController.php b/app/Http/Controllers/V1/Customer/InvoicePdfController.php
index 329d3565..ac9986c1 100755
--- a/app/Http/Controllers/V1/Customer/InvoicePdfController.php
+++ b/app/Http/Controllers/V1/Customer/InvoicePdfController.php
@@ -29,7 +29,9 @@ class InvoicePdfController extends Controller
 
                 if ($notifyInvoiceViewed == 'YES') {
                     $data['invoice'] = Invoice::findOrFail($invoice->id)->toArray();
-                    $data['user'] = Customer::find($invoice->customer_id)->toArray();
+                    $customer = Customer::where('company_id', $invoice->company_id)
+                        ->find($invoice->customer_id);
+                    $data['user'] = $customer ? $customer->toArray() : [];
                     $notificationEmail = CompanySetting::getSetting(
                         'notification_email',
                         $invoice->company_id
diff --git a/app/Http/Controllers/V1/Customer/PaymentPdfController.php b/app/Http/Controllers/V1/Customer/PaymentPdfController.php
index bf6ea7d5..80662219 100755
--- a/app/Http/Controllers/V1/Customer/PaymentPdfController.php
+++ b/app/Http/Controllers/V1/Customer/PaymentPdfController.php
@@ -21,7 +21,15 @@ class PaymentPdfController extends Controller
 
     public function getPayment(EmailLog $emailLog)
     {
-        $payment = Payment::find($emailLog->mailable_id);
+        $payment = Payment::with([
+            'customer.currency',
+            'invoice',
+            'paymentMethod',
+            'fields',
+            'company',
+            'currency',
+            'transaction',
+        ])->find($emailLog->mailable_id);
 
         return new PaymentResource($payment);
     }
diff --git a/app/Http/Middleware/ConfigMiddleware.php b/app/Http/Middleware/ConfigMiddleware.php
index bdff4b8e..e0be3879 100755
--- a/app/Http/Middleware/ConfigMiddleware.php
+++ b/app/Http/Middleware/ConfigMiddleware.php
@@ -19,8 +19,11 @@ class ConfigMiddleware
     {
         if (InstallUtils::isDbCreated()) {
             // Only handle dynamic file disk switching when file_disk_id is provided
-            if ($request->has('file_disk_id')) {
-                $file_disk = FileDisk::find($request->file_disk_id);
+            if ($request->has('file_disk_id') && $request->hasHeader('company')) {
+                $fileDiskQuery = FileDisk::query()
+                    ->where('company_id', $request->header('company'));
+
+                $file_disk = $fileDiskQuery->find($request->file_disk_id);
 
                 if ($file_disk) {
                     $file_disk->setConfig();
diff --git a/app/Http/Middleware/RequestTimingLogger.php b/app/Http/Middleware/RequestTimingLogger.php
new file mode 100644
index 00000000..7646aa01
--- /dev/null
+++ b/app/Http/Middleware/RequestTimingLogger.php
@@ -0,0 +1,37 @@
+<?php
+
+namespace App\Http\Middleware;
+
+use Closure;
+use Illuminate\Http\Request;
+
+class RequestTimingLogger
+{
+    public function handle(Request $request, Closure $next)
+    {
+        $start = microtime(true);
+
+        $response = $next($request);
+
+        $durationMs = (microtime(true) - $start) * 1000;
+
+        // Log moderately slow requests to surface bottlenecks.
+        if ($durationMs >= 50) {
+            $userId = $request->user() ? $request->user()->id : '-';
+            $line = sprintf(
+                "[%s] %s %s %d %.2fms user=%s\n",
+                date('Y-m-d H:i:s'),
+                $request->getMethod(),
+                $request->getRequestUri(),
+                method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 0,
+                $durationMs,
+                $userId
+            );
+
+            $logPath = storage_path('logs/royal-timing.log');
+            @file_put_contents($logPath, $line, FILE_APPEND);
+        }
+
+        return $response;
+    }
+}
diff --git a/app/Http/Requests/AppointmentRequest.php b/app/Http/Requests/AppointmentRequest.php
index 774957c0..18298e92 100644
--- a/app/Http/Requests/AppointmentRequest.php
+++ b/app/Http/Requests/AppointmentRequest.php
@@ -3,6 +3,7 @@
 namespace App\Http\Requests;
 
 use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\Rule;
 
 class AppointmentRequest extends FormRequest
 {
@@ -20,7 +21,12 @@ class AppointmentRequest extends FormRequest
     public function rules(): array
     {
         return [
-            'customer_id' => 'required|exists:customers,id',
+            'customer_id' => [
+                'required',
+                Rule::exists('customers', 'id')
+                    ->where('company_id', $this->header('company'))
+                    ->whereNull('deleted_at'),
+            ],
             'company_id' => 'required|exists:companies,id',
             'creator_id' => 'nullable|exists:users,id',
             'title' => 'required|string|max:255',
diff --git a/app/Http/Requests/DeleteCustomersRequest.php b/app/Http/Requests/DeleteCustomersRequest.php
index 3bc72431..13ceb91d 100755
--- a/app/Http/Requests/DeleteCustomersRequest.php
+++ b/app/Http/Requests/DeleteCustomersRequest.php
@@ -26,7 +26,9 @@ class DeleteCustomersRequest extends FormRequest
             ],
             'ids.*' => [
                 'required',
-                Rule::exists('customers', 'id'),
+                Rule::exists('customers', 'id')
+                    ->where('company_id', $this->header('company'))
+                    ->whereNull('deleted_at'),
             ],
         ];
     }
diff --git a/app/Http/Requests/DeleteEstimatesRequest.php b/app/Http/Requests/DeleteEstimatesRequest.php
index b94fbb6d..8d8d2f65 100755
--- a/app/Http/Requests/DeleteEstimatesRequest.php
+++ b/app/Http/Requests/DeleteEstimatesRequest.php
@@ -26,7 +26,9 @@ class DeleteEstimatesRequest extends FormRequest
             ],
             'ids.*' => [
                 'required',
-                Rule::exists('estimates', 'id'),
+                Rule::exists('estimates', 'id')
+                    ->where('company_id', $this->header('company'))
+                    ->whereNull('deleted_at'),
             ],
         ];
     }
diff --git a/app/Http/Requests/DeleteExpensesRequest.php b/app/Http/Requests/DeleteExpensesRequest.php
index 553ab87b..c8d0bc2b 100755
--- a/app/Http/Requests/DeleteExpensesRequest.php
+++ b/app/Http/Requests/DeleteExpensesRequest.php
@@ -26,7 +26,9 @@ class DeleteExpensesRequest extends FormRequest
             ],
             'ids.*' => [
                 'required',
-                Rule::exists('expenses', 'id'),
+                Rule::exists('expenses', 'id')
+                    ->where('company_id', $this->header('company'))
+                    ->whereNull('deleted_at'),
             ],
         ];
     }
diff --git a/app/Http/Requests/DeleteInvoiceRequest.php b/app/Http/Requests/DeleteInvoiceRequest.php
index b5e87509..8b1552f1 100755
--- a/app/Http/Requests/DeleteInvoiceRequest.php
+++ b/app/Http/Requests/DeleteInvoiceRequest.php
@@ -28,7 +28,9 @@ class DeleteInvoiceRequest extends FormRequest
             ],
             'ids.*' => [
                 'required',
-                Rule::exists('invoices', 'id'),
+                Rule::exists('invoices', 'id')
+                    ->where('company_id', $this->header('company'))
+                    ->whereNull('deleted_at'),
                 new RelationNotExist(Invoice::class, 'payments'),
             ],
         ];
diff --git a/app/Http/Requests/DeleteItemsRequest.php b/app/Http/Requests/DeleteItemsRequest.php
index 0fbaa7f5..13319bbd 100755
--- a/app/Http/Requests/DeleteItemsRequest.php
+++ b/app/Http/Requests/DeleteItemsRequest.php
@@ -28,7 +28,8 @@ class DeleteItemsRequest extends FormRequest
             ],
             'ids.*' => [
                 'required',
-                Rule::exists('items', 'id'),
+                Rule::exists('items', 'id')
+                    ->where('company_id', $this->header('company')),
                 new RelationNotExist(Item::class, 'invoiceItems'),
                 new RelationNotExist(Item::class, 'estimateItems'),
                 new RelationNotExist(Item::class, 'taxes'),
diff --git a/app/Http/Requests/DeletePaymentsRequest.php b/app/Http/Requests/DeletePaymentsRequest.php
index 50a53f93..f57860c0 100755
--- a/app/Http/Requests/DeletePaymentsRequest.php
+++ b/app/Http/Requests/DeletePaymentsRequest.php
@@ -26,7 +26,9 @@ class DeletePaymentsRequest extends FormRequest
             ],
             'ids.*' => [
                 'required',
-                Rule::exists('payments', 'id'),
+                Rule::exists('payments', 'id')
+                    ->where('company_id', $this->header('company'))
+                    ->whereNull('deleted_at'),
             ],
         ];
     }
diff --git a/app/Http/Requests/DeleteUserRequest.php b/app/Http/Requests/DeleteUserRequest.php
index aafb05aa..2e8b2afb 100755
--- a/app/Http/Requests/DeleteUserRequest.php
+++ b/app/Http/Requests/DeleteUserRequest.php
@@ -26,7 +26,8 @@ class DeleteUserRequest extends FormRequest
             ],
             'users.*' => [
                 'required',
-                Rule::exists('users', 'id'),
+                Rule::exists('user_company', 'user_id')
+                    ->where('company_id', $this->header('company')),
             ],
         ];
     }
diff --git a/app/Http/Requests/EstimatesRequest.php b/app/Http/Requests/EstimatesRequest.php
index 26bb77a1..acf57d92 100755
--- a/app/Http/Requests/EstimatesRequest.php
+++ b/app/Http/Requests/EstimatesRequest.php
@@ -32,6 +32,9 @@ class EstimatesRequest extends FormRequest
             ],
             'customer_id' => [
                 'required',
+                Rule::exists('customers', 'id')
+                    ->where('company_id', $this->header('company'))
+                    ->whereNull('deleted_at'),
             ],
             'estimate_number' => [
                 'required',
@@ -92,7 +95,8 @@ class EstimatesRequest extends FormRequest
 
         $companyCurrency = CompanySetting::getSetting('currency', $this->header('company'));
 
-        $customer = Customer::find($this->customer_id);
+        $customer = Customer::where('company_id', $this->header('company'))
+            ->find($this->customer_id);
 
         if ($companyCurrency && $customer) {
             if ((string) $customer->currency_id !== $companyCurrency) {
@@ -120,7 +124,8 @@ class EstimatesRequest extends FormRequest
         $company_currency = CompanySetting::getSetting('currency', $this->header('company'));
         $current_currency = $this->currency_id;
         $exchange_rate = $company_currency != $current_currency ? $this->exchange_rate : 1;
-        $currency = Customer::find($this->customer_id)->currency_id;
+        $currency = Customer::where('company_id', $this->header('company'))
+            ->find($this->customer_id)?->currency_id;
 
         return collect($this->except('items', 'taxes'))
             ->merge([
diff --git a/app/Http/Requests/InvoicesRequest.php b/app/Http/Requests/InvoicesRequest.php
index 92a75468..05c27196 100755
--- a/app/Http/Requests/InvoicesRequest.php
+++ b/app/Http/Requests/InvoicesRequest.php
@@ -33,6 +33,9 @@ class InvoicesRequest extends FormRequest
             ],
             'customer_id' => [
                 'required',
+                Rule::exists('customers', 'id')
+                    ->where('company_id', $this->header('company'))
+                    ->whereNull('deleted_at'),
             ],
             'invoice_number' => [
                 'required',
@@ -92,11 +95,23 @@ class InvoicesRequest extends FormRequest
                 'nullable',
                 'exists:users,id',
                 function ($attribute, $value, $fail) {
-                    if ($value) {
-                        $user = User::find($value);
-                        if ($user && !$user->isA('dentist')) {
-                            $fail(__('must_be_dentist'));
-                        }
+                    if (! $value) {
+                        return;
+                    }
+
+                    $user = User::where('id', $value)
+                        ->whereHas('companies', function ($q) {
+                            $q->where('company_id', $this->header('company'));
+                        })
+                        ->first();
+
+                    if (! $user) {
+                        $fail('Selected user is not part of this company.');
+                        return;
+                    }
+
+                    if (! $user->isA('dentist')) {
+                        $fail(__('must_be_dentist'));
                     }
                 },
             ],
@@ -104,7 +119,8 @@ class InvoicesRequest extends FormRequest
 
         $companyCurrency = CompanySetting::getSetting('currency', $this->header('company'));
 
-        $customer = Customer::find($this->customer_id);
+        $customer = Customer::where('company_id', $this->header('company'))
+            ->find($this->customer_id);
 
         if ($customer && $companyCurrency) {
             if ((string) $customer->currency_id !== $companyCurrency) {
@@ -132,8 +148,9 @@ class InvoicesRequest extends FormRequest
         $company_currency = CompanySetting::getSetting('currency', $this->header('company'));
         $current_currency = $this->currency_id;
         $exchange_rate = $company_currency != $current_currency ? $this->exchange_rate : 1;
-        $customer = Customer::find($this->customer_id);
-        $currency = $customer->currency_id;
+        $customer = Customer::where('company_id', $this->header('company'))
+            ->find($this->customer_id);
+        $currency = $customer ? $customer->currency_id : null;
 
         return collect($this->except('items', 'taxes'))
             ->merge([
@@ -154,15 +171,15 @@ class InvoicesRequest extends FormRequest
                 'base_due_amount' => $this->total * $exchange_rate,
                 'currency_id' => $currency,
                 // Patient information snapshot
-                'customer_age' => $customer->age,
-                'customer_next_of_kin' => $customer->next_of_kin,
-                'customer_next_of_kin_phone' => $customer->next_of_kin_phone,
-                'customer_diagnosis' => $customer->diagnosis,
-                'customer_treatment' => $customer->treatment,
+                'customer_age' => $customer?->age,
+                'customer_next_of_kin' => $customer?->next_of_kin,
+                'customer_next_of_kin_phone' => $customer?->next_of_kin_phone,
+                'customer_diagnosis' => $customer?->diagnosis,
+                'customer_treatment' => $customer?->treatment,
                 'customer_attended_to_by' => $this->assigned_to_id
                     ? User::find($this->assigned_to_id)?->name
-                    : $customer->attended_to_by,
-                'customer_review_date' => $customer->review_date,
+                    : $customer?->attended_to_by,
+                'customer_review_date' => $customer?->review_date,
                 'assigned_to_id' => $this->assigned_to_id,
             ])
             ->toArray();
diff --git a/app/Http/Requests/PaymentRequest.php b/app/Http/Requests/PaymentRequest.php
index 3a6f1e0d..8a01576e 100755
--- a/app/Http/Requests/PaymentRequest.php
+++ b/app/Http/Requests/PaymentRequest.php
@@ -28,6 +28,9 @@ class PaymentRequest extends FormRequest
             ],
             'customer_id' => [
                 'required',
+                Rule::exists('customers', 'id')
+                    ->where('company_id', $this->header('company'))
+                    ->whereNull('deleted_at'),
             ],
             'exchange_rate' => [
                 'nullable',
@@ -43,6 +46,9 @@ class PaymentRequest extends FormRequest
             ],
             'invoice_id' => [
                 'nullable',
+                Rule::exists('invoices', 'id')
+                    ->where('company_id', $this->header('company'))
+                    ->whereNull('deleted_at'),
             ],
             'payment_method_id' => [
                 'nullable',
@@ -64,7 +70,8 @@ class PaymentRequest extends FormRequest
 
         $companyCurrency = CompanySetting::getSetting('currency', $this->header('company'));
 
-        $customer = Customer::find($this->customer_id);
+        $customer = Customer::where('company_id', $this->header('company'))
+            ->find($this->customer_id);
 
         if ($customer && $companyCurrency) {
             if ((string) $customer->currency_id !== $companyCurrency) {
@@ -82,7 +89,8 @@ class PaymentRequest extends FormRequest
         $company_currency = CompanySetting::getSetting('currency', $this->header('company'));
         $current_currency = $this->currency_id;
         $exchange_rate = $company_currency != $current_currency ? $this->exchange_rate : 1;
-        $currency = Customer::find($this->customer_id)->currency_id;
+        $currency = Customer::where('company_id', $this->header('company'))
+            ->find($this->customer_id)?->currency_id;
 
         return collect($this->validated())
             ->merge([
diff --git a/app/Http/Requests/RecurringInvoiceRequest.php b/app/Http/Requests/RecurringInvoiceRequest.php
index f9792832..7763065c 100755
--- a/app/Http/Requests/RecurringInvoiceRequest.php
+++ b/app/Http/Requests/RecurringInvoiceRequest.php
@@ -6,6 +6,7 @@ use App\Models\CompanySetting;
 use App\Models\Customer;
 use App\Models\RecurringInvoice;
 use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\Rule;
 
 class RecurringInvoiceRequest extends FormRequest
 {
@@ -34,6 +35,9 @@ class RecurringInvoiceRequest extends FormRequest
             ],
             'customer_id' => [
                 'required',
+                Rule::exists('customers', 'id')
+                    ->where('company_id', $this->header('company'))
+                    ->whereNull('deleted_at'),
             ],
             'exchange_rate' => [
                 'nullable',
@@ -84,7 +88,8 @@ class RecurringInvoiceRequest extends FormRequest
             ],
         ];
 
-        $customer = Customer::find($this->customer_id);
+        $customer = Customer::where('company_id', $this->header('company'))
+            ->find($this->customer_id);
 
         if ($customer && $companyCurrency) {
             if ((string) $customer->currency_id !== $companyCurrency) {
@@ -102,7 +107,8 @@ class RecurringInvoiceRequest extends FormRequest
         $company_currency = CompanySetting::getSetting('currency', $this->header('company'));
         $current_currency = $this->currency_id;
         $exchange_rate = $company_currency != $current_currency ? $this->exchange_rate : 1;
-        $currency = Customer::find($this->customer_id)->currency_id;
+        $currency = Customer::where('company_id', $this->header('company'))
+            ->find($this->customer_id)?->currency_id;
 
         $nextInvoiceAt = RecurringInvoice::getNextInvoiceDate($this->frequency, $this->starts_at);
 
diff --git a/app/Http/Resources/CustomerResource.php b/app/Http/Resources/CustomerResource.php
index b3f06da7..9b19cce1 100755
--- a/app/Http/Resources/CustomerResource.php
+++ b/app/Http/Resources/CustomerResource.php
@@ -36,19 +36,19 @@ class CustomerResource extends JsonResource
             'base_due_amount' => $this->base_due_amount,
             'prefix' => $this->prefix,
             'tax_id' => $this->tax_id,
-            'billing' => $this->when($this->billingAddress()->exists(), function () {
+            'billing' => $this->whenLoaded('billingAddress', function () {
                 return new AddressResource($this->billingAddress);
             }),
-            'shipping' => $this->when($this->shippingAddress()->exists(), function () {
+            'shipping' => $this->whenLoaded('shippingAddress', function () {
                 return new AddressResource($this->shippingAddress);
             }),
-            'fields' => $this->when($this->fields()->exists(), function () {
+            'fields' => $this->whenLoaded('fields', function () {
                 return CustomFieldValueResource::collection($this->fields);
             }),
-            'company' => $this->when($this->company()->exists(), function () {
+            'company' => $this->whenLoaded('company', function () {
                 return new CompanyResource($this->company);
             }),
-            'currency' => $this->when($this->currency()->exists(), function () {
+            'currency' => $this->whenLoaded('currency', function () {
                 return new CurrencyResource($this->currency);
             }),
         ];
diff --git a/app/Http/Resources/EstimateResource.php b/app/Http/Resources/EstimateResource.php
index 0b0eac22..19b3a9f4 100755
--- a/app/Http/Resources/EstimateResource.php
+++ b/app/Http/Resources/EstimateResource.php
@@ -46,25 +46,25 @@ class EstimateResource extends JsonResource
             'estimate_pdf_url' => $this->estimatePdfUrl,
             'sales_tax_type' => $this->sales_tax_type,
             'sales_tax_address_type' => $this->sales_tax_address_type,
-            'items' => $this->when($this->items()->exists(), function () {
+            'items' => $this->whenLoaded('items', function () {
                 return EstimateItemResource::collection($this->items);
             }),
-            'customer' => $this->when($this->customer()->exists(), function () {
+            'customer' => $this->whenLoaded('customer', function () {
                 return new CustomerResource($this->customer);
             }),
-            'creator' => $this->when($this->creator()->exists(), function () {
+            'creator' => $this->whenLoaded('creator', function () {
                 return new UserResource($this->creator);
             }),
-            'taxes' => $this->when($this->taxes()->exists(), function () {
+            'taxes' => $this->whenLoaded('taxes', function () {
                 return TaxResource::collection($this->taxes);
             }),
-            'fields' => $this->when($this->fields()->exists(), function () {
+            'fields' => $this->whenLoaded('fields', function () {
                 return CustomFieldValueResource::collection($this->fields);
             }),
-            'company' => $this->when($this->company()->exists(), function () {
+            'company' => $this->whenLoaded('company', function () {
                 return new CompanyResource($this->company);
             }),
-            'currency' => $this->when($this->currency()->exists(), function () {
+            'currency' => $this->whenLoaded('currency', function () {
                 return new CurrencyResource($this->currency);
             }),
         ];
diff --git a/app/Http/Resources/ExpenseCategoryResource.php b/app/Http/Resources/ExpenseCategoryResource.php
index 1f2a7f82..6a6e7941 100755
--- a/app/Http/Resources/ExpenseCategoryResource.php
+++ b/app/Http/Resources/ExpenseCategoryResource.php
@@ -20,7 +20,7 @@ class ExpenseCategoryResource extends JsonResource
             'company_id' => $this->company_id,
             'amount' => $this->amount,
             'formatted_created_at' => $this->formattedCreatedAt,
-            'company' => $this->when($this->company()->exists(), function () {
+            'company' => $this->whenLoaded('company', function () {
                 return new CompanyResource($this->company);
             }),
         ];
diff --git a/app/Http/Resources/ExpenseResource.php b/app/Http/Resources/ExpenseResource.php
index f251b9bb..ba46138c 100755
--- a/app/Http/Resources/ExpenseResource.php
+++ b/app/Http/Resources/ExpenseResource.php
@@ -32,25 +32,25 @@ class ExpenseResource extends JsonResource
             'currency_id' => $this->currency_id,
             'base_amount' => $this->base_amount,
             'payment_method_id' => $this->payment_method_id,
-            'customer' => $this->when($this->customer()->exists(), function () {
+            'customer' => $this->whenLoaded('customer', function () {
                 return new CustomerResource($this->customer);
             }),
-            'expense_category' => $this->when($this->category()->exists(), function () {
+            'expense_category' => $this->whenLoaded('category', function () {
                 return new ExpenseCategoryResource($this->category);
             }),
-            'creator' => $this->when($this->creator()->exists(), function () {
+            'creator' => $this->whenLoaded('creator', function () {
                 return new UserResource($this->creator);
             }),
-            'fields' => $this->when($this->fields()->exists(), function () {
+            'fields' => $this->whenLoaded('fields', function () {
                 return CustomFieldValueResource::collection($this->fields);
             }),
-            'company' => $this->when($this->company()->exists(), function () {
+            'company' => $this->whenLoaded('company', function () {
                 return new CompanyResource($this->company);
             }),
-            'currency' => $this->when($this->currency()->exists(), function () {
+            'currency' => $this->whenLoaded('currency', function () {
                 return new CurrencyResource($this->currency);
             }),
-            'payment_method' => $this->when($this->paymentMethod()->exists(), function () {
+            'payment_method' => $this->whenLoaded('paymentMethod', function () {
                 return new PaymentMethodResource($this->paymentMethod);
             }),
         ];
diff --git a/app/Http/Resources/InvoiceResource.php b/app/Http/Resources/InvoiceResource.php
index 4bdaec6c..43637e89 100755
--- a/app/Http/Resources/InvoiceResource.php
+++ b/app/Http/Resources/InvoiceResource.php
@@ -56,29 +56,29 @@ class InvoiceResource extends JsonResource
             'sales_tax_type' => $this->sales_tax_type,
             'sales_tax_address_type' => $this->sales_tax_address_type,
             'overdue' => $this->overdue,
-            'items' => $this->when($this->items()->exists(), function () {
+            'items' => $this->whenLoaded('items', function () {
                 return InvoiceItemResource::collection($this->items);
             }),
-            'customer' => $this->when($this->customer()->exists(), function () {
+            'customer' => $this->whenLoaded('customer', function () {
                 return new CustomerResource($this->customer);
             }),
-            'creator' => $this->when($this->creator()->exists(), function () {
+            'creator' => $this->whenLoaded('creator', function () {
                 return new UserResource($this->creator);
             }),
             'assigned_to_id' => $this->assigned_to_id,
-            'assigned_to' => $this->when($this->assignedTo()->exists(), function () {
+            'assigned_to' => $this->whenLoaded('assignedTo', function () {
                 return new UserResource($this->assignedTo);
             }),
-            'taxes' => $this->when($this->taxes()->exists(), function () {
+            'taxes' => $this->whenLoaded('taxes', function () {
                 return TaxResource::collection($this->taxes);
             }),
-            'fields' => $this->when($this->fields()->exists(), function () {
+            'fields' => $this->whenLoaded('fields', function () {
                 return CustomFieldValueResource::collection($this->fields);
             }),
-            'company' => $this->when($this->company()->exists(), function () {
+            'company' => $this->whenLoaded('company', function () {
                 return new CompanyResource($this->company);
             }),
-            'currency' => $this->when($this->currency()->exists(), function () {
+            'currency' => $this->whenLoaded('currency', function () {
                 return new CurrencyResource($this->currency);
             }),
         ];
diff --git a/app/Http/Resources/PaymentMethodResource.php b/app/Http/Resources/PaymentMethodResource.php
index 8283a8b4..694bf5b9 100755
--- a/app/Http/Resources/PaymentMethodResource.php
+++ b/app/Http/Resources/PaymentMethodResource.php
@@ -18,7 +18,7 @@ class PaymentMethodResource extends JsonResource
             'name' => $this->name,
             'company_id' => $this->company_id,
             'type' => $this->type,
-            'company' => $this->when($this->company()->exists(), function () {
+            'company' => $this->whenLoaded('company', function () {
                 return new CompanyResource($this->company);
             }),
         ];
diff --git a/app/Http/Resources/PaymentResource.php b/app/Http/Resources/PaymentResource.php
index 2801011b..4873de1f 100755
--- a/app/Http/Resources/PaymentResource.php
+++ b/app/Http/Resources/PaymentResource.php
@@ -33,25 +33,25 @@ class PaymentResource extends JsonResource
             'formatted_created_at' => $this->formattedCreatedAt,
             'formatted_payment_date' => $this->formattedPaymentDate,
             'payment_pdf_url' => $this->paymentPdfUrl,
-            'customer' => $this->when($this->customer()->exists(), function () {
+            'customer' => $this->whenLoaded('customer', function () {
                 return new CustomerResource($this->customer);
             }),
-            'invoice' => $this->when($this->invoice()->exists(), function () {
+            'invoice' => $this->whenLoaded('invoice', function () {
                 return new InvoiceResource($this->invoice);
             }),
-            'payment_method' => $this->when($this->paymentMethod()->exists(), function () {
+            'payment_method' => $this->whenLoaded('paymentMethod', function () {
                 return new PaymentMethodResource($this->paymentMethod);
             }),
-            'fields' => $this->when($this->fields()->exists(), function () {
+            'fields' => $this->whenLoaded('fields', function () {
                 return CustomFieldValueResource::collection($this->fields);
             }),
-            'company' => $this->when($this->company()->exists(), function () {
+            'company' => $this->whenLoaded('company', function () {
                 return new CompanyResource($this->company);
             }),
-            'currency' => $this->when($this->currency()->exists(), function () {
+            'currency' => $this->whenLoaded('currency', function () {
                 return new CurrencyResource($this->currency);
             }),
-            'transaction' => $this->when($this->transaction()->exists(), function () {
+            'transaction' => $this->whenLoaded('transaction', function () {
                 return new TransactionResource($this->transaction);
             }),
         ];
diff --git a/app/Http/Resources/RecurringInvoiceResource.php b/app/Http/Resources/RecurringInvoiceResource.php
index fd7352c8..204da894 100755
--- a/app/Http/Resources/RecurringInvoiceResource.php
+++ b/app/Http/Resources/RecurringInvoiceResource.php
@@ -45,28 +45,28 @@ class RecurringInvoiceResource extends JsonResource
             'template_name' => $this->template_name,
             'sales_tax_type' => $this->sales_tax_type,
             'sales_tax_address_type' => $this->sales_tax_address_type,
-            'fields' => $this->when($this->fields()->exists(), function () {
+            'fields' => $this->whenLoaded('fields', function () {
                 return CustomFieldValueResource::collection($this->fields);
             }),
-            'items' => $this->when($this->items()->exists(), function () {
+            'items' => $this->whenLoaded('items', function () {
                 return InvoiceItemResource::collection($this->items);
             }),
-            'customer' => $this->when($this->customer()->exists(), function () {
+            'customer' => $this->whenLoaded('customer', function () {
                 return new CustomerResource($this->customer);
             }),
-            'company' => $this->when($this->company()->exists(), function () {
+            'company' => $this->whenLoaded('company', function () {
                 return new CompanyResource($this->company);
             }),
-            'invoices' => $this->when($this->invoices()->exists(), function () {
+            'invoices' => $this->whenLoaded('invoices', function () {
                 return InvoiceResource::collection($this->invoices);
             }),
-            'taxes' => $this->when($this->taxes()->exists(), function () {
+            'taxes' => $this->whenLoaded('taxes', function () {
                 return TaxResource::collection($this->taxes);
             }),
-            'creator' => $this->when($this->creator()->exists(), function () {
+            'creator' => $this->whenLoaded('creator', function () {
                 return new UserResource($this->creator);
             }),
-            'currency' => $this->when($this->currency()->exists(), function () {
+            'currency' => $this->whenLoaded('currency', function () {
                 return new CurrencyResource($this->currency);
             }),
         ];
diff --git a/app/Models/Appointment.php b/app/Models/Appointment.php
index a882a75c..84cb435b 100644
--- a/app/Models/Appointment.php
+++ b/app/Models/Appointment.php
@@ -292,8 +292,18 @@ class Appointment extends Model
             $end = Carbon::parse($filters['to_date']);
             $query->whereBetween('appointment_date', [$start, $end]);
         })->when($filters['orderByField'] ?? null, function ($query, $orderByField) use ($filters) {
-            $orderBy = $filters['orderBy'] ?? 'desc';
-            $query->orderBy($orderByField, $orderBy);
+            $allowed = [
+                'id',
+                'created_at',
+                'updated_at',
+                'appointment_date',
+                'status',
+                'type',
+            ];
+            $field = in_array($orderByField, $allowed, true) ? $orderByField : 'appointment_date';
+            $orderBy = strtolower($filters['orderBy'] ?? 'desc');
+            $orderBy = in_array($orderBy, ['asc', 'desc'], true) ? $orderBy : 'desc';
+            $query->orderBy($field, $orderBy);
         }, function ($query) {
             $query->orderBy('appointment_date', 'desc');
         });
diff --git a/app/Models/CompanySetting.php b/app/Models/CompanySetting.php
index 459e4bc5..7718298b 100755
--- a/app/Models/CompanySetting.php
+++ b/app/Models/CompanySetting.php
@@ -11,6 +11,7 @@ class CompanySetting extends Model
     use HasFactory;
 
     protected $fillable = ['company_id', 'option', 'value'];
+    private static array $cache = [];
 
     public function company(): BelongsTo
     {
@@ -36,6 +37,8 @@ class CompanySetting extends Model
                     'value' => $value,
                 ]
             );
+            $cacheKey = $company_id.':'.$key;
+            unset(self::$cache[$cacheKey]);
         }
     }
 
@@ -56,12 +59,14 @@ class CompanySetting extends Model
 
     public static function getSetting($key, $company_id)
     {
-        $setting = static::whereOption($key)->whereCompany($company_id)->first();
-
-        if ($setting) {
-            return $setting->value;
-        } else {
-            return null;
+        $cacheKey = $company_id.':'.$key;
+        if (array_key_exists($cacheKey, self::$cache)) {
+            return self::$cache[$cacheKey];
         }
+
+        $setting = static::whereOption($key)->whereCompany($company_id)->first();
+        $value = $setting ? $setting->value : null;
+        self::$cache[$cacheKey] = $value;
+        return $value;
     }
 }
diff --git a/app/Models/Customer.php b/app/Models/Customer.php
index 87def60b..a195f4ba 100755
--- a/app/Models/Customer.php
+++ b/app/Models/Customer.php
@@ -156,10 +156,15 @@ class Customer extends Authenticatable implements HasMedia
         return 0;
     }
 
-    public static function deleteCustomers($ids)
+    public static function deleteCustomers($ids, $companyId = null)
     {
-        foreach ($ids as $id) {
-            $customer = self::find($id);
+        $query = self::query();
+        if ($companyId) {
+            $query->where('company_id', $companyId);
+        }
+
+        $customers = $query->whereIn('id', $ids)->get();
+        foreach ($customers as $customer) {
 
             if ($customer->estimates()->exists()) {
                 $customer->estimates()->delete();
@@ -226,7 +231,8 @@ class Customer extends Authenticatable implements HasMedia
                     $customer->addCustomFields($customFields);
                 }
 
-                $customer = Customer::with('billingAddress', 'shippingAddress', 'fields')->find($customer->id);
+                $customer = Customer::with('billingAddress', 'shippingAddress', 'fields', 'company', 'currency')
+                    ->find($customer->id);
 
                 return $customer;
             });
@@ -275,7 +281,8 @@ class Customer extends Authenticatable implements HasMedia
                 $customer->updateCustomFields($customFields);
             }
 
-            $customer = Customer::with('billingAddress', 'shippingAddress', 'fields')->find($customer->id);
+            $customer = Customer::with('billingAddress', 'shippingAddress', 'fields', 'company', 'currency')
+                ->find($customer->id);
 
             return $customer;
         });
@@ -328,7 +335,7 @@ class Customer extends Authenticatable implements HasMedia
 
     public function scopeWhereCustomer($query, $customer_id)
     {
-        $query->orWhere('customers.id', $customer_id);
+        $query->where('customers.id', $customer_id);
     }
 
     public function scopeApplyInvoiceFilters($query, array $filters)
@@ -378,7 +385,17 @@ class Customer extends Authenticatable implements HasMedia
 
         if ($filters->get('orderByField') || $filters->get('orderBy')) {
             $field = $filters->get('orderByField') ? $filters->get('orderByField') : 'name';
-            $orderBy = $filters->get('orderBy') ? $filters->get('orderBy') : 'asc';
+            $allowed = [
+                'id',
+                'name',
+                'created_at',
+                'updated_at',
+            ];
+            if (! in_array($field, $allowed, true)) {
+                $field = 'name';
+            }
+            $orderBy = strtolower($filters->get('orderBy') ? $filters->get('orderBy') : 'asc');
+            $orderBy = in_array($orderBy, ['asc', 'desc'], true) ? $orderBy : 'asc';
             $query->whereOrder($field, $orderBy);
         }
     }
diff --git a/app/Models/Estimate.php b/app/Models/Estimate.php
index 611f61f1..385595b2 100755
--- a/app/Models/Estimate.php
+++ b/app/Models/Estimate.php
@@ -153,7 +153,7 @@ class Estimate extends Model implements HasMedia
 
     public function scopeWhereEstimate($query, $estimate_id)
     {
-        $query->orWhere('id', $estimate_id);
+        $query->where('id', $estimate_id);
     }
 
     public function scopeWhereSearch($query, $search)
@@ -199,7 +199,22 @@ class Estimate extends Model implements HasMedia
 
         if ($filters->get('orderByField') || $filters->get('orderBy')) {
             $field = $filters->get('orderByField') ? $filters->get('orderByField') : 'sequence_number';
-            $orderBy = $filters->get('orderBy') ? $filters->get('orderBy') : 'desc';
+            $allowed = [
+                'id',
+                'created_at',
+                'updated_at',
+                'sequence_number',
+                'estimate_number',
+                'estimate_date',
+                'expiry_date',
+                'total',
+                'status',
+            ];
+            if (! in_array($field, $allowed, true)) {
+                $field = 'sequence_number';
+            }
+            $orderBy = strtolower($filters->get('orderBy') ? $filters->get('orderBy') : 'desc');
+            $orderBy = in_array($orderBy, ['asc', 'desc'], true) ? $orderBy : 'desc';
             $query->whereOrder($field, $orderBy);
         }
     }
diff --git a/app/Models/Expense.php b/app/Models/Expense.php
index 4d6fb96a..1d3f996c 100755
--- a/app/Models/Expense.php
+++ b/app/Models/Expense.php
@@ -176,7 +176,18 @@ class Expense extends Model implements HasMedia
 
         if ($filters->get('orderByField') || $filters->get('orderBy')) {
             $field = $filters->get('orderByField') ? $filters->get('orderByField') : 'expense_date';
-            $orderBy = $filters->get('orderBy') ? $filters->get('orderBy') : 'asc';
+            $allowed = [
+                'id',
+                'created_at',
+                'updated_at',
+                'expense_date',
+                'amount',
+            ];
+            if (! in_array($field, $allowed, true)) {
+                $field = 'expense_date';
+            }
+            $orderBy = strtolower($filters->get('orderBy') ? $filters->get('orderBy') : 'asc');
+            $orderBy = in_array($orderBy, ['asc', 'desc'], true) ? $orderBy : 'asc';
             $query->whereOrder($field, $orderBy);
         }
 
@@ -187,16 +198,18 @@ class Expense extends Model implements HasMedia
 
     public function scopeWhereExpense($query, $expense_id)
     {
-        $query->orWhere('id', $expense_id);
+        $query->where('id', $expense_id);
     }
 
     public function scopeWhereSearch($query, $search)
     {
         foreach (explode(' ', $search) as $term) {
-            $query->whereHas('category', function ($query) use ($term) {
-                $query->where('name', 'LIKE', '%'.$term.'%');
-            })
-                ->orWhere('notes', 'LIKE', '%'.$term.'%');
+            $query->where(function ($query) use ($term) {
+                $query->whereHas('category', function ($query) use ($term) {
+                    $query->where('name', 'LIKE', '%'.$term.'%');
+                })
+                    ->orWhere('notes', 'LIKE', '%'.$term.'%');
+            });
         }
     }
 
diff --git a/app/Models/ExpenseCategory.php b/app/Models/ExpenseCategory.php
index 3b02fe4c..9243310e 100755
--- a/app/Models/ExpenseCategory.php
+++ b/app/Models/ExpenseCategory.php
@@ -38,8 +38,16 @@ class ExpenseCategory extends Model
         return Carbon::parse($this->created_at)->format($dateFormat);
     }
 
-    public function getAmountAttribute()
+    public function getAmountAttribute($value)
     {
+        if ($value !== null) {
+            return $value;
+        }
+
+        if (array_key_exists('expenses_sum_amount', $this->attributes)) {
+            return $this->attributes['expenses_sum_amount'];
+        }
+
         return $this->expenses()->sum('amount');
     }
 
@@ -50,7 +58,7 @@ class ExpenseCategory extends Model
 
     public function scopeWhereCategory($query, $category_id)
     {
-        $query->orWhere('id', $category_id);
+        $query->where('id', $category_id);
     }
 
     public function scopeWhereSearch($query, $search)
diff --git a/app/Models/FileDisk.php b/app/Models/FileDisk.php
index 48162c76..de133134 100755
--- a/app/Models/FileDisk.php
+++ b/app/Models/FileDisk.php
@@ -77,8 +77,10 @@ class FileDisk extends Model
     public function scopeWhereSearch($query, $search)
     {
         foreach (explode(' ', $search) as $term) {
-            $query->where('name', 'LIKE', '%'.$term.'%')
-                ->orWhere('driver', 'LIKE', '%'.$term.'%');
+            $query->where(function ($query) use ($term) {
+                $query->where('name', 'LIKE', '%'.$term.'%')
+                    ->orWhere('driver', 'LIKE', '%'.$term.'%');
+            });
         }
     }
 
@@ -105,8 +107,19 @@ class FileDisk extends Model
         }
 
         if ($filters->get('orderByField') || $filters->get('orderBy')) {
-            $field = $filters->get('orderByField') ? $filters->get('orderByField') : 'sequence_number';
-            $orderBy = $filters->get('orderBy') ? $filters->get('orderBy') : 'asc';
+            $field = $filters->get('orderByField') ? $filters->get('orderByField') : 'created_at';
+            $allowed = [
+                'id',
+                'name',
+                'driver',
+                'created_at',
+                'updated_at',
+            ];
+            if (! in_array($field, $allowed, true)) {
+                $field = 'created_at';
+            }
+            $orderBy = strtolower($filters->get('orderBy') ? $filters->get('orderBy') : 'asc');
+            $orderBy = in_array($orderBy, ['asc', 'desc'], true) ? $orderBy : 'asc';
             $query->whereOrder($field, $orderBy);
         }
     }
diff --git a/app/Models/Invoice.php b/app/Models/Invoice.php
index c44b9e07..28f3e2fa 100755
--- a/app/Models/Invoice.php
+++ b/app/Models/Invoice.php
@@ -292,8 +292,22 @@ class Invoice extends Model implements HasMedia
         })->when($filters['customer_id'] ?? null, function ($query, $customerId) {
             $query->where('customer_id', $customerId);
         })->when($filters['orderByField'] ?? null, function ($query, $orderByField) use ($filters) {
-            $orderBy = $filters['orderBy'] ?? 'desc';
-            $query->orderBy($orderByField, $orderBy);
+            $allowed = [
+                'id',
+                'created_at',
+                'updated_at',
+                'sequence_number',
+                'invoice_number',
+                'invoice_date',
+                'due_date',
+                'total',
+                'status',
+                'paid_status',
+            ];
+            $field = in_array($orderByField, $allowed, true) ? $orderByField : 'sequence_number';
+            $orderBy = strtolower($filters['orderBy'] ?? 'desc');
+            $orderBy = in_array($orderBy, ['asc', 'desc'], true) ? $orderBy : 'desc';
+            $query->orderBy($field, $orderBy);
         }, function ($query) {
             $query->orderBy('sequence_number', 'desc');
         });
@@ -301,7 +315,7 @@ class Invoice extends Model implements HasMedia
 
     public function scopeWhereInvoice($query, $invoice_id)
     {
-        $query->orWhere('id', $invoice_id);
+        $query->where('id', $invoice_id);
     }
 
     public function scopeWhereCompany($query)
@@ -384,10 +398,14 @@ class Invoice extends Model implements HasMedia
                         'items',
                         'items.fields',
                         'items.fields.customField',
-                        'customer',
+                        'customer.currency',
                         'taxes',
-                    ])
-                        ->find($invoice->id);
+                        'creator',
+                        'assignedTo',
+                        'fields',
+                        'company',
+                        'currency',
+                    ])->find($invoice->id);
 
                     return $invoice;
                 });
@@ -492,10 +510,14 @@ class Invoice extends Model implements HasMedia
                 'items',
                 'items.fields',
                 'items.fields.customField',
-                'customer',
+                'customer.currency',
                 'taxes',
-            ])
-                ->find($this->id);
+                'creator',
+                'assignedTo',
+                'fields',
+                'company',
+                'currency',
+            ])->find($this->id);
 
             return $invoice;
         });
@@ -786,10 +808,15 @@ class Invoice extends Model implements HasMedia
         }
     }
 
-    public static function deleteInvoices($ids)
+    public static function deleteInvoices($ids, $companyId = null)
     {
-        foreach ($ids as $id) {
-            $invoice = self::find($id);
+        $query = self::query();
+        if ($companyId) {
+            $query->where('company_id', $companyId);
+        }
+
+        $invoices = $query->whereIn('id', $ids)->get();
+        foreach ($invoices as $invoice) {
 
             if ($invoice->transactions()->exists()) {
                 $invoice->transactions()->delete();
diff --git a/app/Models/Item.php b/app/Models/Item.php
index a73b62b4..990be8c1 100755
--- a/app/Models/Item.php
+++ b/app/Models/Item.php
@@ -68,7 +68,7 @@ class Item extends Model
 
     public function scopeWhereItem($query, $item_id)
     {
-        $query->orWhere('id', $item_id);
+        $query->where('id', $item_id);
     }
 
     public function scopeApplyFilters($query, array $filters)
@@ -93,7 +93,18 @@ class Item extends Model
 
         if ($filters->get('orderByField') || $filters->get('orderBy')) {
             $field = $filters->get('orderByField') ? $filters->get('orderByField') : 'name';
-            $orderBy = $filters->get('orderBy') ? $filters->get('orderBy') : 'asc';
+            $allowed = [
+                'id',
+                'name',
+                'price',
+                'created_at',
+                'updated_at',
+            ];
+            if (! in_array($field, $allowed, true)) {
+                $field = 'name';
+            }
+            $orderBy = strtolower($filters->get('orderBy') ? $filters->get('orderBy') : 'asc');
+            $orderBy = in_array($orderBy, ['asc', 'desc'], true) ? $orderBy : 'asc';
             $query->whereOrder($field, $orderBy);
         }
     }
diff --git a/app/Models/Payment.php b/app/Models/Payment.php
index 57b32dd6..ed42b260 100755
--- a/app/Models/Payment.php
+++ b/app/Models/Payment.php
@@ -186,8 +186,11 @@ class Payment extends Model implements HasMedia
             try {
                 return DB::transaction(function () use ($request, $data, $sequenceNumber, $customerSequenceNumber) {
                     if ($request->invoice_id) {
-                        $invoice = Invoice::find($request->invoice_id);
-                        $invoice->subtractInvoicePayment($request->amount);
+                        $invoice = Invoice::where('company_id', $request->header('company'))
+                            ->find($request->invoice_id);
+                        if ($invoice) {
+                            $invoice->subtractInvoicePayment($request->amount);
+                        }
                     }
 
                     $payment = Payment::create($data);
@@ -212,10 +215,13 @@ class Payment extends Model implements HasMedia
                     }
 
                     $payment = Payment::with([
-                        'customer',
+                        'customer.currency',
                         'invoice',
                         'paymentMethod',
                         'fields',
+                        'company',
+                        'currency',
+                        'transaction',
                     ])->find($payment->id);
 
                     return $payment;
@@ -256,19 +262,28 @@ class Payment extends Model implements HasMedia
             $data = $request->getPaymentPayload();
 
             if ($request->invoice_id && (! $this->invoice_id || $this->invoice_id !== $request->invoice_id)) {
-                $invoice = Invoice::find($request->invoice_id);
-                $invoice->subtractInvoicePayment($request->amount);
+                $invoice = Invoice::where('company_id', $this->company_id)
+                    ->find($request->invoice_id);
+                if ($invoice) {
+                    $invoice->subtractInvoicePayment($request->amount);
+                }
             }
 
             if ($this->invoice_id && (! $request->invoice_id || $this->invoice_id !== $request->invoice_id)) {
-                $invoice = Invoice::find($this->invoice_id);
-                $invoice->addInvoicePayment($this->amount);
+                $invoice = Invoice::where('company_id', $this->company_id)
+                    ->find($this->invoice_id);
+                if ($invoice) {
+                    $invoice->addInvoicePayment($this->amount);
+                }
             }
 
             if ($this->invoice_id && $this->invoice_id === $request->invoice_id && $request->amount !== $this->amount) {
-                $invoice = Invoice::find($this->invoice_id);
-                $invoice->addInvoicePayment($this->amount);
-                $invoice->subtractInvoicePayment($request->amount);
+                $invoice = Invoice::where('company_id', $this->company_id)
+                    ->find($this->invoice_id);
+                if ($invoice) {
+                    $invoice->addInvoicePayment($this->amount);
+                    $invoice->subtractInvoicePayment($request->amount);
+                }
             }
 
             $serial = (new SerialNumberFormatter)
@@ -294,11 +309,14 @@ class Payment extends Model implements HasMedia
             }
 
             $payment = Payment::with([
-                'customer',
+                'customer.currency',
                 'invoice',
                 'paymentMethod',
-            ])
-                ->find($this->id);
+                'fields',
+                'company',
+                'currency',
+                'transaction',
+            ])->find($this->id);
 
             return $payment;
         });
@@ -307,20 +325,30 @@ class Payment extends Model implements HasMedia
     public static function deletePayments($ids)
     {
         foreach ($ids as $id) {
-            $payment = Payment::find($id);
+            $paymentQuery = Payment::query();
+            if (request()->hasHeader('company')) {
+                $paymentQuery->where('company_id', request()->header('company'));
+            }
+            $payment = $paymentQuery->find($id);
+            if (! $payment) {
+                continue;
+            }
 
             if ($payment->invoice_id != null) {
-                $invoice = Invoice::find($payment->invoice_id);
-                $invoice->due_amount = ((int) $invoice->due_amount + (int) $payment->amount);
+                $invoice = Invoice::where('company_id', $payment->company_id)
+                    ->find($payment->invoice_id);
+                if ($invoice) {
+                    $invoice->due_amount = ((int) $invoice->due_amount + (int) $payment->amount);
+
+                    if ($invoice->due_amount == $invoice->total) {
+                        $invoice->paid_status = Invoice::STATUS_UNPAID;
+                    } else {
+                        $invoice->paid_status = Invoice::STATUS_PARTIALLY_PAID;
+                    }
 
-                if ($invoice->due_amount == $invoice->total) {
-                    $invoice->paid_status = Invoice::STATUS_UNPAID;
-                } else {
-                    $invoice->paid_status = Invoice::STATUS_PARTIALLY_PAID;
+                    $invoice->status = $invoice->getPreviousStatus();
+                    $invoice->save();
                 }
-
-                $invoice->status = $invoice->getPreviousStatus();
-                $invoice->save();
             }
 
             $payment->delete();
@@ -391,7 +419,20 @@ class Payment extends Model implements HasMedia
 
         if ($filters->get('orderByField') || $filters->get('orderBy')) {
             $field = $filters->get('orderByField') ? $filters->get('orderByField') : 'sequence_number';
-            $orderBy = $filters->get('orderBy') ? $filters->get('orderBy') : 'desc';
+            $allowed = [
+                'id',
+                'created_at',
+                'updated_at',
+                'sequence_number',
+                'payment_number',
+                'payment_date',
+                'amount',
+            ];
+            if (! in_array($field, $allowed, true)) {
+                $field = 'sequence_number';
+            }
+            $orderBy = strtolower($filters->get('orderBy') ? $filters->get('orderBy') : 'desc');
+            $orderBy = in_array($orderBy, ['asc', 'desc'], true) ? $orderBy : 'desc';
             $query->whereOrder($field, $orderBy);
         }
     }
@@ -411,7 +452,7 @@ class Payment extends Model implements HasMedia
 
     public function scopeWherePayment($query, $payment_id)
     {
-        $query->orWhere('id', $payment_id);
+        $query->where('id', $payment_id);
     }
 
     public function scopeWhereCompany($query)
diff --git a/app/Models/PaymentMethod.php b/app/Models/PaymentMethod.php
index ab3a8a3c..2bf029ee 100755
--- a/app/Models/PaymentMethod.php
+++ b/app/Models/PaymentMethod.php
@@ -59,7 +59,7 @@ class PaymentMethod extends Model
 
     public function scopeWherePaymentMethod($query, $payment_id)
     {
-        $query->orWhere('id', $payment_id);
+        $query->where('id', $payment_id);
     }
 
     public function scopeWhereSearch($query, $search)
@@ -104,8 +104,11 @@ class PaymentMethod extends Model
 
     public static function getSettings($id)
     {
-        $settings = PaymentMethod::find($id)
-            ->settings;
+        $query = PaymentMethod::query();
+        if (request()->hasHeader('company')) {
+            $query->where('company_id', request()->header('company'));
+        }
+        $settings = $query->find($id)?->settings;
 
         return $settings;
     }
diff --git a/app/Models/RecurringInvoice.php b/app/Models/RecurringInvoice.php
index 8214fad6..0a3c9537 100755
--- a/app/Models/RecurringInvoice.php
+++ b/app/Models/RecurringInvoice.php
@@ -191,7 +191,20 @@ class RecurringInvoice extends Model
 
         if ($filters->get('orderByField') || $filters->get('orderBy')) {
             $field = $filters->get('orderByField') ? $filters->get('orderByField') : 'created_at';
-            $orderBy = $filters->get('orderBy') ? $filters->get('orderBy') : 'asc';
+            $allowed = [
+                'id',
+                'created_at',
+                'updated_at',
+                'starts_at',
+                'next_invoice_at',
+                'limit_date',
+                'total',
+            ];
+            if (! in_array($field, $allowed, true)) {
+                $field = 'created_at';
+            }
+            $orderBy = strtolower($filters->get('orderBy') ? $filters->get('orderBy') : 'asc');
+            $orderBy = in_array($orderBy, ['asc', 'desc'], true) ? $orderBy : 'asc';
             $query->whereOrder($field, $orderBy);
         }
     }
@@ -339,7 +352,9 @@ class RecurringInvoice extends Model
         $newInvoice['tax'] = $this->tax;
         $newInvoice['total'] = $this->total;
         $newInvoice['customer_id'] = $this->customer_id;
-        $newInvoice['currency_id'] = Customer::find($this->customer_id)->currency_id;
+        $customer = Customer::where('company_id', $this->company_id)
+            ->find($this->customer_id);
+        $newInvoice['currency_id'] = $customer?->currency_id;
         $newInvoice['template_name'] = $this->template_name;
         $newInvoice['due_amount'] = $this->total;
         $newInvoice['recurring_invoice_id'] = $this->id;
@@ -420,10 +435,15 @@ class RecurringInvoice extends Model
         $this->save();
     }
 
-    public static function deleteRecurringInvoice($ids)
+    public static function deleteRecurringInvoice($ids, $companyId = null)
     {
-        foreach ($ids as $id) {
-            $recurringInvoice = self::find($id);
+        $query = self::query();
+        if ($companyId) {
+            $query->where('company_id', $companyId);
+        }
+
+        $recurringInvoices = $query->whereIn('id', $ids)->get();
+        foreach ($recurringInvoices as $recurringInvoice) {
 
             if ($recurringInvoice->invoices()->exists()) {
                 $recurringInvoice->invoices()->update(['recurring_invoice_id' => null]);
diff --git a/app/Models/TaxType.php b/app/Models/TaxType.php
index 85480f0e..6f774db9 100755
--- a/app/Models/TaxType.php
+++ b/app/Models/TaxType.php
@@ -45,7 +45,7 @@ class TaxType extends Model
 
     public function scopeWhereTaxType($query, $tax_type_id)
     {
-        $query->orWhere('id', $tax_type_id);
+        $query->where('id', $tax_type_id);
     }
 
     public function scopeApplyFilters($query, array $filters)
@@ -65,8 +65,18 @@ class TaxType extends Model
         }
 
         if ($filters->get('orderByField') || $filters->get('orderBy')) {
-            $field = $filters->get('orderByField') ? $filters->get('orderByField') : 'payment_number';
-            $orderBy = $filters->get('orderBy') ? $filters->get('orderBy') : 'asc';
+            $field = $filters->get('orderByField') ? $filters->get('orderByField') : 'name';
+            $allowed = [
+                'id',
+                'name',
+                'created_at',
+                'updated_at',
+            ];
+            if (! in_array($field, $allowed, true)) {
+                $field = 'name';
+            }
+            $orderBy = strtolower($filters->get('orderBy') ? $filters->get('orderBy') : 'asc');
+            $orderBy = in_array($orderBy, ['asc', 'desc'], true) ? $orderBy : 'asc';
             $query->whereOrder($field, $orderBy);
         }
     }
diff --git a/app/Models/Unit.php b/app/Models/Unit.php
index 5fe010ed..567819a2 100755
--- a/app/Models/Unit.php
+++ b/app/Models/Unit.php
@@ -30,7 +30,7 @@ class Unit extends Model
 
     public function scopeWhereUnit($query, $unit_id)
     {
-        $query->orWhere('id', $unit_id);
+        $query->where('id', $unit_id);
     }
 
     public function scopeWhereSearch($query, $search)
diff --git a/app/Models/User.php b/app/Models/User.php
index 085a4536..f1776e7b 100755
--- a/app/Models/User.php
+++ b/app/Models/User.php
@@ -179,7 +179,17 @@ class User extends Authenticatable implements HasMedia
 
     public function scopeWhereOrder($query, $orderByField, $orderBy)
     {
-        $query->orderBy($orderByField, $orderBy);
+        $allowed = [
+            'id',
+            'name',
+            'email',
+            'created_at',
+            'updated_at',
+        ];
+        $field = in_array($orderByField, $allowed, true) ? $orderByField : 'name';
+        $direction = strtolower($orderBy);
+        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';
+        $query->orderBy($field, $direction);
     }
 
     public function scopeWhereSearch($query, $search)
@@ -261,7 +271,7 @@ class User extends Authenticatable implements HasMedia
 
     public function scopeWhereSuperAdmin($query)
     {
-        $query->orWhere('role', 'super admin');
+        $query->where('role', 'super admin');
     }
 
     public function scopeApplyInvoiceFilters($query, array $filters)
@@ -404,10 +414,17 @@ class User extends Authenticatable implements HasMedia
         return false;
     }
 
-    public static function deleteUsers($ids)
+    public static function deleteUsers($ids, $companyId = null)
     {
-        foreach ($ids as $id) {
-            $user = self::find($id);
+        $query = self::query();
+        if ($companyId) {
+            $query->whereHas('companies', function ($q) use ($companyId) {
+                $q->where('company_id', $companyId);
+            });
+        }
+
+        $users = $query->whereIn('id', $ids)->get();
+        foreach ($users as $user) {
 
             if ($user->invoices()->exists()) {
                 $user->invoices()->update(['creator_id' => null]);
diff --git a/app/Rules/RelationNotExist.php b/app/Rules/RelationNotExist.php
index 452c49fb..8ca914a9 100755
--- a/app/Rules/RelationNotExist.php
+++ b/app/Rules/RelationNotExist.php
@@ -4,6 +4,7 @@ namespace App\Rules;
 
 use Closure;
 use Illuminate\Contracts\Validation\ValidationRule;
+use Illuminate\Support\Facades\Schema;
 
 class RelationNotExist implements ValidationRule
 {
@@ -29,7 +30,20 @@ class RelationNotExist implements ValidationRule
     {
         $relation = $this->relation;
 
-        if ($this->class::find($value)->$relation()->exists()) {
+        $query = $this->class::query();
+        if (request()->hasHeader('company')) {
+            $model = $query->getModel();
+            if (Schema::hasColumn($model->getTable(), 'company_id')) {
+                $query->where('company_id', request()->header('company'));
+            }
+        }
+
+        $record = $query->find($value);
+        if (! $record) {
+            return;
+        }
+
+        if ($record->$relation()->exists()) {
             $fail("Relation {$this->relation} exists.");
         }
 
diff --git a/app/Services/SerialNumberFormatter.php b/app/Services/SerialNumberFormatter.php
index 7ef8b814..c5599667 100755
--- a/app/Services/SerialNumberFormatter.php
+++ b/app/Services/SerialNumberFormatter.php
@@ -43,7 +43,11 @@ class SerialNumberFormatter
 
     public function setModelObject($id = null)
     {
-        $this->ob = $this->model::find($id);
+        $query = $this->model::query();
+        if ($this->company) {
+            $query->where('company_id', $this->company);
+        }
+        $this->ob = $query->find($id);
 
         if ($this->ob && $this->ob->sequence_number) {
             $this->nextSequenceNumber = $this->ob->sequence_number;
@@ -71,7 +75,16 @@ class SerialNumberFormatter
      */
     public function setCustomer($customer = null)
     {
-        $this->customer = Customer::find($customer);
+        if ($customer === null) {
+            $this->customer = null;
+            return $this;
+        }
+
+        $query = Customer::query();
+        if ($this->company) {
+            $query->where('company_id', $this->company);
+        }
+        $this->customer = $query->find($customer);
 
         return $this;
     }
diff --git a/app/Traits/HasCustomFieldsTrait.php b/app/Traits/HasCustomFieldsTrait.php
index 3a57e089..e34a7460 100755
--- a/app/Traits/HasCustomFieldsTrait.php
+++ b/app/Traits/HasCustomFieldsTrait.php
@@ -27,7 +27,15 @@ trait HasCustomFieldsTrait
             if (! is_array($field)) {
                 $field = (array) $field;
             }
-            $customField = CustomField::find($field['id']);
+            $companyId = $this->company_id ?? request()->header('company');
+            $customFieldQuery = CustomField::query();
+            if ($companyId) {
+                $customFieldQuery->where('company_id', $companyId);
+            }
+            $customField = $customFieldQuery->find($field['id']);
+            if (! $customField) {
+                continue;
+            }
 
             $customFieldValue = [
                 'type' => $customField->type,
@@ -47,7 +55,15 @@ trait HasCustomFieldsTrait
                 $field = (array) $field;
             }
 
-            $customField = CustomField::find($field['id']);
+            $companyId = $this->company_id ?? request()->header('company');
+            $customFieldQuery = CustomField::query();
+            if ($companyId) {
+                $customFieldQuery->where('company_id', $companyId);
+            }
+            $customField = $customFieldQuery->find($field['id']);
+            if (! $customField) {
+                continue;
+            }
             $customFieldValue = $this->fields()->firstOrCreate([
                 'custom_field_id' => $customField->id,
                 'type' => $customField->type,
diff --git a/bootstrap/app.php b/bootstrap/app.php
index be24cefa..b64d4e18 100755
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -5,6 +5,8 @@ use Illuminate\Foundation\Application;
 use Illuminate\Foundation\Configuration\Exceptions;
 use Illuminate\Foundation\Configuration\Middleware;
 
+require_once __DIR__.'/../app/Http/Middleware/RequestTimingLogger.php';
+
 return Application::configure(basePath: dirname(__DIR__))
     ->withProviders([
         \Lavary\Menu\ServiceProvider::class,
@@ -38,6 +40,7 @@ return Application::configure(basePath: dirname(__DIR__))
 
         $middleware->statefulApi();
         $middleware->throttleApi('180,1');
+        $middleware->appendToGroup('api', \App\Http\Middleware\RequestTimingLogger::class);
 
         $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, \App\Http\Middleware\TrustProxies::class);
 
