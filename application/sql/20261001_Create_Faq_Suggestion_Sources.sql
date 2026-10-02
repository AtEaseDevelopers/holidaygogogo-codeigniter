-- Evidence links for AI-generated FAQ suggestions. A suggestion may be backed
-- by many messages, so this is deliberately a child table rather than columns
-- on faq_suggestions.
CREATE TABLE IF NOT EXISTS `faq_suggestion_sources` (
  `SourceID`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `SuggestionID`  INT(11) UNSIGNED NOT NULL,
  `RunID`         INT(11) UNSIGNED NOT NULL,
  `SourceType`    VARCHAR(32) NOT NULL COMMENT 'ghl_message | whatsapp_history | uploaded_chat_file',
  `SourceRef`     VARCHAR(64) NOT NULL COMMENT 'Stable reference shown to the AI, e.g. S12',
  `GhlMessageID`  BIGINT UNSIGNED NULL DEFAULT NULL,
  `ChatFileID`    INT NULL DEFAULT NULL,
  `MessageIndex`  INT NULL DEFAULT NULL COMMENT '1-based message position for an uploaded chat file',
  `SourceExcerpt` MEDIUMTEXT NULL,
  `InsertDate`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`SourceID`),
  UNIQUE KEY `uq_fss_suggestion_ref` (`SuggestionID`, `SourceRef`),
  KEY `idx_fss_suggestion` (`SuggestionID`),
  KEY `idx_fss_run` (`RunID`),
  KEY `idx_fss_ghl_message` (`GhlMessageID`),
  KEY `idx_fss_chat_file` (`ChatFileID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
