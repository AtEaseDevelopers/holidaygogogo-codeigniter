-- Quotation System feedback (18 Sep 2026), Hotel & Flight rework:
--   item 3  + 4.4 : multiple flight OPTIONS, each with its own airline + schedule
--                   + pricing (compare AirAsia vs MAS vs Batik in one quotation)
--   item 4.2       : configurable hotel pricing COLUMNS (Twin / Triple / pax bands)
--                    and a per-column price on each hotel row
--   item 4.3       : flight MODE (No / Include / FIT / GIT)
--
-- Plain ALTER/CREATE only (no IF NOT EXISTS on ALTER, no semicolons inside
-- comments). The patch runner swallows re-run 1060/1061 as non-fatal. MySQL 9.

-- 4.3 flight mode + 4.2 hotel pricing column labels (JSON array of strings).
ALTER TABLE costing_packages ADD COLUMN quote_flight_mode VARCHAR(20) NULL DEFAULT NULL;
ALTER TABLE costing_packages ADD COLUMN quote_hotel_columns TEXT NULL DEFAULT NULL;

-- 4.2 per-column prices for a hotel row (JSON array aligned to quote_hotel_columns).
-- The legacy twin_triple_price column stays as the first-column mirror / fallback.
ALTER TABLE costing_quote_hotels ADD COLUMN prices_json TEXT NULL DEFAULT NULL;

-- 4.4 flight options: one row per airline option shown for comparison. Its
-- schedule rows live in costing_quote_flights (option_id FK below).
CREATE TABLE IF NOT EXISTS `costing_quote_flight_options` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `package_id`    BIGINT UNSIGNED NOT NULL,
  `sort_order`    INT UNSIGNED NOT NULL DEFAULT 0,
  `title`         VARCHAR(255) NULL DEFAULT NULL,
  `airline`       VARCHAR(120) NULL DEFAULT NULL,
  `price`         DECIMAL(12,2) NULL DEFAULT NULL,
  `fare_includes` TEXT NULL DEFAULT NULL,
  `fare_expiry`   VARCHAR(255) NULL DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_costing_quote_flight_options_package` (`package_id`),
  CONSTRAINT `fk_costing_quote_flight_options_package`
    FOREIGN KEY (`package_id`) REFERENCES `costing_packages` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tie each flight schedule row to its option (NULL = legacy, treated as option 1).
ALTER TABLE costing_quote_flights ADD COLUMN option_id BIGINT UNSIGNED NULL DEFAULT NULL;

-- Backfill: give every package that already has flight data (schedule rows OR any
-- package-level flight meta) one default option built from its package-level
-- fields, then attach its existing schedule rows to that option.
INSERT INTO costing_quote_flight_options (package_id, sort_order, title, price, fare_includes, fare_expiry, created_at, updated_at)
SELECT cp.id, 0, cp.quote_flight_title, cp.quote_flight_price, cp.quote_flight_fare_note, cp.quote_flight_expiry, NOW(), NOW()
FROM costing_packages cp
WHERE EXISTS (SELECT 1 FROM costing_quote_flights f WHERE f.package_id = cp.id)
   OR cp.quote_flight_title IS NOT NULL
   OR cp.quote_flight_price IS NOT NULL
   OR cp.quote_flight_fare_note IS NOT NULL
   OR cp.quote_flight_expiry IS NOT NULL;

UPDATE costing_quote_flights f
JOIN costing_quote_flight_options o ON o.package_id = f.package_id AND o.sort_order = 0
SET f.option_id = o.id
WHERE f.option_id IS NULL;
