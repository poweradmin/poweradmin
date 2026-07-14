-- Poweradmin schema update to 4.4.0
-- Add is_default flag to zone_templ for marking a system-wide default zone template
-- Only one template should carry is_default=1 at a time; uniqueness is enforced at the application layer

ALTER TABLE `zone_templ` ADD COLUMN `is_default` tinyint(1) NOT NULL DEFAULT 0;

-- OIDC/SAML external subject identifiers must match one-for-one. The default
-- utf8mb4_unicode_ci collation is case- and accent-insensitive, so distinct
-- subjects such as "victim" and "victím" compare as equal and resolve to the
-- same local account. Force a binary collation on the identity columns.
ALTER TABLE `oidc_user_links`
    MODIFY COLUMN `provider_id` VARCHAR(50) NOT NULL COLLATE utf8mb4_bin,
    MODIFY COLUMN `oidc_subject` VARCHAR(255) NOT NULL COLLATE utf8mb4_bin;
ALTER TABLE `saml_user_links`
    MODIFY COLUMN `provider_id` VARCHAR(50) NOT NULL COLLATE utf8mb4_bin,
    MODIFY COLUMN `saml_subject` VARCHAR(255) NOT NULL COLLATE utf8mb4_bin;
