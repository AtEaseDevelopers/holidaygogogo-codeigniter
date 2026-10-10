-- Server-side TOTP lockout, keyed to the admin row (NOT the session). The first
-- cut stored fail-count/lock in the CI session, but "Cancel and sign in again"
-- destroys the session and cleared the lock -- a trivial brute-force bypass. We
-- now persist it so logout/re-login cannot reset the throttle.
-- NOTE: the patch runner splits on the statement separator char, so comments
-- here must contain none of it.
--   TotpFailCount : consecutive failed code attempts. Reset to 0 on success or
--                   once a lock is applied.
--   TotpLockUntil : locked until this datetime (NULL means not locked). A verify
--                   attempt before this time is refused.
-- Plain ADD COLUMN (no IF NOT EXISTS): MySQL rejects that syntax for columns and
-- the patch runner treats a re-run "Duplicate column name" (1060) as non-fatal.
ALTER TABLE `admin`
    ADD COLUMN `TotpFailCount` INT NOT NULL DEFAULT 0 AFTER `TotpLastStep`,
    ADD COLUMN `TotpLockUntil` DATETIME NULL DEFAULT NULL AFTER `TotpFailCount`;
