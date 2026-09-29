-- AISSeaStats 1.0.0-beta.4 — vessel photos
-- Photos added by the station owner (admin page), stored in the database so that
-- backups and the Docker volume cover them.
CREATE TABLE IF NOT EXISTS vessel_photo (
  mmsi         INT UNSIGNED  NOT NULL PRIMARY KEY,
  mime         VARCHAR(32)   NOT NULL,
  width        SMALLINT UNSIGNED NOT NULL,
  height       SMALLINT UNSIGNED NOT NULL,
  data         MEDIUMBLOB    NOT NULL,
  credit       VARCHAR(255)  NULL,
  uploaded_at  INT UNSIGNED  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cache of free photo look-ups (Wikimedia Commons / Wikidata), keyed "imo:<n>" or "mmsi:<n>".
CREATE TABLE IF NOT EXISTS photo_lookup (
  k           VARCHAR(24)   NOT NULL PRIMARY KEY,
  fetched_at  INT UNSIGNED  NOT NULL,
  found       TINYINT(1)    NOT NULL DEFAULT 0,
  thumb_url   VARCHAR(512)  NULL,
  page_url    VARCHAR(512)  NULL,
  author      VARCHAR(255)  NULL,
  license     VARCHAR(64)   NULL,
  source      VARCHAR(16)   NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Replaced by photo_lookup (it only cached IMO category look-ups).
DROP TABLE IF EXISTS enrich_cache;
