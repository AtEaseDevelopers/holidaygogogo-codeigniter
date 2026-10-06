-- Optional free-text note (multi-line) for a supplier quotation attachment, so
-- the uploader can jot context — validity window, who to contact, what changed —
-- next to the file. Distinct from the short single-line `title`. NULL means none.
-- Plain ADD COLUMN (no IF NOT EXISTS): MySQL rejects that syntax for columns,
-- and the patch runner treats a re-run "Duplicate column name" (1060) as non-fatal.
ALTER TABLE `costing_quotation_files`
    ADD COLUMN `note` TEXT NULL DEFAULT NULL AFTER `title`;
