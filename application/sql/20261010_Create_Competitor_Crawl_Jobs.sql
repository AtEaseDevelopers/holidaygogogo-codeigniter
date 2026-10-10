-- Durable per-crawl job record so the listing survives loss of the status files.
CREATE TABLE IF NOT EXISTS `competitor_crawl_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `feature` VARCHAR(20) NOT NULL DEFAULT 'competitor',
  `job_id` VARCHAR(64) NOT NULL,
  `host` VARCHAR(255) NOT NULL DEFAULT '',
  `src_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `mode` VARCHAR(20) NOT NULL DEFAULT 'crawl',
  `state` VARCHAR(20) NOT NULL DEFAULT 'queued',
  `product_count` INT NOT NULL DEFAULT 0,
  `keyword` VARCHAR(255) NOT NULL DEFAULT '',
  `ai_crawl` TINYINT(1) NOT NULL DEFAULT 0,
  `competitor_name` VARCHAR(255) NULL DEFAULT NULL,
  `cost_total` DECIMAL(12,6) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ccj_feature_job` (`feature`, `job_id`),
  KEY `idx_ccj_feature_host` (`feature`, `host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
