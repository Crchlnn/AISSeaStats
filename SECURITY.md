# Security policy

AISSeaStats is meant to run on a home network next to an AIS receiver.

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting
("Security" tab → "Report a vulnerability") with the steps to reproduce.
You should get an answer within a week.

## Scope notes

- Exposing the app to the Internet is not a supported setup without a reverse proxy
  that adds TLS and authentication.
- The ingestion token is stored as a SHA-256 hash; the admin password with `password_hash()`.
- The only outbound call is the optional Wikimedia Commons photo lookup.
