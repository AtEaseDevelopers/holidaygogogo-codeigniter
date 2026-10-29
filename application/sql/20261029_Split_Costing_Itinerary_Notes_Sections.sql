-- Split the single merged itinerary "Notes & Terms" block back into four
-- independent rich-text sections: Include / Exclude / Important Notes / Terms &
-- Conditions. These columns ALREADY EXIST on costing_packages from the original
-- 4-section design (20260909_Add_Costing_Quotation_Feedback added includes /
-- excludes / important_notes, and terms_and_conditions came from the earlier Move
-- migration). This migration just makes sure they exist and re-activates them —
-- the code now reads/writes these four columns instead of the merged
-- itinerary_notes column (kept for a split-on-the-fly legacy fallback).
--
-- Plain ADD/DROP COLUMN (no IF NOT EXISTS) -- MySQL 9 rejects that syntax, so the
-- patch runner treats a re-run 1060 (duplicate column) / 1091 (drop missing) as
-- non-fatal, so these are safe to re-run.
ALTER TABLE costing_packages ADD COLUMN itinerary_includes TEXT NULL DEFAULT NULL;
ALTER TABLE costing_packages ADD COLUMN itinerary_excludes TEXT NULL DEFAULT NULL;
ALTER TABLE costing_packages ADD COLUMN itinerary_important_notes TEXT NULL DEFAULT NULL;
ALTER TABLE costing_packages ADD COLUMN itinerary_terms_and_conditions TEXT NULL DEFAULT NULL;

-- Clean up the short-lived singular columns an earlier revision of this patch
-- created before the original plural columns were rediscovered. Dropped if
-- present (1091 swallowed when they were never created).
ALTER TABLE costing_packages DROP COLUMN itinerary_include;
ALTER TABLE costing_packages DROP COLUMN itinerary_exclude;
ALTER TABLE costing_packages DROP COLUMN itinerary_terms;
