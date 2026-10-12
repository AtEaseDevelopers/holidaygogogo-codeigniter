-- "No. of Tour Leaders"-style headcount multiplier, driven by the Costing
-- CATEGORY master (not a hardcoded category). Any category flagged
-- has_headcount_multiplier shows a per-combination count next to its section
-- header that scales ONLY that section's cost in the summary + quotation PDF (the
-- per-item cost-table rows stay untouched). Seeded ON for Tour Leader.
--
-- Plain ADD/DROP COLUMN (no IF NOT EXISTS) because MySQL rejects that syntax for
-- columns. The patch runner treats a re-run duplicate-column (1060) or
-- cant-drop-missing (1091) as non-fatal. Keep comments free of semicolons -- the
-- runner splits statements on the semicolon character.

-- Category master flag: which categories get the headcount multiplier.
ALTER TABLE `costing_categories`
    ADD COLUMN `has_headcount_multiplier` TINYINT(1) NOT NULL DEFAULT 0 AFTER `sort_order`;
UPDATE `costing_categories` SET `has_headcount_multiplier` = 1 WHERE `code` = 'tour_leader';

-- Per-combination, per-category counts as a JSON map of category_code to count.
ALTER TABLE `costing_combinations`
    ADD COLUMN `headcount_json` TEXT NULL DEFAULT NULL AFTER `sort_order`;

-- Drop earlier single-value attempts (superseded, non-fatal if never created).
ALTER TABLE `costing_combinations`
    DROP COLUMN `tour_leader_count`;
ALTER TABLE `costing_booking_financials`
    DROP COLUMN `tour_leader_count`;
