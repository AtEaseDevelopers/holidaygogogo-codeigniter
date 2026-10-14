-- 14 Oct 2026: a quotation hotel row can now list MULTIPLE hotel names (one per
-- line) sharing a single price. A newline-separated list can exceed the old
-- VARCHAR(255), so widen the column to TEXT. Plain MODIFY (re-run safe).
ALTER TABLE `costing_quote_hotels` MODIFY `hotel_name` TEXT NULL DEFAULT NULL;
