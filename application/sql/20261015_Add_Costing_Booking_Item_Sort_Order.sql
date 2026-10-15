-- Explicit display order for cost item rows within a combination (or snapshot), so a
-- user can drag-and-drop to rearrange rows on the Costing template and have the order
-- persist (previously rows read back by incidental auto-increment id order).
-- Plain ADD COLUMN (no IF NOT EXISTS, which MySQL rejects for columns) — the patch
-- runner treats a re-run "Duplicate column name" (1060) as non-fatal. Legacy rows
-- default to 0, so id stays the tiebreaker and their original order is preserved.
ALTER TABLE `costing_booking_items` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0;
