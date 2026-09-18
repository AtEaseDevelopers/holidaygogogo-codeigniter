CREATE TABLE IF NOT EXISTS `faq_suggestion_runs` (
  `RunID`        INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `Source`       VARCHAR(16) NOT NULL DEFAULT 'chats',
  `StartDate`    DATE NULL DEFAULT NULL,
  `EndDate`      DATE NULL DEFAULT NULL,
  `Mobile`       VARCHAR(50) NULL DEFAULT NULL,
  `FileName`     VARCHAR(255) NULL DEFAULT NULL,
  `StoredName`   VARCHAR(255) NULL DEFAULT NULL,
  `Model`        VARCHAR(100) NULL DEFAULT NULL,
  `CostUsd`      DECIMAL(12,6) NULL DEFAULT NULL,
  `Proposed`     INT(11) NOT NULL DEFAULT 0,
  `Created`      INT(11) NOT NULL DEFAULT 0,
  `RunState`     VARCHAR(20) NOT NULL DEFAULT 'done',
  `ErrorMessage` TEXT NULL DEFAULT NULL,
  `Status`       ENUM('Y','N') NOT NULL DEFAULT 'Y',
  `InsertBy`     INT(11) NULL,
  `InsertDate`   DATETIME DEFAULT CURRENT_TIMESTAMP,
  `UpdateBy`     INT(11) NULL,
  `UpdateDate`   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`RunID`),
  KEY `idx_fsr_status` (`Status`, `RunState`),
  KEY `idx_fsr_source` (`Source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `faq_suggestions` ADD COLUMN `RunID` INT(11) UNSIGNED NULL DEFAULT NULL AFTER `RunKey`;
ALTER TABLE `faq_suggestions` ADD INDEX `idx_faq_sugg_runid` (`RunID`);
