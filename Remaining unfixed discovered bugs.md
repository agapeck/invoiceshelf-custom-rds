# Remaining unfixed discovered bugs

Date: 2026-03-26

## Current stability statement

I did not find evidence that the app is broken by the `dbc5740c` bugfix commit.

What I did verify:

- the focused bugfix verification bundle exited `0` against an isolated MySQL schema
- the existing `clone invoice` and `clone estimate` regression tests exited `0`
- `migrate:fresh --env=testing` now completes cleanly on an isolated schema
- `git diff --check` was clean before commit
- all modified PHP files passed `php -l`

Important limit:

- this is strong targeted verification of the changed paths, not proof that every route and every old test in the repo is bug-free
- selected PHPUnit runs still report `risky` warnings in this repo's test harness, so the verification layer itself still needs cleanup

## Remaining verified issues

### 1. Live dev database is missing UGX even though fresh installs seed it

Status:

- still unfixed

Verification:

- `database/seeders/CurrenciesTableSeeder.php` already includes `Ugandan Shilling` / `UGX`
- live dev DB query returned `COUNT(*) = 0` for `currencies.code = 'UGX'`

Impact:

- the current dev database does not match fresh-install currency data
- any manual/dev workflows expecting UGX in the current DB will fail or behave inconsistently

Recommended fix:

- apply a one-off data fix to the current DB, or add an idempotent sync migration/seed path if you want existing installs corrected automatically

### 2. `ExchangeRateLogFactory` still builds invalid rows

File:

- `database/factories/ExchangeRateLogFactory.php`

Verification:

- the factory still sets `company_id` from `Currency::find(1)->id`
- `database/migrations/2021_08_05_103535_create_exchange_rate_logs_table.php` defines `company_id` as a foreign key to `companies.id`
- `app/Models/ExchangeRateLog.php` writes logs with:
  - `company_id = $model->company_id`
  - `base_currency_id = $model->currency_id`
  - `currency_id = CompanySetting::getSetting('currency', $model->company_id)`

Impact:

- factory-generated exchange-rate log rows can be semantically wrong and can fail once ids diverge
- tests using this factory can become flaky or misleading

Recommended fix:

- make the factory use a real company id for `company_id`
- replace hardcoded `Currency::find(1)` / `Currency::find(4)` assumptions with real selected currency rows
- align the factory field meaning with `ExchangeRateLog::addExchangeRateLog()`

### 3. `ItemFactory` still assigns the wrong value to `creator_id`

File:

- `database/factories/ItemFactory.php`

Verification:

- the factory sets `creator_id` to `User::where('role', 'super admin')->first()->company_id`
- `database/migrations/2020_11_23_050406_add_creator_in_items_table.php` defines `creator_id` as a foreign key to `users.id`
- `app/Models/Item.php` defines `creator()` as `belongsTo(User::class, 'creator_id')`

Impact:

- factory-created items can point at a company id instead of a user id
- this can silently create wrong creator links or fail once ids stop coinciding

Recommended fix:

- set `creator_id` from the selected user's `id`, not `company_id`

### 4. Hardcoded-id test fragility still exists outside the files I fixed

Verification:

- repo search still finds many tests using `User::find(1)`, `User::findOrFail(1)`, and similar hardcoded seeded-id assumptions
- examples remain in:
  - `tests/Feature/Admin/RoleTest.php`
  - `tests/Feature/Admin/ItemTest.php`
  - `tests/Feature/Admin/RecurringInvoiceTest.php`
  - `tests/Feature/HashGenerationTest.php`
  - `tests/Unit/Hardening/QueryHardeningTest.php`

Impact:

- tests can fail after auto-increment drift, repeated reset cycles, or seed changes even when app behavior is correct
- this reduces trust in the suite and slows future bugfix verification

Recommended fix:

- replace hardcoded seeded ids with `query()->firstOrFail()` or explicit fixtures created in the test itself

### 5. PHPUnit still reports `risky` warnings during otherwise-passing targeted runs

Status:

- still unfixed

Verification:

- targeted runs for the bugfix bundle and clone regressions exited `0` but still reported `risky`
- the warning text shown by the runner indicates error-handler interference in test or tested code

Impact:

- verification is noisier than it should be
- real failures are harder to distinguish from harness issues

Recommended fix:

- isolate the warning source in the test bootstrap / shared helpers / affected code paths
- clear the risky warnings before relying on the broader suite for release confidence

## Bottom line

Based on the targeted verification I ran, the app does not appear broken by the changes I made.

The remaining issues I can substantiate are mainly:

- one live-data drift issue in the current dev DB
- two real factory bugs
- broader remaining test-suite fragility and risky-warning noise

Those should be treated as follow-up cleanup, not evidence that the newly fixed runtime paths regressed.
