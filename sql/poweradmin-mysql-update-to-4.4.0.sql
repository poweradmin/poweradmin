-- Poweradmin schema update to 4.4.0
-- Add is_default flag to zone_templ for marking a system-wide default zone template
-- Only one template should carry is_default=1 at a time; uniqueness is enforced at the application layer

ALTER TABLE `zone_templ` ADD COLUMN `is_default` tinyint(1) NOT NULL DEFAULT 0;
