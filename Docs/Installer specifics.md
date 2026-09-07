## Installer specifics

By default, setup synchronizes a production copy to `/var/www/cannonminer` and
continues installation there. Nginx serves only
`/var/www/cannonminer/public`; the source checkout is not hosted. Git metadata,
an existing `vendor/`, runtime logs, and the source checkout's `.env` are not
blindly copied over the deployment. An existing deployment `.env`, `vendor/`,
and `var/` are preserved during upgrades. If the deployment has no `.env`, an
existing source `.env` is copied with restricted permissions.

If an existing install of the legacy Python version or the  PHP version is
detected it then creates or reuses the `cannonminer` database, creates a dedicated
database login, writes the bootstrap connection to `.env`, migrates the schema,
prompts for the first WebUI administrator, configures Nginx/PHP-FPM, and
installs the collector schedule. The script stops on unsupported operating
systems, old PHP versions, missing extensions, failed service checks, invalid
Nginx configuration, an occupied requested port, or an unreadable web root.

CannonMiner listens on port `3636` by default. Setup prompts for a custom port
and preserves the existing CannonMiner port when an installation is upgraded.
Noninteractive installations use `3636`, or the existing port on upgrades;
`CANNONMINER_LISTEN_PORT` can supply an explicit value. The generated site does
not claim Nginx's `default_server`, remove the default-site symlink, or edit any
other application's server block.

The application runs under a non-login `cannonminer` system user. Setup creates
a dedicated PHP-FPM pool at `/etc/php/<version>/fpm/pool.d/cannonminer.conf`, a
socket at `/run/php/cannonminer.sock`, private sessions under
`/var/lib/cannonminer/sessions`, and a `cannonminer-worker.service` analysis
worker. Nginx retains its normal account and can read only the public document
tree and FPM socket. The `.env`, application source, session files, and runtime
data are not readable by other applications running as `www-data`.
Upgrades restart the analysis worker: queued jobs are retained, while a job that
was actively running during the restart is marked failed and can be run again.

The 512 MB memory limit and unlimited execution/input timers are scoped to the
CannonMiner FPM pool. Collector, diagnostic, password-reset, installer, and
analysis-worker CLI commands receive their limits explicitly instead of
changing global PHP CLI configuration. Setup removes global `99-cannonminer.ini`
files created by older CannonMiner releases.

Before installing `/etc/cron.d/cannonminer`, setup removes active or commented
legacy Python collector entries only when the same line contains both a
CannonMiner path and `run_main.sh`. Other entries in the original operator's
personal crontab are preserved.

When setup is run by a non-root operator it validates sudo once and immediately
re-executes the complete installer as root. The original operator remains the
application file owner and Composer runs as that operator. If setup is started
from an actual root login, the installer explicitly authorizes Composer's
noninteractive superuser mode.

Setup removes its temporary Nginx and cron staging files automatically. It
also synchronizes away obsolete application files in the deployment, while
preserving `.env`, `vendor/`, and `var/`. `setup.sh`, `composer.json`, and the
database migrations intentionally remain under `/var/www/cannonminer` for
repeatable upgrades and recovery; none are reachable through the configured
Nginx `public/` document root.
