-- Run with: php index.php run_sql_patches
ALTER TABLE `ghl_contacts` ADD COLUMN `high_chance` TINYINT(1) NOT NULL DEFAULT 0;
