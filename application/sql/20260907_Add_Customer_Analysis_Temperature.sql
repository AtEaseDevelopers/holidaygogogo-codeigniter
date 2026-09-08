ALTER TABLE `customer_analyses` ADD COLUMN `temperature` VARCHAR(10) NULL DEFAULT NULL AFTER `message_count`;
ALTER TABLE `customer_analyses` ADD COLUMN `temperature_reason` VARCHAR(500) NULL DEFAULT NULL AFTER `temperature`;
ALTER TABLE `customer_analyses` ADD INDEX `idx_customer_analyses_temperature` (`temperature`);
