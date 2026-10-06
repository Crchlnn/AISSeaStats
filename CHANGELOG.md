# Changelog

French version: [docs/CHANGELOG.fr.md](docs/CHANGELOG.fr.md).

## 1.0.0-beta.8 — 2026-10-06

New
- Debug capture also writes a dated JSON Lines file (`capture_YYYYMMDD_HHMMSS.jsonl`), live and untruncated, listed in the admin with download and delete; kept 30 days. Contributed by @Phil353556 (pull request #1)
- Vessel card: link to the vessel on aiscatcher.org (the site of the AIS-catcher community)

Fixes
- MarineTraffic link removed: MarineTraffic no longer opens a vessel from its MMSI, neither by URL nor by search (its pages use an internal id)
- Debug capture files: Docker volume `debug-logs` mounted on `/data/debug`, writable by PHP and kept across updates, with no manual setup; tests for the log file

Docs
- README links AIS-catcher's JSON field reference

## 1.0.0-beta.7 — 2026-09-30

New
- Admin, "Debug: received messages": records the raw messages of a few vessels (or all of them for 1 hour), shows them per vessel and AIS type, and downloads them as JSON. Stops by itself; deleted after 7 days
- Vessel card: when the name is missing, explains that it has not been received yet (identity message AIS type 5 or 24)

Changes
- Vessel chart: 30 and 90 days always show 30 and 90 bars, and 1 year 12 months, even when the station is new (empty days are no longer hidden)

Database: migration 004 (debug capture table), applied automatically.

## 1.0.0-beta.6 — 2026-09-30

New
- Alerts (admin): one message when no AIS message has been received for N minutes (30 by default), one when reception comes back. Channels: ntfy, Telegram, webhook (payload readable by Discord, Slack, Mattermost, Gotify, Home Assistant) and e-mail over SMTP (STARTTLS or SSL/TLS). "Send a test" reports the result of each channel
- Optional heartbeat URL (healthchecks.io, Uptime Kuma…), called every 5 minutes while reception works, to be warned when the station itself is off
- Inland vessels: the ship type is taken from the Inland AIS (ERI) data until the vessel's own static message arrives, so barges no longer show "Type not declared"

Changes
- Admin: the database tile is now "Data (tables)", with an explanation of why MariaDB's Docker volume is bigger (fixed-size transaction log)
- Admin: zone list fits on a phone
- README: screenshots
- CI: actions/checkout v5 and Ubuntu 24.04 runners; the smoke test starts from an empty database

No database migration.

## 1.0.0-beta.5 — 2026-09-30

Feedback from the second day of tests.

New
- Destination dictionary (admin): group the spellings of a port under one name, e.g. "SAINT-MALO, ST-MALO, FR SML" → FRSML. The admin lists the destinations received in the last 90 days with a "Group…" shortcut
- Meaningless destinations ("0", "Q", "NONE"…) are shown as "Unknown"
- Click a bar of the vessel chart to list the vessels of that hour, day or month

Changes
- Maximum plausible range raised from 200 to 1,500 NM by default (up to 3,000): tropospheric ducting brings messages from over 1,000 NM. Beyond 50 NM a position only counts for range records when the same vessel was received shortly before at a consistent position
- Privacy note about MMSI removed from the documentation (an MMSI identifies a vessel, not a person)
- Version history in the README (English and French) and a French changelog

Database: migration 003 (range 200 → 1,500 NM if the old default was still set), applied automatically.

## 1.0.0-beta.4 — 2026-09-30

Feedback from the first two stations.

Fixes
- Map tiles "Access blocked" (OpenStreetMap): the page now sends its origin as Referer
- Vessel chart could stay empty after switching period on some browsers: charts no longer animate and are updated in place
- Setup: password managers no longer offer to save the longitude as the user name
- The map no longer re-centres itself on each automatic refresh

New
- One period selector for the whole page (48 h, 7 d, 30 d, 90 d, 1 year, all); the vessel chart picks hourly, daily or monthly bars from it
- The last period and "Top vessels" choice are remembered
- 48 h chart shows the date under each midnight, with a separator
- Automatic refresh: status every minute, everything else every 5 minutes, with the time of the last update
- Click a vessel type, a flag, a route or a destination to list its vessels
- Fleet types and flags as readable bars on phones, with country names and larger flags
- Declared destinations merged when they differ only by spaces or punctuation ("FR SML" = "FRSML")
- Photos: your own photos (admin), then Wikimedia Commons and Wikidata by IMO or MMSI; ShipSpotting link
- Setup can pre-fill the station from AIS-catcher's config.json and picks the browser time zone
- Field-by-field help for AIS-catcher's HTTP output, warning for .local addresses
- README: Upgrade, Troubleshooting, backup and restore

Database: migration 002 (vessel photos, photo look-up cache), applied automatically.

## 1.0.0-beta.3 — 2026-09-29

- Flag derived from the MMSI when AIS-catcher does not send it
- "Rare flag" only applies once 100 vessels are known (configurable)
- Clearer AIS-catcher instructions at the end of the setup
- Scoping note made neutral for the public repository

## 1.0.0-beta.2 — 2026-09-29

- Fix: setup wizard and admin forms failed with "Invalid form token" in Docker (session started after output)
- Smoke test in CI covering setup, admin login and ingestion
- Setup shows neutral example coordinates
- Install time documented (5 to 10 min on a Raspberry Pi)
- install.sh checks memory and disk space

## 1.0.0-beta.1 — 2026-09-29

First test version.

- Ingestion from AIS-catcher's HTTP output (AISCATCHER and LIST protocols, gzip, Basic or Bearer token)
- Vessels, hourly and daily statistics, sampled positions, passages, range by 10° sector
- Routes inferred from passages, compass sectors or named zones
- Interesting vessel rules and watch list
- Statistics page (FR/EN, light/dark, mobile) with vessel card, Wikimedia Commons photos
- Setup wizard and admin page
- Docker Compose: MariaDB 11, lighttpd + PHP-FPM 8.3, background worker
- Demo traffic simulator and dependency-free tests
