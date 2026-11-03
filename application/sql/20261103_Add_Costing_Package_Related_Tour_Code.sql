ALTER TABLE costing_packages ADD COLUMN related_tour_code VARCHAR(50) NULL DEFAULT NULL AFTER tour_code;
