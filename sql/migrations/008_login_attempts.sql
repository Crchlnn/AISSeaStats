-- AISSeaStats 1.1.6 — failed admin logins, to slow down password guessing
-- One row per failed attempt (address in binary form); rows older than a day are purged by the worker.
CREATE TABLE IF NOT EXISTS login_attempt (
  id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip  VARBINARY(16)   NOT NULL,
  ts  INT UNSIGNED    NOT NULL,
  KEY idx_ip_ts (ip, ts),
  KEY idx_ts (ts)
) ENGINE=InnoDB;
