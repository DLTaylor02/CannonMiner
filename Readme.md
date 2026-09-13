# CannonMiner

CannonMiner is a PHP 8.2 web application for comparing routes and recurring
departure windows against historical traffic-delay observations. It includes
authentication, route maps, database-backed configuration, scheduled
collection, and reports for traffic windows to avoid.

## Example screenshot

![Route Analysis Screenshot](Docs/ExampleScreenshot.JPG)

## Supported installation

Only Debian based linux distributions are currently supported.

Use the following commands to install:
```bash
git clone https://github.com/DLTaylor02/CannonMiner.git
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
- logrotate

When the deployment does not already have an `.env`, setup securely copies the
one from the source checkout to `/var/www/cannonminer/.env`. If neither location
has one, setup creates a new `cannonminer` database and application account.

During installation, setup asks which Nginx port CannonMiner should use. Press
Enter to accept port `3636`. CannonMiner is installed as an independent Nginx
site and does not replace, disable, or modify existing sites. Choose another
unused port if `3636` is already occupied. The port may also need to be allowed
through the server firewall. The port can also be supplied as the first argument
for an unattended run, such as `./setup.sh 3637`.

See `Docs\How to setup API key.md` for instructions on how to setup your API key.
During first-time database setup, the installer prompts for the Google Maps API
key with hidden input. You can enter the API key at this time or press Enter to
skip it and add the key later under WebUI Settings.

After the script completes, it prints the address including the selected port,
for example `http://192.168.1.106:3636/`.

## Diagnostics

Run the installed-system test suite after setup or an upgrade:

```bash
cd /var/www/cannonminer
composer test
```

## User Management

- The installer will create a **superadmin** to manage all settings, segments, and users. Its role cannot be assigned, removed, or transferred in the WebUI. If you lose access to the superadmin account it can be reset with the following commands:
```bash
cd /var/www/cannonminer
composer reset-superadmin-password
```
- **Web admin**s can manage segments, users, and the default maximum risk. They cannot read or change the Google key, collection interval, or other collection settings.
- **User**s can run route analysis and view avoid trends. Route jobs always enforce the configured maximum risk for this role.
- New and changed passwords for web admins and users must meet the password policy configured by the superadmin. Existing passwords continue to work until an administrator selects **Require change** for that account.
- Sign-in failures are limited by both username and client address. The superadmin configures the attempt limit and temporary lockout duration under Settings.
- Passwords are checked against the Have I Been Pwned Pwned Passwords range service. Only the first five characters of a locally calculated SHA-1 hash are sent. If the service is unavailable, a locally valid password is accepted and the user receives an advisory.

## Route selection

CannonMiner finds the available routes between the selected starting point and destination. For each route, it considers every month and weekday combination at 15-minute intervals by default. A candidate is scored only when every segment has direct observations from the same weekday, the same or an adjacent month, and the relevant time window. CannonMiner never fills an unsupported segment with an assumed value.
Each route and departure-time combination is evaluated using:
- Distance and the selected target average speed
- Typical historical delay for each segment
- Traffic observations near the estimated time the vehicle would reach each segment
- Seasonal and weekday traffic patterns
- Simulated delay outcomes based on the collected delay distribution
- The probability of a meaningful slowdown on any segment or across the complete route
Options exceeding the selected maximum delay risk are excluded when possible. Maximum-risk fields in the WebUI are entered as percentages from `0` to `100`; for example, enter `43` for 43%. Balanced and Fastest then favor the lowest expected travel time, with delay risk used as a tie-breaker. Reliability favors the lowest delay risk first, with expected travel time used as a tie-breaker.
Exactly three supported combinations are displayed. The first is the highest-ranked option. The remaining choices favor a different day, route, or meaningfully different departure window while preserving the normal ranking order. If no option satisfies the maximum-risk setting, CannonMiner displays the best available supported alternatives instead of returning no result.

Confidence is separate from risk. CannonMiner repeatedly resamples the existing simulation outcomes to measure how often a candidate remains in the top three. Evidence coverage reaches 50% when the least-supported segment has the median sample count among the candidates being compared. The displayed confidence is the geometric mean of ranking stability and evidence coverage. Repeating an identical automated calculation does not add calendar evidence: only the latest equivalent calculation contributes. Calendar points are `confidence x (1 - risk)`, and all three results can contribute.

Calculation methods are versioned. Existing calculations remain in the database and their direct result links continue to work, while the dashboard, Calculated Runs, and Calendar display only calculations produced by the current method. The dynamically calibrated confidence calculation is method version 3.
