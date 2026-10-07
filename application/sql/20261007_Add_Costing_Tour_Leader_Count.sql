-- Per-combination "No. of Tour Leaders". Scales ONLY the tour_leader category
-- cost in THAT combination's summary + quotation PDF (the per-item cost-table
-- rows are left untouched). Default 1 = unchanged.
-- Plain ADD/DROP COLUMN (no IF NOT EXISTS): MySQL rejects that syntax for
-- columns, and the patch runner treats a re-run "Duplicate column name" (1060)
-- / "Can't DROP ... check that column/key exists" (1091) as non-fatal.
ALTER TABLE `costing_combinations`
    ADD COLUMN `tour_leader_count` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `sort_order`;

-- Drop the earlier package-level attempt (superseded by the per-combination column).
ALTER TABLE `costing_booking_financials`
    DROP COLUMN `tour_leader_count`;
