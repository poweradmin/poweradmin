-- Add primary key to records_zone_templ table for MySQL InnoDB Cluster compatibility (Issue #906)
-- InnoDB Cluster requires all tables to have a primary key for replication
ALTER TABLE `records_zone_templ` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (`id`);
