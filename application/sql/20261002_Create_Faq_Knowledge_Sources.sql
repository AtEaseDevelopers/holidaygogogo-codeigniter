-- Reusable, staff-approved evidence for package/resort/room-specific FAQs.
CREATE TABLE IF NOT EXISTS `faq_knowledge_sources` (
  `SourceID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `SourceType` ENUM('pdf','url','supplier_confirmation','approved_staff_reply') NOT NULL,
  `Status` ENUM('pending','approved','expired','rejected') NOT NULL DEFAULT 'pending',
  `Title` VARCHAR(255) NOT NULL,
  `SourceUrl` VARCHAR(2048) NULL,
  `StoredName` VARCHAR(255) NULL,
  `Excerpt` MEDIUMTEXT NULL,
  `ProductID` INT NULL,
  `ResortName` VARCHAR(255) NULL,
  `RoomType` VARCHAR(255) NULL,
  `Topic` VARCHAR(80) NULL,
  `ValidFrom` DATE NULL,
  `ValidTo` DATE NULL,
  `VerifiedBy` INT NULL,
  `VerifiedDate` DATETIME NULL,
  `InsertBy` INT NULL, `InsertDate` DATETIME NOT NULL,
  `UpdateBy` INT NULL, `UpdateDate` DATETIME NOT NULL,
  PRIMARY KEY (`SourceID`),
  KEY `idx_fks_match` (`Status`,`ProductID`,`Topic`),
  KEY `idx_fks_resort_room` (`Status`,`ResortName`,`RoomType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
