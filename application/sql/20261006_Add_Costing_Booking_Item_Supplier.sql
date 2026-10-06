-- Optional supplier per combination cost item. The user may pick an existing
-- supplier by name OR type a brand-new name here. A typed-in name is NOT saved
-- back to the supplier master (free text, per-item only). NULL/blank means none.
-- Plain ADD COLUMN (no IF NOT EXISTS): MySQL rejects that syntax for columns,
-- and the patch runner treats a re-run "Duplicate column name" (1060) as non-fatal.
ALTER TABLE `costing_booking_items`
    ADD COLUMN `supplier` VARCHAR(255) NULL DEFAULT NULL AFTER `remark`;
