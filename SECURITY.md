# Security policy

AISSeaStats is meant to run on a home network next to an AIS receiver.

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting
("Security" tab → "Report a vulnerability") with the steps to reproduce.
You should get an answer within a week.

## Scope notes

- Exposing the app to the Internet is not a supported setup without a reverse proxy
  that adds TLS (and ideally authentication in front of the admin page).
- Secrets: the ingestion token is stored as a SHA-256 hash and compared in constant time; the admin password
  with `password_hash()`. Alert secrets (Telegram token, SMTP password…) are never sent back to the browser.
- Admin login: failed attempts are limited per address and in total (refused for 15 minutes past the limit);
  admin sessions end after 8 hours without activity. `bin/reset-admin.php` sets a new password and lifts a lockout.
- First run: the setup wizard asks for a one-time code shown only to whoever runs the server
  (end of `install.sh`, `docker compose logs app`, or `docker compose exec app php bin/setup-code.php`).
- Ingestion: optional source allow-list with `INGEST_ALLOW` (comma separated CIDRs; invalid entries are ignored
  and logged). Behind a reverse proxy, the address seen is the proxy's.
- Outbound network calls, all optional: vessel photo look-ups (Wikimedia Commons, Wikidata, at most 30 a minute,
  only for vessels the station received) and the alert channels configured by the admin (ntfy, Telegram,
  webhook, SMTP e-mail, heartbeat URL). Map tiles are loaded by the visitor's browser, not by the server.
- The public page and API show the station position; the admin can round it to about 1 km.
- Containers: the web server and PHP workers run as `www-data`; the background worker runs as `www-data`
  without Linux capabilities. The database port is not published.
