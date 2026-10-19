-- Costing package revisions: link versions of the same quotation together.
-- revision_root_id = id of the original base package that owns the base tour code
--   (NULL on a base package which is its own root, set on every revision).
-- revision_number  = 0 for the base, 1 = REV01, 2 = REV02 and so on.
-- Plain ALTER ADD COLUMN (no IF NOT EXISTS) per the MySQL 9 rule. The runner
-- swallows duplicate-column (1060) on re-run.
ALTER TABLE costing_packages ADD COLUMN revision_root_id BIGINT UNSIGNED NULL AFTER tour_code;
ALTER TABLE costing_packages ADD COLUMN revision_number INT UNSIGNED NOT NULL DEFAULT 0 AFTER revision_root_id;
