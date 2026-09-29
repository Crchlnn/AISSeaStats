# Changelog

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
