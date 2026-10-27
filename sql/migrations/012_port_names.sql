-- AISSeaStats 1.2.0 — names of UN/LOCODE ports (NLRTM -> Rotterdam)
-- Filled only when the admin clicks "Fill in the names of UN/LOCODE ports" (downloaded by the station itself,
-- not shipped with AISSeaStats). The destination dictionary keeps priority.
CREATE TABLE IF NOT EXISTS port_name (
  code  CHAR(5)     NOT NULL PRIMARY KEY,
  name  VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
