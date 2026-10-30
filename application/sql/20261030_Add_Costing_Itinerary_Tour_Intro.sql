-- Tour Intro (header) — a standalone itinerary-wide rich-text block shown once at
-- the TOP of the Itinerary section on the Quotation PDF. Independent of the four
-- Notes & Terms section columns (itinerary_includes / excludes / important_notes /
-- terms_and_conditions). Plain ADD COLUMN (no IF NOT EXISTS, which MySQL 9 rejects)
-- so the patch runner treats a re-run 1060 duplicate-column error as non-fatal.
ALTER TABLE costing_packages ADD COLUMN itinerary_tour_intro TEXT NULL DEFAULT NULL;
