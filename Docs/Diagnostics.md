Run the installed-system test suite after setup or an upgrade:

```bash
cd /var/www/cannonminer
composer test
```

The diagnostic checks PHP and required extensions, PostgreSQL connectivity,
schema tables, required PHP extensions, settings, administrator and segment records, collected
measurements, and a complete `redball` to `portofino` route analysis. The route
smoke test reports elapsed time and fails if peak PHP memory reaches the
standard 128 MB PHP-FPM limit. It is read-only and does not call Google or
collect billable data.

Route analysis runs as a persisted job. The browser reports observation-loading
and scoring progress, estimated time remaining, and retains completed results
across refreshes. Setup configures PHP CLI and PHP-FPM with a 512 MB memory
limit, unlimited analysis execution time, and a one-hour Nginx FastCGI timeout.
Scoring progress is weighted by route segment count, and each database update
records a heartbeat. The WebUI warns when no heartbeat has been received for 90
seconds; fatal PHP shutdowns mark the run failed instead of leaving it running.
The packed observation model is still tested against a 128 MB peak-memory
target so the larger limit remains operational headroom.
