-- Extra team memberships for a multi-team leader.
-- Only a TEAM LEAD (admin.Level = '25') or OP TEAM LEAD ('45') may oversee more
-- than one team. admin.TeamID stays the single PRIMARY/home team (it still drives
-- sale attribution, sales targets and the Sales-by-Team card). Each row here
-- grants the leader VISIBILITY over an ADDITIONAL team (booking/payment listing
-- scope and checklist edit rights). A plain member keeps exactly one team via
-- admin.TeamID and has no rows here. See application/helpers/team_scope_helper.php

CREATE TABLE IF NOT EXISTS `admin_team` (
  `AdminID`    INT      NOT NULL,
  `TeamID`     INT      NOT NULL,
  `InsertBy`   INT      NULL DEFAULT NULL,
  `InsertDate` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`AdminID`, `TeamID`),
  KEY `idx_at_team` (`TeamID`),
  CONSTRAINT `fk_at_admin` FOREIGN KEY (`AdminID`)
    REFERENCES `admin` (`AdminID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_at_team` FOREIGN KEY (`TeamID`)
    REFERENCES `team` (`TeamID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
