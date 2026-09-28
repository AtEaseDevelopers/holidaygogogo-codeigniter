-- Store the exact text sent to the AI for a generation run (the noise-filtered,
-- deduped customer/agent transcript for chats / chat-file runs) so the runs
-- listing and detail page can show operators the input the suggestions came
-- from. PDF runs leave this NULL — the uploaded document is their input and its
-- name already shows as the run scope. MEDIUMTEXT (not TEXT) because the
-- transcript is capped at FAQ_SUGGESTION_MAX_CHARS (default 200,000 chars),
-- above TEXT's 64 KB limit. On a fresh install the CREATE already includes this
-- column, so this re-run is a harmless duplicate-column (1060) the runner swallows.
ALTER TABLE `faq_suggestion_runs` ADD COLUMN `InputText` MEDIUMTEXT NULL DEFAULT NULL AFTER `StoredName`;
