# CannonMiner

CannonMiner is a PHP 8.2 web application for comparing routes and recurring
departure windows against historical traffic-delay observations. It includes
authentication, route maps, database-backed configuration, scheduled
collection, and reports for traffic windows to avoid.

## Supported installation

Only Debian based linux distributions are currently supported.

The recommended flow is to clone or unpack the source anywhere convenient and
run the installer from that checkout:

```bash
git clone -b WebUI https://github.com/DLTaylor02/CannonMiner.git
cd CannonMiner
cp .env.example .env
nano .env #define your existing Postgres configuration or desired configuration
chmod +x setup.sh
./setup.sh
```

Use a different absolute deployment directory when necessary:

```bash
CANNONMINER_INSTALL_DIR=/srv/cannonminer bash setup.sh
```

The script installs and validates dependencies as well as installing the app itself:

- PHP 8.2 or newer, PHP-FPM, and the PostgreSQL, XML, mbstring, and cURL extensions
- Composer 2 and all PHP packages in `composer.json`
- PostgreSQL server and client
- Nginx
- cron

During first-time database setup, the installer optionally prompts for the
Google Maps API key with hidden input. Press Enter at the prompt to skip it and
add the key later under WebUI Settings. See `Docs\How to setup API key.md` for
instructions on how to setup your API key.

When the deployment does not already have an `.env`, setup securely copies the
one from the source checkout to `/var/www/cannonminer/.env`. If neither location
has one, setup creates a new `cannonminer` database and application account.

After the script completes, it will tell you the address for the application.

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
Enable both the Directions API and Maps Static API for that Google Cloud project.
Static route previews use stored overview polylines and color each segment from
green to red according to its simulated likelihood of a nontrivial slowdown.

## Diagnostics

Run the installed-system test suite after setup or an upgrade:

```bash
cd /var/www/cannonminer
composer test
```

## User Management

- The single install-created **superadmin** can manage all settings, segments, and users. Its role cannot be assigned, removed, or transferred in the WebUI. If you lose access to the superadmin account it can be reset with the following commands:
```bash
cd /var/www/cannonminer
composer reset-superadmin-password
```
- **Web admin**s can manage segments, users, and the default maximum risk. They cannot read or change the Google key, collection interval, or other collection settings.
- **User**s can run route analysis and view avoid trends. Route jobs always enforce the configured maximum risk for this role.

The first account created by setup is a superadmin. On upgrade, the oldest
existing account is promoted only when no superadmin exists.

## Layout

- `public/`: Nginx web root
- `src/Router.php`: route scoring
- `src/Collector.php`: Google Directions collection
- `database/`: schema, seeds, and legacy migration
- `templates/`: Twig UI
- `bin/collect.php`: manual and scheduled collector
