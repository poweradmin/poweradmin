-- Poweradmin MySQL/MariaDB update script for version 4.0.5
-- This script adds a primary key to the records_zone_templ table
-- Required for MySQL InnoDB Cluster compatibility (closes #906)

-- Add id column with auto_increment and primary key to records_zone_templ
-- This is required for MySQL InnoDB Cluster which requires all tables to have a primary key
ALTER TABLE `records_zone_templ`
    ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`id`);
