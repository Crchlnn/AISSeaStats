-- AISSeaStats 1.0.0-beta.7 — debug capture of raw messages
-- Filled only while a capture is running (admin page), for a few vessels and a limited time.
CREATE TABLE IF NOT EXISTS debug_msg (
  id    BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ts    INT UNSIGNED     NOT NULL,
  mmsi  INT UNSIGNED     NOT NULL,
  type  TINYINT UNSIGNED NOT NULL,
  raw   TEXT             NOT NULL,
  KEY idx_mmsi (mmsi, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
