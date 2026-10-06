-- Apply after 20261002_Create_Faq_Knowledge_Sources.sql. Legacy completed rows stay intact.
ALTER TABLE faq_suggestions MODIFY State ENUM('pending','pending_context','need_context','need_official_source','need_human_review','ready_to_draft','draft_ready','accepted','dismissed') NOT NULL DEFAULT 'pending_context';
ALTER TABLE faq_suggestions MODIFY Description MEDIUMTEXT NULL;
ALTER TABLE faq MODIFY Description MEDIUMTEXT NULL;
ALTER TABLE faq_suggestions ADD ReviewReason TEXT NULL;
ALTER TABLE faq_suggestions ADD DraftSourcesJson MEDIUMTEXT NULL;
ALTER TABLE faq_suggestions ADD DraftHash CHAR(64) NULL;
ALTER TABLE faq_suggestions ADD KEY idx_workspace (Status, State, SuggestionID);
UPDATE faq_suggestions SET State = 'pending_context', ReviewReason = 'Legacy candidate: re-evaluate with supporting information before publishing.' WHERE State = 'pending';
ALTER TABLE faq_knowledge_sources ADD ExtractedText MEDIUMTEXT NULL;
ALTER TABLE faq_knowledge_sources ADD RetrievedDate DATETIME NULL;
ALTER TABLE faq_knowledge_sources ADD FilePath VARCHAR(512) NULL;
ALTER TABLE faq_knowledge_sources ADD ReviewDue DATE NULL;
ALTER TABLE faq_knowledge_sources ADD AppliesToAllRooms TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE faq_knowledge_sources ADD KEY idx_source_validity (Status, Topic, ValidFrom, ValidTo, ReviewDue);
ALTER TABLE faq_knowledge_sources ADD KEY idx_source_topic (Topic, SourceID);
UPDATE faq_knowledge_sources SET FilePath = CONCAT('assets/upload/faq_knowledge_sources/', StoredName) WHERE StoredName IS NOT NULL AND FilePath IS NULL;
-- Append-only suggestion, source and publication history.
CREATE TABLE IF NOT EXISTS faq_workspace_audit (
  AuditID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  SuggestionID INT UNSIGNED NULL, SourceID INT UNSIGNED NULL,
  Action VARCHAR(80) NOT NULL, BeforeJson MEDIUMTEXT NULL, AfterJson MEDIUMTEXT NULL,
  InsertBy INT NULL, InsertDate DATETIME NOT NULL,
  PRIMARY KEY (AuditID), KEY idx_candidate_audit (SuggestionID, AuditID), KEY idx_source_audit (SourceID, AuditID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
