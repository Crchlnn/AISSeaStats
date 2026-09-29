-- AISSeaStats — initial schema
-- All timestamps are UTC Unix epochs (INT UNSIGNED). `day` columns are local dates
-- in the station time zone. Distances are nautical miles.

CREATE TABLE IF NOT EXISTS setting (
  name        VARCHAR(64)  NOT NULL PRIMARY KEY,
  value       MEDIUMTEXT   NOT NULL,
  updated_at  INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vessel (
  mmsi            INT UNSIGNED      NOT NULL PRIMARY KEY,
  name            VARCHAR(32)       NULL,
  callsign        VARCHAR(16)       NULL,
  imo             INT UNSIGNED      NULL,
  eni             VARCHAR(16)       NULL,
  shiptype        SMALLINT UNSIGNED NULL,
  vclass          VARCHAR(8)        NOT NULL DEFAULT '',
  length_m        SMALLINT UNSIGNED NULL,
  beam_m          SMALLINT UNSIGNED NULL,
  draught_m       DECIMAL(4,1)      NULL,
  country         CHAR(2)           NULL,
  destination     VARCHAR(32)       NULL,
  eta             VARCHAR(16)       NULL,
  first_seen      INT UNSIGNED      NOT NULL,
  last_seen       INT UNSIGNED      NOT NULL,
  msgs            INT UNSIGNED      NOT NULL DEFAULT 0,
  passages        INT UNSIGNED      NOT NULL DEFAULT 0,
  max_dist_nm     DECIMAL(6,2)      NULL,
  max_speed_kn    DECIMAL(5,1)      NULL,
  last_lat        DECIMAL(9,6)      NULL,
  last_lon        DECIMAL(9,6)      NULL,
  last_pos_ts     INT UNSIGNED      NULL,
  last_sog        DECIMAL(5,1)      NULL,
  last_cog        DECIMAL(4,1)      NULL,
  last_signal     DECIMAL(5,1)      NULL,
  cur_passage_id  BIGINT UNSIGNED   NULL,
  tags            VARCHAR(255)      NOT NULL DEFAULT '',
  static_updated  INT UNSIGNED      NOT NULL DEFAULT 0,
  KEY idx_last_seen (last_seen),
  KEY idx_first_seen (first_seen),
  KEY idx_tags (tags(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stats_hourly (
  hour_ts     INT UNSIGNED  NOT NULL PRIMARY KEY,
  msgs        INT UNSIGNED  NOT NULL DEFAULT 0,
  msgs_a      INT UNSIGNED  NOT NULL DEFAULT 0,
  msgs_b      INT UNSIGNED  NOT NULL DEFAULT 0,
  vessels     INT UNSIGNED  NOT NULL DEFAULT 0,
  max_dist_nm DECIMAL(6,2)  NULL,
  sig_sum     DOUBLE        NOT NULL DEFAULT 0,
  sig_n       INT UNSIGNED  NOT NULL DEFAULT 0,
  sig_min     DECIMAL(5,1)  NULL,
  sig_max     DECIMAL(5,1)  NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stats_daily (
  day          DATE         NOT NULL PRIMARY KEY,
  msgs         INT UNSIGNED NOT NULL DEFAULT 0,
  vessels      INT UNSIGNED NOT NULL DEFAULT 0,
  new_vessels  INT UNSIGNED NOT NULL DEFAULT 0,
  max_dist_nm  DECIMAL(6,2) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vessel_hourly (
  hour_ts INT UNSIGNED NOT NULL,
  mmsi    INT UNSIGNED NOT NULL,
  PRIMARY KEY (hour_ts, mmsi)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vessel_daily (
  day         DATE         NOT NULL,
  mmsi        INT UNSIGNED NOT NULL,
  msgs        INT UNSIGNED NOT NULL DEFAULT 0,
  max_dist_nm DECIMAL(6,2) NULL,
  PRIMARY KEY (day, mmsi),
  KEY idx_mmsi (mmsi)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS msgtype_daily (
  day   DATE             NOT NULL,
  type  TINYINT UNSIGNED NOT NULL,
  msgs  INT UNSIGNED     NOT NULL DEFAULT 0,
  PRIMARY KEY (day, type)
) ENGINE=InnoDB;

-- One sampled position per vessel per minute.
CREATE TABLE IF NOT EXISTS position (
  mmsi    INT UNSIGNED  NOT NULL,
  minute  INT UNSIGNED  NOT NULL,
  lat     DECIMAL(9,6)  NOT NULL,
  lon     DECIMAL(9,6)  NOT NULL,
  sog     DECIMAL(5,1)  NULL,
  cog     DECIMAL(4,1)  NULL,
  PRIMARY KEY (mmsi, minute),
  KEY idx_minute (minute)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS passage (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mmsi        INT UNSIGNED    NOT NULL,
  start_ts    INT UNSIGNED    NOT NULL,
  end_ts      INT UNSIGNED    NOT NULL,
  entry_lat   DECIMAL(9,6)    NULL,
  entry_lon   DECIMAL(9,6)    NULL,
  exit_lat    DECIMAL(9,6)    NULL,
  exit_lon    DECIMAL(9,6)    NULL,
  moved_nm    DECIMAL(7,2)    NOT NULL DEFAULT 0,
  max_dist_nm DECIMAL(6,2)    NULL,
  msgs        INT UNSIGNED    NOT NULL DEFAULT 0,
  entry_zone  VARCHAR(48)     NULL,
  exit_zone   VARCHAR(48)     NULL,
  closed      TINYINT(1)      NOT NULL DEFAULT 0,
  KEY idx_mmsi (mmsi, start_ts),
  KEY idx_open (closed, end_ts),
  KEY idx_start (start_ts),
  KEY idx_route (entry_zone, exit_zone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zone (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(48)  NOT NULL,
  lat        DECIMAL(9,6) NOT NULL,
  lon        DECIMAL(9,6) NOT NULL,
  radius_nm  DECIMAL(6,2) NOT NULL,
  UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS range_polar (
  day         DATE             NOT NULL,
  sector      TINYINT UNSIGNED NOT NULL,
  max_dist_nm DECIMAL(6,2)     NOT NULL,
  mmsi        INT UNSIGNED     NOT NULL,
  PRIMARY KEY (day, sector)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS enrich_cache (
  imo         INT UNSIGNED  NOT NULL PRIMARY KEY,
  fetched_at  INT UNSIGNED  NOT NULL,
  found       TINYINT(1)    NOT NULL DEFAULT 0,
  thumb_url   VARCHAR(512)  NULL,
  page_url    VARCHAR(512)  NULL,
  author      VARCHAR(255)  NULL,
  license     VARCHAR(64)   NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ingest_log (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ts        INT UNSIGNED    NOT NULL,
  station   VARCHAR(64)     NULL,
  bytes     INT UNSIGNED    NOT NULL DEFAULT 0,
  msgs      INT UNSIGNED    NOT NULL DEFAULT 0,
  accepted  INT UNSIGNED    NOT NULL DEFAULT 0,
  ms        INT UNSIGNED    NOT NULL DEFAULT 0,
  error     VARCHAR(255)    NULL,
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
