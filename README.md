# Deed

Deed tracks domain registrations in Kanboard. It looks domains, IP addresses and ASNs up through public RDAP (no
credentials), keeps a watchlist up to date every day, and opens exactly one renewal task per domain per expiry cycle,
on the board where the work already happens.

- **Domains** (user menu and dashboard sidebar, admins and app managers): the watchlist with registrar, expiry (red ≤ 7 days, amber ≤ 30),
  status, project and last check. Add one domain, paste a list (bulk add: commas, spaces or new lines), or refresh now.
- **Domain detail:** the registration record and its RDAP source, nameservers, statuses, the open renewal task, live
  DNS records (A, AAAA, MX, NS, TXT, CNAME, SOA, loaded on demand) and the change history.
- **Lookup:** a domain, an IPv4/IPv6 address or an ASN (`AS15169` or `15169`): registrar or network, range/handle,
  registrant org and abuse contact where published.
- **Project → Domains:** a read-only list of the project's linked domains for its members (and app admins).
- **Settings → Deed** (admins): default project for renewals, reminder thresholds (`30,14,7`), refresh hour (0–23),
  sweep batch size (1–200) and "Refresh bootstrap".

## Renewal tasks

When a domain is within the first threshold of its expiry (the earlier of the registry and registrar dates), Deed
creates one task `Renew {domain} (expires {Y-m-d})` in the domain's project (or the default project), due on the
expiry date and assigned to the domain's assignee. Later thresholds add a comment to that task. When a refresh shows a
later expiry, Deed comments "Renewed — registry now shows …" and closes the task. A task a person closed or deleted is
never reopened or recreated for the same cycle.

Deed finds its tasks through the task's **Reference** field (`deed:renewal:{id}`). Leave that field as it is: if it
is changed, Deed loses track of the task and stops commenting on it.

## Requirements

- Kanboard 1.2.47 or later
- PHP 8.4 or later with cURL and JSON
- SQLite, MariaDB/MySQL or PostgreSQL

Unicode domain names need to be entered as punycode (`xn--…`); port-43 WHOIS is not used.

## Install

Extract the release archive into Kanboard's `plugins/` directory so that `Plugin.php` is at
`plugins/Deed/Plugin.php`. The tables are created on the next page load.

Deed refreshes the watchlist from the first page view after the refresh hour, in short slices (about 10 seconds of a
request at a time). For a large watchlist, or a quiet instance, add a daily cron job as well:

```
0 6 * * * cd /var/www/app && ./cli deed:refresh
```

`./cli deed:refresh --domain example.com` refreshes one domain now.

## Politeness

Registries ask for light, identifiable use, and Deed keeps to that: it sends `Kanboard-Deed/<version> (+<your
Kanboard URL>)` as its User-Agent, makes one request at a time per RDAP server at least 2 seconds apart, honours
`429 Retry-After`, refreshes each domain once a day, and caches on-demand lookups for 24 hours (1 hour for "not
registered"). Failed refreshes back off (1 h, 6 h, 24 h) and never erase known data.

RDAP URLs come only from IANA's bootstrap files or one registrar referral. Every request is HTTPS to a public address,
pinned to the checked IP, with at most 2 redirects, a 10-second timeout and a 1 MiB body cap.

## Development

Run `../testing/run-plugin-tests.sh Deed` from the Deed directory in the Kanboard plugin harness, and
`../testing/run-realdb-tests.sh Deed mariadb|postgres` for the real databases.

Deed is distributed under the MIT license.
