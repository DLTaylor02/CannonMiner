# CannonMiner

CannonMiner is a PHP 8.2 web application for comparing routes and recurring
departure windows against historical traffic-delay observations. It includes
authentication, route maps, database-backed configuration, scheduled
collection, and reports for traffic windows to avoid.

## Supported installation

The recommended flow is to clone or unpack the source anywhere convenient and
run the installer from that checkout:

```bash
git clone -b WebUI https://github.com/DLTaylor02/CannonMiner.git
bash setup.sh
cd CannonMiner
cp .env.example .env
nano .env            #define your existing Postgres configuration here or desired configuration
chmod +x setup.sh
./setup.sh
```

By default, setup synchronizes a production copy to `/var/www/cannonminer` and
continues installation there. Nginx serves only
`/var/www/cannonminer/public`; the source checkout is not hosted. Git metadata,
an existing `vendor/`, runtime logs, and the source checkout's `.env` are not
blindly copied over the deployment. An existing deployment `.env`, `vendor/`,
and `var/` are preserved during upgrades. If the deployment has no `.env`, an
existing source `.env` is copied with restricted permissions.

Use a different absolute deployment directory when necessary:

```bash
CANNONMINER_INSTALL_DIR=/srv/cannonminer bash setup.sh
```

On Debian and Ubuntu, the script installs and validates:

- PHP 8.2 or newer, PHP-FPM, and the PostgreSQL, XML, mbstring, and cURL extensions
- Composer 2 and all PHP packages in `composer.json`
- PostgreSQL server and client
- Nginx
- cron

It then creates or reuses the `cannonminer` database, creates a dedicated
database login, writes the bootstrap connection to `.env`, migrates the schema,
prompts for the first WebUI administrator, configures Nginx/PHP-FPM, and
installs the collector schedule. The script stops on unsupported operating
systems, old PHP versions, missing extensions, failed service checks, invalid
Nginx configuration, or an unreadable web root.

During first-time database setup, the installer optionally prompts for the
Google Maps API key with hidden input. Press Enter at the prompt to skip it and
add the key later under WebUI Settings.

The automatic installer currently supports systemd-based Debian and Ubuntu.
Other systems require the equivalent dependencies and manual web-server and
scheduler configuration. `composer serve` remains available for development;
PHP's built-in server is not the production setup.

Setup removes its temporary Nginx and cron staging files automatically. It
also synchronizes away obsolete application files in the deployment, while
preserving `.env`, `vendor/`, and `var/`. `setup.sh`, `composer.json`, and the
database migrations intentionally remain under `/var/www/cannonminer` for
repeatable upgrades and recovery; none are reachable through the configured
Nginx `public/` document root.

When the deployment does not already have an `.env`, setup securely copies the
one from the source checkout to `/var/www/cannonminer/.env`. If neither location
has one, setup creates a new `cannonminer` database and application account.

## Upgrading an original Python installation

Back up PostgreSQL before upgrading. Install this version over the original
project and run `bash setup.sh` on the same host. When the original
`cannonminer` database exists, the installer reuses it and grants the new
application role access. It imports original observation tables:

- `segment_<start>_to_<end>` observations

Legacy `segmentestimate_*` and other estimation tables are intentionally
ignored.

Imports are tracked by source table and row ID, so rerunning the installer does
not duplicate migrated measurements. Original tables are left intact and can
be retained until the migrated counts have been verified. Custom legacy tables
are imported when their `<start>_to_<end>` name matches a row in `segments`;
add custom segment rows before rerunning `bash setup.sh`.

During this stage setup prints a row-based progress bar, the active legacy
table, and a final imported/skipped summary. Imports are committed in batches,
so a long migration can be resumed by rerunning setup without duplicating
completed rows.

## Scheduled collection

Setup creates `/etc/cron.d/cannonminer`. Cron checks once per minute, while the
actual collection interval is read from PostgreSQL and can be changed under
WebUI Settings from 5 minutes to 7 days. PostgreSQL locking prevents overlapping
runs. Results and failures are recorded in `collection_runs`; collector output
is written to `var/collector.log`.

A manual collection ignores the interval:

```bash
composer collect
```

Collection requires a Google Maps API key and explicit confirmation in Settings
that the operator's Google agreement permits persistent traffic-data storage.

## Diagnostics

Run the installed-system test suite after setup or an upgrade:

```bash
cd /var/www/cannonminer
composer test
```

The diagnostic checks PHP and required extensions, PostgreSQL connectivity,
schema tables, required PHP extensions (including sodium for reference-compatible
Blake2b seeds), settings, administrator and segment records, collected
measurements, and a complete `redball` to `portofino` route analysis. The route
smoke test reports elapsed time and fails if peak PHP memory reaches the
standard 128 MB PHP-FPM limit. It is read-only and does not call Google or
collect billable data.

Route analysis runs as a persisted job. The browser reports observation-loading
and scoring progress, estimated time remaining, and retains completed results
across refreshes. Setup configures PHP CLI and PHP-FPM with a 512 MB memory
limit, unlimited analysis execution time, and a one-hour Nginx FastCGI timeout.
The packed observation model is still tested against a 128 MB peak-memory
target so the larger limit remains operational headroom.

## Users and themes

Every user can select Light, Dark, or Adaptive appearance from the header.
Preferences are stored per account.

- Superadmins can manage all settings, segments, and users, including other superadmins.
- Web admins can manage segments, users, and the default maximum risk. They cannot read or change the Google key, collection interval, or other collection settings.
- Users can run route analysis and view avoid trends. Route jobs always enforce the configured maximum risk for this role.

The first account created by setup is a superadmin. On upgrade, the oldest
existing account is promoted only when no superadmin exists.

## Router parity

`router.py` remains the behavioral reference for traffic weighting, empirical
quantiles, arrival propagation, risk thresholds, simulation count, eligibility,
and ranking. The PHP implementation retains raw delay distributions in compact
packed buffers rather than replacing them with averages. NumPy's seeded PCG64
stream is specific to NumPy; until the cross-language parity fixture confirms
the same recommendation and ordering, small Monte Carlo risk differences must
be treated as possible rather than silently described as exact parity.

## Configuration and security

Application configuration, administrator accounts, collection frequency, and
route segments live in PostgreSQL. `.env` contains only the bootstrap database
connection and is created with restricted permissions. Use HTTPS in front of
the generated Nginx site before exposing it beyond a trusted network.

## Licensing and Google data

CannonMiner code is distributed under the MIT License. This permits use,
copying, modification, redistribution, and commercial use while requiring the
copyright and license notice to be preserved. It fits the goal of software that
is free of charge and permissively reusable.

The MIT License covers this project's code only. It does not license Google
Maps content, API access, collected traffic responses, or Composer packages.
Google's standard terms generally restrict persistent storage of Maps content
except for specific documented allowances. An operator must have an applicable
agreement or permission for CannonMiner's historical-storage use case. See
`THIRD_PARTY_NOTICES.md` before enabling collection.

Composer dependencies retain their own licenses in `vendor/`. Setup generates
the exact resolved inventory at `var/composer-licenses.json`.

## Layout

- `public/`: Nginx web root
- `src/Router.php`: route scoring
- `src/Collector.php`: Google Directions collection
- `database/`: schema, seeds, and legacy migration
- `templates/`: Twig UI
- `bin/collect.php`: manual and scheduled collector
