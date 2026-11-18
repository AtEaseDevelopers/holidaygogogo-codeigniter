ALTER TABLE `notification`
    ADD COLUMN `booking_status_log_id` INT NULL DEFAULT NULL
        COMMENT 'Stage entry for booking reminders, or 0 for bookings without transition history',
    ADD UNIQUE KEY `uq_notification_booking_stage`
        (`user_id`, `type`, `owner_type`, `owner_id`, `booking_status_log_id`);
