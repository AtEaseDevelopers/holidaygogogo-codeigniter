-- Durable store for crawled products so they survive loss of the job items files.
CREATE TABLE IF NOT EXISTS `competitor_crawl_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `feature` VARCHAR(20) NOT NULL DEFAULT 'competitor',
  `job_id` VARCHAR(64) NOT NULL,
  `host` VARCHAR(255) NOT NULL DEFAULT '',
  `src_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `idx` INT NOT NULL DEFAULT 0,
  `url` VARCHAR(1000) NOT NULL DEFAULT '',
  `title` VARCHAR(500) NULL DEFAULT NULL,
  `item_text` LONGTEXT NULL DEFAULT NULL,
  `analysis_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cci_feature_job_idx` (`feature`, `job_id`, `idx`),
  KEY `idx_cci_feature_host` (`feature`, `host`),
  KEY `idx_cci_analysis` (`analysis_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
