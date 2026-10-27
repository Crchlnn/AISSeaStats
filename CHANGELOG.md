# Changelog

French version: [docs/CHANGELOG.fr.md](docs/CHANGELOG.fr.md).

## 1.2.0 — 2026-10-27

Everything from 1.2.0-beta.1 (MQTT and Home Assistant, vessel alerts and summaries, constellation, records, signal and distance, hardening; see below), plus testers' feedback:

New
- Interesting vessels: the tags above the list are filters; click "Military" to list only military vessels, click again to see them all. The filter is applied in the database, so a rare tag is not lost beyond the 30 latest passages (issue #4)
- Constellation: zoom with the + / − / ⟲ buttons, a double click, Ctrl + wheel or a pinch, then drag to move; names are shown in full once zoomed and keep their size on screen. Hovering a destination shows how crews wrote it ("written: NL RTM, ROTTERDAM")
- Port names (admin → destination dictionary): "Fill in the names of UN/LOCODE ports" downloads UNECE's list from the station on demand (about 17,500 ports) and shows `NLRTM` as *Rotterdam* on the routes card, the constellation, vessel lists and cards. The list is not shipped with AISSeaStats (unclear licence); names typed in the dictionary keep priority
- Documentation: ports view screenshot; checking MQTT with `mosquitto_sub` and setting up Mosquitto with a user and password

Fixes
- Admin, dark theme chosen with the page's button: secondary buttons (Sign out, Statistics, Generate a new token…) had black text on a dark background
- Constellation on a phone: names running past the edge of the drawing are shortened, and drawn above the bubbles
- Signal and distance: no empty chart area while there is no data yet
- Top routes: compass sectors written in full ("West → West"); in French the one-letter "O" (Ouest) looked like a zero
- Admin on a phone: the list of debug log files no longer makes the page wider than the screen
- Destinations "FOR ORDERS" / "FOR ORDER" (waiting for orders) are no longer taken for a port
- Records, fastest vessel: up to 50 kn for high-speed craft and 40 kn for other vessels, so a corrupted speed (55 kn for a ferry) is left out
- Lost password: the login page and README say to run `docker compose exec app …` from the install folder, or `docker exec -it aisseastats-app-1 …` from anywhere (issue #5)
- CI and release: GitHub actions moved to their Node 24 versions (setup-qemu v4, setup-buildx v4, login v4, metadata v6, build-push v7)

Database: migration 012 (UN/LOCODE port names, empty until the admin downloads the list), applied automatically. From 1.1.6, migrations 009 to 012 are applied.

## 1.2.0-beta.1 — 2026-10-23

Test version: published only as the image tag `1.2.0-beta.1` (never `latest`). The final 1.2.0 follows in one or two weeks.

New
- Home Assistant through MQTT (admin → MQTT / Home Assistant): the worker keeps one connection to your MQTT server and publishes every minute, retained, the station state on `aisseastats/<station>/state` (vessels today, active vessels, new vessels today, messages today and in the last hour, today's range, range record, age of the last message, reception on/off). With discovery on, an "AISSeaStats" device and its 9 sensors appear in Home Assistant by themselves. `availability` is `online`, and the server publishes `offline` itself if the worker stops (last will). User and password, TLS with or without checking the certificate, test button. No message per vessel
- Vessel alerts (admin → Vessel alerts and summaries): watch list, distress, military, authorities and rescue, dangerous goods, very large, rare flag, superyacht, first visit, new range record, exceptional propagation; on by default once enabled: watch list, distress, military, range record. Once per vessel, reason and passage; several reasons in one message with type, flag, distance, direction, destination and a link to the vessel card. Quiet hours (distress excepted) and a maximum per hour; the last 100 messages are listed with their result
- Daily and/or weekly summaries at the chosen time: vessels, new vessels, messages, best range, interesting vessels, busiest hour; a button sends today's summary at once
- Channels per kind of message (reception, vessels, summaries) among ntfy, Telegram, webhook, e-mail and MQTT. Reception alerts keep going to every channel set up, MQTT included when it is enabled; the alerts' "Send a test" also tests MQTT. Webhook and MQTT messages carry `kind` and, for vessels, `mmsi`, `triggers`, `distance_nm` and `url`
- Admin → Station: optional public address of the page, used for the links in alerts and summaries and for Home Assistant's "Visit" link; `?mmsi=<number>` opens a vessel's card on the page
- Constellation: destinations declared by the vessels of the period around the station, with their vessel types (bubbles with a relief effect), or the network of ports linked by the vessels' destination changes, with arrows in the direction of travel; top 10, 20 or 30; click a bubble or an arrow to list its vessels; the same information as a list
- Records: furthest reception, largest vessel, fastest, rarest flag, busiest day, longest continuous presence, first vessel received
- Signal and distance: mean signal level by 2 NM distance bins over the period, for the whole station and by direction
- Admin rules: explanation that no MMSI block is reserved for military vessels

Hardening (second security review, 2026-10-22)
- Public API: each query is stopped after 20 seconds (MariaDB `max_statement_time`)
- Image build fails if a required PHP extension is missing (mbstring, pdo_mysql, json, zlib, OPcache)
- README: "Exposing to the Internet" (HTTPS reverse proxy, admin and ingestion kept on the home network, listening on 127.0.0.1 with `docker-compose.override.yml`, rate limiting, firewall)
- CI: the real Docker stack (database, web, worker) is started and tested end to end (setup with the one-time code, gzip ingestion, admin login, worker, data kept after a restart, processes running as `www-data`); MQTT tests against Mosquitto
- `docker-compose.hardened.yml` (optional): web container without Linux capabilities except those its servers need, `no-new-privileges`; being validated by the CI, so not used by default
- Non-blocking warning for an admin password under 8 characters (at setup and when changing it)
- Release workflow: also publishes the major tag (`1`), so `AISSEASTATS_VERSION=1` follows every 1.x release

Database: migrations 009 (alert log), 010 (history of declared destinations), 011 (signal level by distance), applied automatically. The history of destinations and the signal levels start with this version.

## 1.1.6 — 2026-10-21

Security (code review of 2026-10-21; no critical or high finding)
- Admin login: failed attempts are counted per address and in total; after 10 failures from one address, or 100 in all, within 15 minutes, logins are refused for 15 minutes, even with the right password. The 2-second pause after a failure is gone: it held a PHP worker, so a few failed logins a second could stall the site. `bin/reset-admin.php` also lifts the lock
- Setup wizard: asks for a one-time code shown at the end of `install.sh`, in the container logs and by `bin/setup-code.php`, so only the person running the server can become admin; two forms sent at the same time can no longer both complete the setup
- Vessel photos: looked up online only for vessels the station received, at most 30 look-ups a minute; old look-ups are purged
- Ingestion: posts with a wrong or missing token are no longer logged as batches (anyone could fill the log or distort the health figures); the admin still shows the last refusal, to spot a wrong token in AIS-catcher. The public summary no longer includes the last batch
- `INGEST_ALLOW`: an entry with a missing or invalid prefix (`192.168.1.0/`, `/abc`, `/40`) is ignored and logged; it used to allow every address
- Worker container: runs as `www-data`, without Linux capabilities and with `no-new-privileges`
- Admin → Station: option to round the position published on the public page and API to about 1 km; distances still use the exact position
- Admin sessions end after 8 hours without activity; the public page no longer opens a session for every visitor
- Zone names escaped on the admin map; `SECURITY.md` and the photo setting label updated (the photo look-up is not the only outbound call any more)

Database: migration 008 (failed admin logins), applied automatically.

## 1.1.5 — 2026-10-15

Improvements
- Top routes map: each route is drawn along its most typical real track, the one of its 12 latest passages closest on average to the others, instead of a straight line from the average entry to the average exit. On rivers and winding fairways the route follows the water; a detour or a bad position is not chosen. Without positions left for the period (kept 30 days by default), the straight line is kept
- Route arrows are sized in pixels (the same at every zoom) and outlined: short routes on a river or in a port are no longer covered by large triangles; the arrow of a route follows its highlight from the list
- Admin password: no minimum length any more (it only must not be empty), at setup and when changing it, as asked by several users; mismatched passwords now get their own message

No database migration.

## 1.1.4 — 2026-10-13

Improvements
- Traffic density map: the range of the period (solid line) and the all-time range record (dashed) are drawn by 10° sector around the station, like the range chart; the layer box at the top right of the map shows or hides each one (folded into an icon on a phone). Without density data yet, the map frames the range
- After an update, the worker builds the density history 240 hours per minute instead of 48: about 3 minutes for 30 days of positions instead of 15. Meanwhile the map says up to when the history is processed, instead of "No data yet"

No database migration.

## 1.1.3 — 2026-10-13

Fixes
- A batch from AIS-catcher could be lost with "Record has changed since last read in table 'stats_hourly'" (MariaDB 11.8, the `mariadb:11` image, refuses by default a transaction that writes a row changed meanwhile by the worker) (issue #2). The database sessions now use READ COMMITTED, and a batch stopped by such a conflict, a deadlock or a lock timeout is run again from the start (up to 4 times), counted once
- Two batches arriving at the same time (two receivers) no longer overwrite each other's vessel counts: the vessels and passages being updated are locked until the batch is saved

New
- Traffic density: heatmap of vessel tracks under the routes map (issue #3, suggested by @Phil353556). Vessel-hours per area of about 1 km, computed by the worker every hour and kept 400 days, for any period of the page; base stations and aids to navigation left out. After the update, the worker fills it from the positions still kept (30 days by default) within a few minutes
- Bundled Leaflet.heat 0.2.0 (BSD-2-Clause) to draw the heatmap

Database: migration 007 (table `traffic_cell`), applied automatically.

## 1.1.2 — 2026-10-10

Installation and updates
- Ready-made image `ghcr.io/crchlnn/aisseastats` (arm64 and amd64), used by default: `install.sh` downloads it (about a minute) and builds the image on the machine only if the download fails; `./install.sh --build` always builds it. Updating: `docker compose pull && docker compose up -d`, or `docker compose up -d --build` as before; both can be mixed on the same station
- `AISSEASTATS_VERSION` in `.env` pins an image tag (`1.1`, `1.1.2`…); empty means the latest release
- Installation without Git documented (`docker-compose.yml` and `.env` only), and automatic updaters such as Watchtower
- Release workflow: checks that the tag matches the version, `latest` only for final releases (not betas), `edge` for a manual run on main, no build attestation listed as an "unknown" platform

Improvements
- Top vessels and Regulars: 10, 20, 50 or 100 per page (remembered), with previous / next pages; the page goes back to 1 when the period or the ranking changes
- Vessel list of a chart bar: sort by messages (by name for an hour) or by distance; click a distance band to list only its vessels (up to 300 per band), click again to list them all
- In that list, distances are rounded down so that a vessel at 19.96 NM reads 19.9 NM next to its "< 20 NM" square

No database migration.

## 1.1.1 — 2026-10-10

Improvements
- Vessel list of a chart bar (hour, day or month): a coloured square shows each vessel's distance band, with the same colours as the chart, and its furthest distance; the totals per band for the whole bar are shown at the top
- Range by direction: the tooltip names the vessel that set each sector's record, with its date and time (for the period and all time)
- Furthest vessels: time of the record next to its day

Database: migration 006 (time of each range record; records of the last 30 days get their time from the sampled positions when a matching one exists), applied automatically.

## 1.1.0 — 2026-10-10

New statistics
- Vessel chart split by distance band (under 20 NM, 20–50 NM, 50 NM and beyond, unknown), for 48 h, days and months
- Exceptional propagation days (tropospheric ducting) flagged on the chart, with a link to the tropospheric forecast; furthest vessels of the period with day and direction
- Regulars: vessels that come back most regularly; time between passages (average, shortest, longest) on the vessel card
- Busiest hours: average vessels by weekday and hour (station time zone)
- Station reception: hour-by-hour strip for the last 14 days, share of hours with messages and list of interruptions

Database: migration 005 (distance per vessel and hour; the last 48 hours are filled from sampled positions), applied automatically.

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
