# Changelog

## 1.0.1 — unreleased

- The settings controller is now `DeedSettingsController`. Kanboard keys access rules by short controller name,
  so the admin-only rule for Deed's `SettingsController` also locked other plugins' settings pages to admins.
  URLs are unchanged (`deed/settings`).

## 1.0.0 — unreleased

- Domain watchlist with add, bulk add (paste a list) and refresh now; registrar, registry and registrar expiry,
  statuses and nameservers from public RDAP, following one registrar referral.
- Daily refresh from the first page view after the refresh hour (10-second slices, resumable) and a
  `./cli deed:refresh [--domain …]` command for cron.
- One renewal task per domain per expiry cycle, due on the expiry date, with threshold comments (30/14/7 days by
  default), a "Renewed" comment and close when the expiry moves out, and no duplicates or reopened tasks.
- "Not registered" (registry 404) shown in red, with a one-time comment on an open renewal task; failures back off
  and keep the last known data, and a domain with no success for 3 days is marked stale.
- Domain detail with live DNS records and a change history.
- Lookup page for domains, IP addresses and ASNs.
- Project "Domains" page for project members; settings for default project, thresholds, refresh hour and batch size.
- SSRF guard on every RDAP request (HTTPS, public addresses only, pinned IP, redirect, size and time caps) and polite
  pacing (≥ 2 s per RDAP server, Retry-After honoured, 24 h lookup cache).
- SQLite, MariaDB/MySQL and PostgreSQL schemas.
