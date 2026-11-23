# Deed

Deed is a Kanboard plugin for tracking domain registrations and renewals. Version 1.0.0 is in development. The initial scaffold installs the database tables for the watchlist, change history, renewal cycles, and RDAP cache.

## Requirements

- Kanboard 1.2.47 or later
- PHP 8.4 or later with cURL and JSON
- SQLite, MariaDB, or PostgreSQL

To install, extract the release archive into Kanboard's `plugins/` directory so that `Plugin.php` is at `plugins/Deed/Plugin.php`.

## Development

Run `../testing/run-plugin-tests.sh Deed` from the Deed directory in the Kanboard plugin harness.

Deed is distributed under the MIT license.
