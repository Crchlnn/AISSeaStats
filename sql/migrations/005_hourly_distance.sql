-- AISSeaStats 1.1.0 — distance per vessel and hour
-- Lets the 48 h chart split vessels by distance band, like the daily and monthly charts.
ALTER TABLE vessel_hourly ADD COLUMN IF NOT EXISTS max_dist_nm DECIMAL(6,2) NULL;

-- Fill the last 49 hours from the sampled positions, so the 48 h chart is split at once after the update.
-- Older hours stay "distance unknown" (hourly detail is only kept 35 days anyway). Skipped without a station position.
SET @slat = (SELECT CAST(value AS DOUBLE) FROM setting WHERE name = 'station_lat' AND value REGEXP '^-?[0-9]+(\\.[0-9]+)?$');
SET @slon = (SELECT CAST(value AS DOUBLE) FROM setting WHERE name = 'station_lon' AND value REGEXP '^-?[0-9]+(\\.[0-9]+)?$');
SET @since = (UNIX_TIMESTAMP() DIV 3600 - 49) * 3600;
UPDATE vessel_hourly vh JOIN (
    SELECT mmsi, (minute DIV 60) * 3600 AS h,
           MAX(2 * 3440.065 * ASIN(LEAST(1, SQRT(POW(SIN(RADIANS(lat - @slat) / 2), 2)
               + COS(RADIANS(@slat)) * COS(RADIANS(lat)) * POW(SIN(RADIANS(lon - @slon) / 2), 2))))) AS d
    FROM position WHERE minute >= @since DIV 60 AND @slat IS NOT NULL AND @slon IS NOT NULL
    GROUP BY mmsi, h
) x ON x.mmsi = vh.mmsi AND x.h = vh.hour_ts
SET vh.max_dist_nm = ROUND(x.d, 2)
WHERE vh.hour_ts >= @since AND vh.max_dist_nm IS NULL;
