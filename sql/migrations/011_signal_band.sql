-- AISSeaStats 1.2.0 — signal level by distance
-- Per day, distance bin of 2 NM (bin 0 = 0-2 NM … capped at 150 = 300 NM and beyond) and octant (0 = north, centred on it,
-- 45° each, clockwise): number of positions with a signal level, sum, lowest and highest level (dB).
CREATE TABLE IF NOT EXISTS signal_band (
  day     DATE              NOT NULL,
  bin     SMALLINT UNSIGNED NOT NULL,
  octant  TINYINT UNSIGNED  NOT NULL,
  n       INT UNSIGNED      NOT NULL,
  sig_sum DOUBLE            NOT NULL,
  sig_min DECIMAL(5,1)      NOT NULL,
  sig_max DECIMAL(5,1)      NOT NULL,
  PRIMARY KEY (day, bin, octant)
) ENGINE=InnoDB;
