-- AISSeaStats 1.2.0 — history of declared destinations
-- One row each time a vessel declares a new destination (AIS type 5), for the network of ports:
-- previous destination -> new destination of the same vessel. Collected from 1.2.0-beta.1 on.
CREATE TABLE IF NOT EXISTS destination_change (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ts        INT UNSIGNED    NOT NULL,
  mmsi      INT UNSIGNED    NOT NULL,
  old_dest  VARCHAR(32)     NOT NULL,
  new_dest  VARCHAR(32)     NOT NULL,
  KEY idx_ts (ts),
  KEY idx_mmsi (mmsi, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
