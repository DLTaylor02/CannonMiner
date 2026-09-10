## Scheduled collection

Setup creates `/etc/cron.d/cannonminer`. Cron checks once per minute, while the
actual collection interval is read from PostgreSQL and can be changed under
WebUI Settings from 5 minutes to 7 days. PostgreSQL locking prevents overlapping
runs. Results and failures are recorded in `collection_runs`; successful
collections and failures are written to `/var/log/cannonminer/collector.log`.
Routine scheduled checks that find a collection is not due, or already running,
do not write a log entry. During upgrades, setup removes active and
commented legacy Python collector entries from the installing user's crontab.

A manual collection ignores the interval:

```bash
composer collect
```

Collection requires a Google Maps API key and explicit confirmation in Settings
that the operator's Google agreement permits persistent traffic-data storage.
Enable both the Directions API and Maps Static API for that Google Cloud project.
Static route previews use stored overview polylines and color each segment from
green to red according to its simulated likelihood of a nontrivial slowdown.

## Google API request telemetry

CannonMiner records each attempted Directions API request and each Maps Static
API request that it sends. Static map images are served through an authenticated
CannonMiner endpoint so the application can count the request and avoid sending
the API key to the browser. Request telemetry is retained for 90 days.

The dashboard shows observed requests per hour for the last 48 hours and a
24-hour forecast. The forecast combines the configured collection frequency and
enabled segment count with the average hourly static-map usage from the previous
24 hours. It is an operational estimate, not an authoritative Google billing or
quota report; consult Google Cloud for billable usage.
