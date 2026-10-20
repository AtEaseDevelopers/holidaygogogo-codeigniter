-- 20 Oct 2026: the quotation hotel pricing table can auto-fill the 2nd+ price
-- columns as a percentage of the first column. Persist that per-column percentage
-- as a JSON array aligned to quote_hotel_columns (null means no %), so the editor
-- shows it back on reopen. Plain ADD COLUMN -- MySQL 9 rejects IF NOT EXISTS and the
-- runner swallows the duplicate-column re-run error.
ALTER TABLE `costing_packages` ADD COLUMN `quote_hotel_pcts` TEXT NULL DEFAULT NULL;
