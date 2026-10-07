-- Track slow source extraction separately from the upload request.
CREATE TABLE IF NOT EXISTS faq_knowledge_imports (
  ImportID CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  State ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
  SourceType ENUM('pdf','document','csv','url') NOT NULL,
  FileName VARCHAR(255) NOT NULL DEFAULT '',
  StoredName VARCHAR(255) NULL,
  SourceUrl VARCHAR(2048) NULL,
  DestinationID INT NULL,
  SourceCount INT UNSIGNED NOT NULL DEFAULT 0,
  ErrorMessage TEXT NULL,
  InsertBy INT NOT NULL,
  InsertDate DATETIME NOT NULL,
  StartedDate DATETIME NULL,
  FinishedDate DATETIME NULL,
  PRIMARY KEY (ImportID),
  KEY idx_fki_staff_state (InsertBy, State, InsertDate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
