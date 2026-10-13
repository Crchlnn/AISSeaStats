-- AISSeaStats 1.1.3 — traffic density (heatmap of tracks)
-- Vessel-hours per cell of about 1 km and per day (station time zone), filled by the worker from the sampled
-- positions, hour after hour. Kept 400 days, so the heatmap also covers periods longer than the positions.
-- cy = FLOOR(lat × 100), cx = FLOOR(lon × 100 × cos(lat)): cells of 0.01° of latitude, about 1.1 km wide everywhere.
CREATE TABLE IF NOT EXISTS traffic_cell (
  day  DATE         NOT NULL,
  cy   SMALLINT     NOT NULL,
  cx   SMALLINT     NOT NULL,
  n    INT UNSIGNED NOT NULL,
  PRIMARY KEY (day, cy, cx)
) ENGINE=InnoDB;
