# Changelog

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
