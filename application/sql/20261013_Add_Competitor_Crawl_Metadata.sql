-- Preserve canonical/original URLs, regional sources and quality flags after cache loss.
ALTER TABLE `competitor_crawl_items` ADD COLUMN `metadata_json` LONGTEXT NULL AFTER `item_text`;
