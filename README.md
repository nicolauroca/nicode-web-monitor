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

A separate system plugin now serves read-only inventory over HTTPS, using a unique
site UUID and a revocable bearer credential. The site stores only its SHA-256 digest.
Rotation preserves site identity and rejects the old credential on the next request.
The central PHP client can now fetch and validate inventory with bounded HTTPS,
an enrolled IP and expected identity. Its transport core is tested separately;
the installable central component, guided pairing, alerting, updates and backups
remain pending. See `docs/central-client.txt` for its contract and test commands.
No data is pushed to external services. The console command uses the operating-system
account's existing access; the HTTP connector has its own read-only credential.

## Build and try in a disposable Joomla 6 installation

Requirements: Python 3 for packaging; an installed Joomla 6 with its supported PHP
and database. First tested on Joomla 6.1.4, PHP 8.4.26 and MariaDB 11.8.9 on Windows.

1. Run `python tools/build.py` from this repository.
2. Install `dist/plg_console_nicodewebmonitor-0.1.1-dev.zip` using Joomla's extension
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

## Read-only connector (development)

The build also produces `dist/plg_system_nicodewebmonitor-0.2.0-dev.zip`.
Install it in a disposable Joomla 6 site. In **System - Nicode Web Monitor**,
configure a unique lowercase UUID v4 and the SHA-256 digest of a cryptographically
random 32-byte credential encoded as 64 hex characters. Hash the encoded text,
not the decoded bytes. Keep the credential in a private secret store, never in Git,
URLs, logs or the plugin form. Enable the plugin only after configuring it.

The endpoint is `GET /index.php?option=com_nicodewebmonitor&task=inventory`, with
`Authorization: Bearer <credential>` over HTTPS. A valid response includes the site
UUID: clients must compare it with the identity they enrolled. Empty the digest
or disable the plugin to revoke access. Replace the digest to rotate credentials;
create a new UUID and credential for a cloned site before enabling its connector.

The web server must pass Authorization to PHP and set its trusted `HTTPS` server
variable. Client proxy headers are deliberately ignored. TLS termination through a
reverse proxy requires a correctly restricted server configuration; no automatic
proxy trust is implemented. Clients must verify certificates and must not forward
credentials across redirects. Cookies and Joomla login do not grant connector access.

This development version has one read-only credential per site; no maintenance
permissions, pairing UI or production web-server rate limiting is supplied yet.
See `docs/connector-contract.txt` and `docs/verification-2026-10-05.txt`.
