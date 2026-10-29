ALTER TABLE costing_packages ADD COLUMN sales_admin_mobile VARCHAR(50) NULL AFTER sales_admin_id;
ALTER TABLE costing_packages ADD COLUMN validity_date DATE NULL AFTER sales_admin_mobile;
