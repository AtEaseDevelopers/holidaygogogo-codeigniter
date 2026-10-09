-- Admin two-factor auth (Google Authenticator / TOTP, RFC 6238).
-- NOTE: the patch runner splits on the statement separator char, so comments
-- here must contain none of it.
--   TotpSecret   : the shared secret, AES-256-GCM encrypted at rest (base64 token
--                  from totp_encrypt, key is TOTP_ENCRYPTION_KEY in .env). NULL
--                  means never enrolled. Encrypted (reversible), NOT hashed --
--                  verification recomputes the HMAC and needs the original key.
--   TotpEnabled  : 'Y' only after the admin scanned the QR and confirmed one code
--                  (so a mis-scan cannot lock them out). 'N' means must enrol.
--   TotpLastStep : last successfully-accepted 30s time step. Replay guard -- a
--                  code at step below/equal this is rejected (one-time per window).
-- Plain ADD COLUMN (no IF NOT EXISTS): MySQL rejects that syntax for columns and
-- the patch runner treats a re-run "Duplicate column name" (1060) as non-fatal.
ALTER TABLE `admin`
    ADD COLUMN `TotpSecret` VARCHAR(255) NULL DEFAULT NULL AFTER `Password`,
    ADD COLUMN `TotpEnabled` ENUM('Y','N') NOT NULL DEFAULT 'N' AFTER `TotpSecret`,
    ADD COLUMN `TotpLastStep` BIGINT NULL DEFAULT NULL AFTER `TotpEnabled`;
