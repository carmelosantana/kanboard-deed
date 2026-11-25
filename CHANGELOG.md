# Changelog

## 1.0.2 — 2026-11-25
- Security: a domain's project list now offers only projects where the user is a project manager or member (viewers and custom project roles are
  refused, since a custom role may forbid creating or closing tasks), and the assignee
  must be able to hold a task in that project. A renewal task whose assignee is not a project member is left
  unassigned instead of appearing on that user's dashboard.
- Security: each evaluation also unassigns an open renewal task whose owner cannot hold a task in its project,
  covering tasks created before this check and owners later removed from the project.
- Security: saving a domain with "Keep current project" and refreshing a domain now require write access to the
  linked project (or the default one a refresh falls back to). A viewer, a custom-role user, a removed member or a pre-upgrade
  mapping can no longer keep the link or trigger renewal task changes there. The scheduled sweep is unchanged.

## 1.0.1 — 2026-11-25

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
