-- Background worker needs the on-disk (encrypted) file name to re-read an
-- uploaded PDF. Dev already created faq_suggestion_runs without this column;
-- this ALTER adds it there. On a fresh install the CREATE already includes it,
-- so this re-run is a harmless duplicate-column (1060) the runner swallows.
ALTER TABLE `faq_suggestion_runs` ADD COLUMN `StoredName` VARCHAR(255) NULL DEFAULT NULL AFTER `FileName`;
