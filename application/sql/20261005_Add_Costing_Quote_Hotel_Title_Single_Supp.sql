-- Quotation Hotel Pricing feedback (5 Oct 2026):
--   * the first (row-header) column title can be chosen as Hotel or Room Type
--   * the Single Supplement column can be added on or removed per package
--
-- Plain ALTER only, no IF NOT EXISTS. The patch runner swallows re-run 1060 as
-- non-fatal. No semicolons inside comments (the runner splits on them). MySQL 9.

-- First-column title choice. NULL / legacy defaults to Hotel in the helper.
ALTER TABLE costing_packages ADD COLUMN quote_hotel_title_label VARCHAR(40) NULL DEFAULT NULL;

-- Whether to show the Single Supp (RM) column. 1 = shown (default, matches legacy).
ALTER TABLE costing_packages ADD COLUMN quote_show_single_supp TINYINT(1) NOT NULL DEFAULT 1;
