ALTER TABLE `customer_analyses` ADD COLUMN `last_message_at` DATETIME NULL DEFAULT NULL AFTER `message_count`;
ALTER TABLE `customer_analyses` ADD COLUMN `covered_ghl` INT NOT NULL DEFAULT 0 AFTER `last_message_at`;
ALTER TABLE `customer_analyses` ADD COLUMN `covered_upload` INT NOT NULL DEFAULT 0 AFTER `covered_ghl`;
