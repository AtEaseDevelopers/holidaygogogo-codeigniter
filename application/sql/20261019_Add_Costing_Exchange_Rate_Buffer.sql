-- Add a per-currency-rate buffer (additive safety margin on the exchange rate).
-- Effective rate used in costing conversions = rate + buffer. Travels with the
-- rate row (date-sensitive, like `rate`) and is carried forward by the nightly
-- auto-rate cron, mirroring bank_charges_myr.
ALTER TABLE costing_exchange_rates ADD COLUMN buffer DECIMAL(18,8) NOT NULL DEFAULT 0 AFTER rate;
