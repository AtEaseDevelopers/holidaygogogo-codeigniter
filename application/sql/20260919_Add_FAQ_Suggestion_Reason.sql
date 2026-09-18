-- Add a short "why suggested" explanation to each FAQ suggestion — the AI's
-- one-sentence rationale for why the FAQ helps customers (frequency / booking
-- impact), shown to reviewers on the listing and the edit form. Plain ALTER
-- (the patch runner swallows duplicate-column warning 1060 as non-fatal).

ALTER TABLE `faq_suggestions`
  ADD COLUMN `Reason` VARCHAR(500) NULL AFTER `Description`;
