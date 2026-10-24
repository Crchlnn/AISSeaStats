-- AISSeaStats 1.2.0 — vessel alerts
-- One row per alert sent (or tried): which vessel, which trigger, during which passage, and the channels' results.
-- A trigger alerts at most once per vessel and passage; events not tied to a vessel use mmsi 0.
CREATE TABLE IF NOT EXISTS vessel_alert (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ts          INT UNSIGNED    NOT NULL,
  mmsi        INT UNSIGNED    NOT NULL,
  trig        VARCHAR(24)     NOT NULL,
  passage_id  BIGINT UNSIGNED NULL,
  title       VARCHAR(200)    NOT NULL,
  results     VARCHAR(500)    NULL,
  KEY idx_ts (ts),
  KEY idx_once (mmsi, trig, passage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
