-- Add the AI-cost column to faq_suggestions for databases where the table was
-- created before CostUsd existed. Plain ALTER (no IF NOT EXISTS — the patch
-- runner swallows the duplicate-column warning 1060 as non-fatal, so a fresh
-- install that already got CostUsd from the CREATE is unaffected).

ALTER TABLE `faq_suggestions`
  ADD COLUMN `CostUsd` DECIMAL(12,6) NULL AFTER `Model`;
