# Implementation Plan Review + Hardening Status (March 19, 2026)

## Verdict

The original plan was not pure AI slop. It had useful direction, but some findings were incomplete and several operational gaps were missed.

## Confirmed bugs fixed (including new findings)

1. Customer profile billing/shipping write inversion
   - Fixed incorrect address-type mapping in customer profile updates.

2. Appointment company-context bypass
   - `getAvailableSlots` now prioritizes header company context and validates exclusion IDs in-company.

3. Appointment hash regeneration skip issue
   - Switched from mutable `chunk()` flow to grouped predicate + `chunkById()`.

4. Dynamic `ORDER BY` injection surfaces
   - Added allowlisted ordering in invoice/appointment filters and role listing.

5. Tenant escape through ungrouped OR filters
   - Grouped `orWhere` search logic in expense and file disk search paths.

6. Payment concurrency race on invoice due totals
   - Added row-level locks and transactional updates for create/update/delete payment flows.

7. Historical migration breakage under MySQL + encrypted casts
   - Fixed `2020_12_02_090527_update_crater_version_400` to seed `file_disks` via direct DB inserts (raw JSON), avoiding cast encryption conflicts before later schema migrations.

8. File disk tenancy schema-code mismatch
   - Added `2026_03_19_145000_add_company_id_to_file_disks_table` to align schema with existing app code (`company_id`, FK, index).
   - Hardened `ConfigMiddleware` to safely handle pre/post-migration states and global fallback disks.

9. Appointment slot query indexability
   - Replaced `whereDate()` with `whereBetween(startOfDay, endOfDay)` to keep datetime indexes usable.

## Performance and scaling work completed

### Code and query-path optimization
- Customer resources now rely on `whenLoaded()` instead of relation `exists()` checks.
- Customer controllers/PDF endpoints now eager-load related data to avoid N+1 patterns.
- Request timing logger is configurable:
  - `REQUEST_TIMING_LOG_ENABLED`
  - `REQUEST_TIMING_LOG_THRESHOLD_MS`

### Database/indexing
- Added composite index migration for high-traffic list patterns:
  - invoices, estimates, payments, expenses, customers, appointments, file_disks.
- Ran `EXPLAIN` checks on major list queries and verified index usage.

### Runtime stack tuning (container deployment path)
- Updated `docker/custom-production/docker-compose.yml`:
  - added Redis service (AOF, healthcheck),
  - switched cache/session/queue defaults to Redis,
  - added dedicated queue worker service,
  - tightened MySQL runtime parameters (`innodb_log_file_size`, `tmp_table_size`, `thread_cache_size`, etc.).
- Updated `docker/custom-production/Dockerfile` with PHP runtime performance ini.
- Added `docker/custom-production/php/conf.d/zz-performance.ini` (OPcache + realpath cache tuning).

## Test status (real MySQL)

- Added hardening regression tests under:
  - `tests/Feature/Hardening/*`
  - `tests/Unit/Hardening/*`
- Provisioned and used dedicated MySQL test DB (`invoiceshelf_test`).
- Hardening suite now runs successfully on MySQL.
- PHPUnit marks these tests as `risky` due framework-level handler state changes during boot; assertions pass.
- Note: DB-destructive tests must run sequentially against one shared test DB to avoid cross-test table-drop races.

## Remaining work to fully close the optimization program

1. Run full-suite staging profiling with request timing logs enabled, then tune top P95/P99 endpoints.
2. Add repeatable load tests for payment/appointment hot paths (parallel user simulation) and capture lock/contention metrics.
3. Tune MySQL server-level settings on the target host by actual RAM/CPU envelope (not generic defaults), then re-check slow query log.
4. Validate Redis memory/eviction settings under sustained load and confirm queue throughput/SLA.
5. Calibrate PHP-FPM pool sizing (`pm.max_children`, `pm.max_requests`) from measured concurrency and memory usage on the deployment host.
