-- Quotation System feedback (18 Sep 2026), item 2.2 — foreign-currency bank
-- charge. The bank charge used to be baked into every per-unit "MYR (convert)"
-- figure and then multiplied by the day/pax count, so a RM120 charge on a 20-pax
-- line was billed 20 times. It is now a single per-line fee added ONCE to the line
-- total, and the frozen per-unit column stores the PURE foreign to MYR conversion.
--
-- Existing frozen values (costing_booking_items.myr_per_unit) still have the bank
-- charge baked in. Strip it back out (it was stored per row in bank_charges_myr)
-- so the stored per-unit is the pure conversion and the new once-per-line total
-- math applies uniformly. Rows with no frozen value (legacy pre-freeze) are left
-- alone, they convert live. This is a one-time data backfill, so the patch runner
-- must apply it exactly once (tracked by filename).
--
-- Plain UPDATE only (no IF NOT EXISTS, no semicolons inside comments). MySQL 9.

UPDATE costing_booking_items
SET myr_per_unit = GREATEST(0, ROUND(myr_per_unit - COALESCE(bank_charges_myr, 0), 2))
WHERE myr_per_unit IS NOT NULL;
