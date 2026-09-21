-- Quotation System feedback (18 Sep 2026), item 6 / general request:
-- a "General Combination" holds the cost items COMMON to every combination
-- (Tour Leader, Flight, etc.) so the user enters them once instead of duplicating
-- them into each hotel combination. Its cost is folded into every other
-- combination's price and it is never shown as its own customer option.
--
-- Plain ALTER only (no IF NOT EXISTS, no semicolons in comments). MySQL 9.

ALTER TABLE costing_combinations ADD COLUMN is_general TINYINT(1) NOT NULL DEFAULT 0;
