-- Optional user-supplied competitor name, keyed in on the submit form. It is a
-- label only (recorded and shown in the results table / detail) and is never sent
-- to the AI. Plain ALTER ADD COLUMN (the patch runner rejects IF NOT EXISTS and
-- swallows a re-run 1060 duplicate-column warning as non-fatal).
ALTER TABLE competitor_analyses ADD COLUMN competitor_name VARCHAR(255) NULL AFTER product_name;
