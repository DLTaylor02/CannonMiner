## Scheduled collection

Setup creates `/etc/cron.d/cannonminer`. Cron checks once per minute, while the
actual collection interval is read from PostgreSQL and can be changed under
WebUI Settings from 5 minutes to 7 days. PostgreSQL locking prevents overlapping
runs. Results and failures are recorded in `collection_runs`; collector output
is written to `var/collector.log`. During upgrades, setup removes active and
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