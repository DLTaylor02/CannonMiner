Run the installed-system test suite after setup or an upgrade:

```bash
cd /var/www/cannonminer
composer test
```

The diagnostic checks PHP and required extensions, PostgreSQL connectivity,
schema tables, required PHP extensions, settings, administrator and segment records, collected
measurements, and a complete `redball` to `portofino` route analysis. The route
smoke test reports elapsed time and fails if peak PHP memory reaches the
128 MB efficiency target. It is read-only and does not call Google or
collect billable data.

Route analysis runs as a persisted job. The browser reports observation-loading
and scoring progress, estimated time remaining, and retains completed results
across refreshes. A systemd-managed CLI worker processes queued jobs, so route
analysis does not hold a PHP-FPM request open. Setup gives CannonMiner its own
PHP-FPM pool, Unix socket, system account, and private session directory. The
512 MB memory limit and unlimited timers are pool-specific or passed explicitly
to CannonMiner CLI commands; global PHP settings are not changed. Nginx still
limits request bodies to 2 MB.
Scoring progress is weighted by route segment count, and each database update
records a heartbeat. The WebUI warns when no heartbeat has been received for 90
seconds; fatal PHP shutdowns mark the run failed instead of leaving it running.
The packed observation model is still tested against a 128 MB peak-memory
target so the larger limit remains operational headroom.

Inspect the isolated runtime services with:

```bash
systemctl status cannonminer-worker.service
systemctl status php$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')-fpm.service
sudo tail -n 100 /var/log/cannonminer/worker.log
```

All application-specific logs are stored under `/var/log/cannonminer`:

- `nginx-access.log` and `nginx-error.log`: CannonMiner's Nginx virtual host
- `php-error.log`: CannonMiner's dedicated PHP-FPM pool
- `collector.log`: successful collections and collection failures
- `automation.log`: queued automation batches and automation failures
- `worker.log`: analysis-worker failures

Routine scheduled checks that have no work to perform are silent, and the
one-second analysis progress poll is omitted from Nginx access logging. Logs
rotate daily, retain 14 rotations, and compress older rotations. PostgreSQL
remains a shared service and retains its system-level logging rather than
duplicating it under CannonMiner. Run
`composer automate` to enqueue an automation batch and record a telemetry
sample immediately. Scheduled dashboard telemetry is collected every 15
minutes by default and has its own superadmin setting, independent of the route
automation schedule.

Host and CannonMiner CPU are sampled over the same 250 ms interval and use the
same whole-system `0-100%` scale. CannonMiner CPU sums process ticks for the
dedicated system account, so the green line represents the portion of the red
host line attributable to CannonMiner rather than a per-core process average.

Dashboard CannonMiner storage includes the deployed application tree, every
file and rotated archive under `/var/log/cannonminer`, the dedicated session
directory, and the complete PostgreSQL database size reported by
`pg_database_size`. Shared operating-system and PostgreSQL service logs remain
part of "Other system" usage.
