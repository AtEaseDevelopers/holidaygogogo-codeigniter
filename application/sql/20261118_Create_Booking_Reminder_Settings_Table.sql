CREATE TABLE IF NOT EXISTS `booking_reminder_settings` (
    `id` TINYINT UNSIGNED NOT NULL,
    `draft_bc_reminder_hours` SMALLINT UNSIGNED NOT NULL,
    `pending_bc_reminder_hours` SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
