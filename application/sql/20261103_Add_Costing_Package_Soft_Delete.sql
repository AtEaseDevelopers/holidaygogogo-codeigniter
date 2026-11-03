-- Soft-delete audit columns for costing packages.
-- Delete now flags the package status as deleted and keeps every row intact.
-- These columns record who deleted it and when (audit-only).
-- Plain ADD COLUMN (no IF NOT EXISTS) so a re-run 1060 is treated as non-fatal.
ALTER TABLE costing_packages ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;
ALTER TABLE costing_packages ADD COLUMN deleted_by INT NULL DEFAULT NULL;
