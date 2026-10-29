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
An installable central component now registers sites with separate read/create/delete
permissions and authenticated encryption of credentials. Its administrator form
supports enrollment, removal and explicit inventory inspection. Results include
Joomla/PHP/database versions, section availability and installed extensions with
versions and enabled state. Unknown data stays explicit. Full inventory is not retained.
Bounded check summaries and freshness states persist across visits; the dashboard
shows recent history (see `docs/check-history.txt`).
Internal inventory alerts and bounded state transitions are now shown in the
dashboard (see `docs/check-alerts.txt`). On narrow screens, each site's details,
alerts and actions stack vertically without hiding columns off screen. Browser
coverage and reproduction: `docs/verification-2026-10-11-browser.txt`.
An optional native Joomla task plugin now performs bounded automatic inventory
batches with explicit permissions and oldest-first selection. Setup and current
verification limits: `docs/automatic-monitoring.txt`.
Guided pairing and external notifications remain pending.
The 0.8.0-dev central component queues prepared Joomla core updates with separate
credentials and permissions, then reconciles their status. A remote CLI must still
execute and verify the queued operation; see `docs/core-dispatch.txt`.
Version 0.7.0-dev of the central component and 0.5.0-dev of the connector add
locally prepared plugin, module, component and template updates through the panel, with a separate credential and
permission, explicit confirmation, native installation and lost-response
reconciliation. Start-to-finish instructions: `docs/extension-update-http.txt`.
The central backup administrator forms support enrollment, explicit single-step
advance and status reconciliation, with separate permissions and CSRF checks.
An explicitly confirmed browser batch advances up to 100 steps sequentially,
supports pause/resume and stops on uncertainty without retrying. Keep the tab open;
this does not create a background job or renew the remote maintenance permit.
Version 0.6.0-dev adds the backup transport,
separate service ACL/credential and durable operation journal; see `docs/central-backups.txt`. Experimental connector backups are described below. See `docs/central-client.txt` for the transport contract and test commands,
and `docs/site-registry.txt` for central installation and credential limits.
A local development recovery primitive captures/restores files, configuration and
database schemas/rows into a separate directory and empty database, with corruption
checks and explicit uncertain operations. Restored Joomla CLI/frontend/login boot
has been tested in the disposable lab. A complete joint capture from central forms
was also restored and booted locally. Preconditions and reproduction:
`docs/local-site-recovery.txt` and `docs/local-database-snapshot.txt`.
A separate experimental backup operation core now reserves operation IDs before
capture, authenticates with a backup-only credential and retains receipts or
uncertain outcomes across process restarts without automatically rerunning capture.
It backs the synchronous Joomla backup route; the separate sequenced backup_site
route combines bounded file/database capture and is available in the central panel.
Contract and historical checks:
`docs/backup-operations.txt` and `docs/verification-2026-10-16.txt`.
No data is pushed to external services. The console command uses the operating-system
account's existing access; HTTP inventory and backup use separate credentials.

## Build and try in a disposable Joomla 6 installation

For a complete installation path, including both sites, maintenance and removal,
start with `docs/installation-from-zero.txt`.

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

The central registry and rendered inventory have also been tested against two
independently installed disposable Joomla sites over verified loopback TLS; see
`docs/verification-2026-10-10.txt` for coverage and remaining limits.

The ZIP has deterministic file order, timestamps and permissions. Generated packages,
local installations, database dumps, credentials and collected inventories stay out
of Git. License: GPL-2.0-or-later; see LICENSE.

## HTTPS connector (development)

The build also produces `dist/plg_system_nicodewebmonitor-0.5.0-dev.zip`.
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

This development version has one read-only inventory credential per site.
An experimental backup route uses a separate credential and a locally armed
maintenance window; see `docs/backup-http.txt` before enabling it in a disposable
site. Central backup UI/CSRF and real reader/operator HTTP permissions have been
tested in the disposable lab. Pairing UI and production rate limiting remain pending.
See `docs/connector-contract.txt` and `docs/verification-2026-10-05.txt`.

Joint resumable file/database capture is available in the development connector
through `backup_site`; see `docs/resumable-site.txt` for prerequisites, sequencing,
reconciliation and private layout. Capture is not proof of restoration. Central
backup forms, real user permissions and the operation journal are tested; an
append-only maintenance history and the remaining extension
types (packages and libraries) remain pending. Prepared extension updates are described in
`docs/extension-update-http.txt`; current integration evidence is in
`docs/verification-2026-10-27.txt`.

A local core-update preflight checks pinned update archives, installed versions and
Joomla's native database schema. It does not execute updates. See
`docs/core-update-preflight.txt` and `docs/verification-2026-10-27-core.txt` for the
disposable core-upgrade experiment. The HTTPS provider and central core panel are
now integrated; the opt-in host runner is described in `docs/core-dispatch.txt`.
