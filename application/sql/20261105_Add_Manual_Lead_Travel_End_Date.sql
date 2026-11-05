-- Run with: php index.php run_sql_patches
-- Preserve travel_date as the start date so existing single dates remain intact.
ALTER TABLE `ghl_contacts` ADD COLUMN `travel_end_date` DATE NULL;
