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
Nginx configuration, or an unreadable web root.

Before installing `/etc/cron.d/cannonminer`, setup removes active or commented
legacy Python collector entries that invoke `run_main.sh` or CannonMiner's
`main.py` from the original operator's personal crontab. Other entries in that
crontab are preserved.

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
