-- Backfill costing_bookings.quotation_token for snapshots created before the token
-- was wired. The public Quotation URL is now addressed by this unguessable token
-- (never the sequential package id), so every booking must have one or its dashboard
-- link would be blank. REPLACE(UUID(),'-','') yields a 32-char lowercase-hex string
-- matching the app's md5(uniqid()) token shape and the UNIQUE constraint — UUID() is
-- evaluated per row so each gets a distinct value. (MySQL 9 on this box has no MD5().)
UPDATE costing_bookings
SET quotation_token = REPLACE(UUID(), '-', '')
WHERE quotation_token IS NULL OR quotation_token = '';
