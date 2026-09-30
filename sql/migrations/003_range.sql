-- AISSeaStats 1.0.0-beta.5 — longer plausible range
-- Tropospheric ducting can bring AIS messages from well over 1,000 NM away.
-- The old default (200 NM) is raised to 1,500 NM; a value chosen by the owner is kept.
UPDATE setting SET value = '1500', updated_at = UNIX_TIMESTAMP() WHERE name = 'max_range_nm' AND TRIM(value) = '200';
