# RDS App Performance Notes

## Database

- MariaDB tuned for the Royal Dental Services workload: `innodb_buffer_pool_size` bumped to ~1 GB and both `tmp_table_size`/`max_heap_table_size` set to 64 MB so larger joins and sorts stay in-memory instead of spilling to disk.
- Added selective indexes on `created_at` for invoices, expenses, payments, and estimates plus lookup indexes on expense categories/payment methods, which keeps the high-traffic lists performant.
- `opcache.validate_timestamps` is disabled on this instance (`0`) so PHP-FPM must be reloaded after deployments (the manual `systemctl restart php8.2-fpm` step ensures opcode caches see code changes).

## Redis / Cache / OPCache

- Redis powers sessions, cache, and queues (`SESSION_DRIVER`, `CACHE_DRIVER`, `QUEUE_CONNECTION` are all `redis` per the production `.env` workflow). `config/queue.php` and `config/session.php` already point to the configured `phpredis` connection, and the extra caching was exercised with `php artisan config:cache`, `route:cache`, and `view:cache` runs to populate optimized files.
- Request timing logging (`App\Http\Middleware\RequestTimingLogger`) is enabled; logs land in `storage/logs/royal-timing.log` so we can spot slow endpoints and regressions without adding more instrumentation.
- Eager-loading improvements across invoices/payments/estimates/recurring invoices reduce round-trips, allowing Redis-backed caches to stay warm and time-sensitive pages to return faster.

## PHP-FPM

- The app runs on system `php8.2-fpm`; with OPCache time-stamping disabled, every deployment explicitly restarts the service to flush stale bytecode and pick up the newly edited controllers/requests/resources.
- Restarting `php8.2-fpm` also ensures the MariaDB connection pool reuses the optimized settings, so both PHP and the database benefit from the same maintenance window.

## Observability

- Beyond the timing log, Laravel cache tools remain in place via `php artisan optimize`, which rebuilds the compiled container, routes, and config for each release; this keeps Redis-backed caches in sync with code changes.
