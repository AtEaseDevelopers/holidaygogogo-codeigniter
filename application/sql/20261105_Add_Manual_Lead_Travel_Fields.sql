-- Apply before deploying the Manual Leads form/export changes.
-- Run from the server project root: php index.php run_sql_patches
-- Keep one column per statement so the runner's duplicate-column handling
-- can continue adding missing columns after a partial/manual application.
ALTER TABLE `ghl_contacts` ADD COLUMN `travel_date` DATE NULL;
ALTER TABLE `ghl_contacts` ADD COLUMN `sales_person_id` INT NULL;
ALTER TABLE `ghl_contacts` ADD COLUMN `op_id` INT NULL;
ALTER TABLE `ghl_contacts` ADD COLUMN `destination` VARCHAR(255) NULL;
ALTER TABLE `ghl_contacts` ADD COLUMN `potential_sales_amount` VARCHAR(255) NULL;
