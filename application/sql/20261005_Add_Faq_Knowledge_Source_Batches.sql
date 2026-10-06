-- One sequential batch number per saved import, shared by all its source rows.
ALTER TABLE faq_knowledge_sources ADD BatchID INT UNSIGNED NULL AFTER SourceID;
ALTER TABLE faq_knowledge_sources ADD KEY idx_source_batch (BatchID, SourceID);

-- A transactional counter prevents simultaneous imports using the same number.
CREATE TABLE IF NOT EXISTS faq_knowledge_source_batch_sequence (
  SequenceID TINYINT UNSIGNED NOT NULL,
  LastBatchID INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (SequenceID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO faq_knowledge_source_batch_sequence (SequenceID, LastBatchID) VALUES (1, 0);

-- Legacy imports have no explicit batch marker. Infer them from the original
-- URL/file, source type, staff member and creation timestamp, ordered by source ID.
DROP TEMPORARY TABLE IF EXISTS faq_knowledge_source_legacy_batches;
CREATE TEMPORARY TABLE faq_knowledge_source_legacy_batches (
  LegacyBatchID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  SourceType VARCHAR(30) NOT NULL,
  SourceUrl VARCHAR(2048) COLLATE utf8mb4_bin NULL,
  StoredName VARCHAR(255) COLLATE utf8mb4_bin NULL,
  InsertBy INT NULL,
  InsertDate DATETIME NOT NULL,
  PRIMARY KEY (LegacyBatchID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
UPDATE faq_knowledge_source_batch_sequence
SET LastBatchID = GREATEST(LastBatchID, COALESCE((SELECT MAX(BatchID) FROM faq_knowledge_sources), 0))
WHERE SequenceID = 1;
INSERT INTO faq_knowledge_source_legacy_batches (SourceType, SourceUrl, StoredName, InsertBy, InsertDate)
SELECT SourceType, BINARY SourceUrl, BINARY StoredName, InsertBy, InsertDate
FROM faq_knowledge_sources
WHERE BatchID IS NULL
GROUP BY SourceType, BINARY SourceUrl, BINARY StoredName, InsertBy, InsertDate
ORDER BY MIN(SourceID);
UPDATE faq_knowledge_sources AS source
JOIN faq_knowledge_source_legacy_batches AS legacy
  ON source.SourceType = legacy.SourceType
  AND BINARY source.SourceUrl <=> BINARY legacy.SourceUrl
  AND BINARY source.StoredName <=> BINARY legacy.StoredName
  AND source.InsertBy <=> legacy.InsertBy
  AND source.InsertDate = legacy.InsertDate
JOIN faq_knowledge_source_batch_sequence AS counter ON counter.SequenceID = 1
SET source.BatchID = counter.LastBatchID + legacy.LegacyBatchID
WHERE source.BatchID IS NULL;
UPDATE faq_knowledge_source_batch_sequence
SET LastBatchID = GREATEST(LastBatchID, COALESCE((SELECT MAX(BatchID) FROM faq_knowledge_sources), 0))
WHERE SequenceID = 1;
COMMIT;

DROP TEMPORARY TABLE faq_knowledge_source_legacy_batches;
ALTER TABLE faq_knowledge_sources MODIFY BatchID INT UNSIGNED NOT NULL;
