-- Destination scope for manually imported knowledge, including Word/text files.
ALTER TABLE faq_knowledge_sources ADD DestinationID INT NULL AFTER ProductID;
ALTER TABLE faq_knowledge_sources ADD KEY idx_fks_destination (Status, DestinationID, Topic);
ALTER TABLE faq_knowledge_sources MODIFY SourceType ENUM('pdf','document','csv','url','manual','supplier_confirmation','approved_staff_reply') NOT NULL;
