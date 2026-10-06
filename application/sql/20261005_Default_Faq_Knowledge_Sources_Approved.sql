-- New knowledge sources are approved when staff save the reviewed import.
ALTER TABLE faq_knowledge_sources ALTER COLUMN Status SET DEFAULT 'approved';
