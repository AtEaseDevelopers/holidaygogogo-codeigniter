CREATE TABLE IF NOT EXISTS `nav_module_access` (
  `NavAccessID` INT AUTO_INCREMENT PRIMARY KEY,
  `AdminID` INT NOT NULL,
  `Module` VARCHAR(64) NOT NULL,
  `CanView` TINYINT(1) NOT NULL DEFAULT 0,
  `CanEdit` TINYINT(1) NOT NULL DEFAULT 0,
  `InsertBy` INT NULL,
  `InsertDate` DATETIME NULL,
  `UpdateBy` INT NULL,
  `UpdateDate` DATETIME NULL,
  UNIQUE KEY `uq_nav_admin_module` (`AdminID`, `Module`)
);
