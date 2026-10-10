-- AISSeaStats 1.1.1 — time of each range record
-- Lets the range chart show when a record was set, not only the day.
ALTER TABLE range_polar ADD COLUMN IF NOT EXISTS ts INT UNSIGNED NULL;

-- Older records: take the time from the sampled positions (kept 30 days) when a sampled position of the same
-- vessel, in the same 10° sector, is within 0.5 NM of the record; the closest one wins. The page only shows
-- this time when it falls on the record's day (station time zone); other records keep their day only.
-- Each record reads its vessel's positions from 15 h before to 39 h after the day's start (any station time zone);
-- the bounds are computed once per record. Skipped without a station position.
SET @slat = (SELECT CAST(value AS DOUBLE) FROM setting WHERE name = 'station_lat' AND value REGEXP '^-?[0-9]+(\\.[0-9]+)?$');
SET @slon = (SELECT CAST(value AS DOUBLE) FROM setting WHERE name = 'station_lon' AND value REGEXP '^-?[0-9]+(\\.[0-9]+)?$');
CREATE TEMPORARY TABLE range_fill AS
    SELECT day, sector, mmsi, max_dist_nm,
           (UNIX_TIMESTAMP(day) DIV 60) - 900 AS lo, (UNIX_TIMESTAMP(day) DIV 60) + 2340 AS hi, CAST(NULL AS UNSIGNED) AS t
    FROM range_polar
    WHERE ts IS NULL AND day >= CURDATE() - INTERVAL 32 DAY AND @slat IS NOT NULL AND @slon IS NOT NULL;
UPDATE range_fill w SET w.t = (
    SELECT p.minute FROM position p
    WHERE p.mmsi = w.mmsi AND p.minute BETWEEN w.lo AND w.hi
      AND ABS(2 * 3440.065 * ASIN(LEAST(1, SQRT(POW(SIN(RADIANS(p.lat - @slat) / 2), 2)
          + COS(RADIANS(@slat)) * COS(RADIANS(p.lat)) * POW(SIN(RADIANS(p.lon - @slon) / 2), 2)))) - w.max_dist_nm) <= 0.5
      AND FLOOR(MOD(DEGREES(ATAN2(SIN(RADIANS(p.lon - @slon)) * COS(RADIANS(p.lat)),
          COS(RADIANS(@slat)) * SIN(RADIANS(p.lat)) - SIN(RADIANS(@slat)) * COS(RADIANS(p.lat)) * COS(RADIANS(p.lon - @slon)))) + 360, 360) / 10) MOD 36 = w.sector
    ORDER BY ABS(2 * 3440.065 * ASIN(LEAST(1, SQRT(POW(SIN(RADIANS(p.lat - @slat) / 2), 2)
          + COS(RADIANS(@slat)) * COS(RADIANS(p.lat)) * POW(SIN(RADIANS(p.lon - @slon) / 2), 2)))) - w.max_dist_nm), p.minute
    LIMIT 1
);
UPDATE range_polar r JOIN range_fill w ON w.day = r.day AND w.sector = r.sector
SET r.ts = w.t * 60
WHERE w.t IS NOT NULL AND r.ts IS NULL;
DROP TEMPORARY TABLE range_fill;
