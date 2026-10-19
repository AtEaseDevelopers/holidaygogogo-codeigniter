-- Backfill existing costing package tour codes to the uppercase QU- prefix.
-- Format is now QU-YYMM-XXXX (generator switched from lowercase "qu" to "QU").
-- Only the fixed prefix changes, the YYMM and sequence stay intact. Safe to
-- re-run as rows already uppercased are left unchanged by the WHERE clause.
UPDATE costing_packages
SET tour_code = CONCAT('QU', SUBSTRING(tour_code, 3))
WHERE tour_code LIKE BINARY 'qu-%';
