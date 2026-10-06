-- Supplier quotation attachments (PDF / Word / Excel / image rate sheets) kept
-- against a costing package as a future reference for the Cost Template & Margin
-- step. Keyed to costing_packages.id (the STABLE anchor) rather than to a cost
-- item, because costing_booking_items are deleted and re-inserted on every cost
-- save. A file is optionally tagged with a supplier name so each cost item can
-- surface the quotation(s) for its supplier. Soft-delete via status Y/N.
CREATE TABLE IF NOT EXISTS `costing_quotation_files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `costing_package_id` BIGINT UNSIGNED NOT NULL,
  `supplier` VARCHAR(255) NULL DEFAULT NULL,
  `title` VARCHAR(255) NULL DEFAULT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_path` VARCHAR(512) NOT NULL,
  `file_size` INT UNSIGNED NULL DEFAULT NULL,
  `status` CHAR(1) NOT NULL DEFAULT 'Y',
  `created_by` INT(15) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cqf_package` (`costing_package_id`, `status`),
  CONSTRAINT `fk_cqf_package`
    FOREIGN KEY (`costing_package_id`) REFERENCES `costing_packages` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
