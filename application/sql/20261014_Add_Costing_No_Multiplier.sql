-- "No multiply" (Total = Cost) flag, driven by the Costing CATEGORY master. Any
-- category flagged no_multiplier has its cost-template rows left UN-multiplied by
-- the day/pax count: each row's Total equals its MYR-converted Cost. In the
-- customer quotation that category's cost is carried as a FLAT lump (marked up by
-- the gross margin) instead of being divided per pax. Mirrors the sibling
-- has_headcount_multiplier flag.
--
-- Plain ADD COLUMN (no IF NOT EXISTS) because MySQL rejects that syntax for
-- columns. The patch runner treats a re-run duplicate-column (1060) as non-fatal.
-- Keep comments free of semicolons -- the runner splits statements on the
-- semicolon character.

ALTER TABLE `costing_categories`
    ADD COLUMN `no_multiplier` TINYINT(1) NOT NULL DEFAULT 0 AFTER `has_headcount_multiplier`;
