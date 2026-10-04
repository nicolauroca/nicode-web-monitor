# Nicode Web Monitor

Free, open-source monitoring and maintenance for multiple Joomla sites, managed
from a component installed in a principal Joomla. Joomla 6 is the baseline;
future major versions will require compatibility testing.

Development is public from the beginning. **This is a development prototype,
not a complete or production-ready monitoring product.**

## Working today

An installable console plugin provides `nicode:inventory`. It reads the local
Joomla version, installed extensions (including disabled ones), PHP runtime and
database version into JSON. Collection failures are explicit, missing extension
versions stay unknown, and hosting-provider information is marked unavailable.

There is no HTTP endpoint yet. The central component, pairing/revocation, remote
inventory, alerting, updates and backups are planned and are not implemented.
No data is sent to external services. This local command uses the operating-system
account's existing access to Joomla; it is not the future remote authorization model.

## Build and try in a disposable Joomla 6 installation

Requirements: Python 3 for packaging; an installed Joomla 6 with its supported PHP
and database. First tested on Joomla 6.1.4, PHP 8.4.26 and MariaDB 11.8.9 on Windows.

1. Run `python tools/build.py` from this repository.
2. Install `dist/plg_console_nicodewebmonitor-0.1.0-dev.zip` using Joomla's extension
   installer, or `php cli/joomla.php extension:install --path=/absolute/path/to/the.zip`
   from the disposable Joomla root.
3. In System > Manage > Plugins, enable **Console - Nicode Web Monitor**.
4. From the Joomla root, run `php cli/joomla.php nicode:inventory`.

Standard output contains JSON. Exit code 0 means the implemented collectors ran
successfully; 2 means partial collection. Neither means the site is secure, backed
up, up to date or operationally healthy. PHP information describes the CLI process,
which can differ from the web server. Do not publish inventory output: software
versions and extension names remain operationally sensitive even without credentials.

## Verification

Run `php tests/inventory.php /absolute/path/to/disposable/joomla` from the repository.
It checks the real database inventory, malformed manifests, field allowlisting,
unavailable hosting, a deliberately missing database table and Joomla 5 rejection.
Tests do not write to Joomla. See `docs/verification-2026-10-04.txt` for executed
installation and command checks and their limits.

The ZIP has deterministic file order, timestamps and permissions. Generated packages,
local installations, database dumps, credentials and collected inventories stay out
of Git. License: GPL-2.0-or-later; see LICENSE.
