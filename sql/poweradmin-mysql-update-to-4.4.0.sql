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

-- Add the edit-as-client permission to databases upgraded from 2.x: 2.2.1 put it in the structure
-- files only, so the 4.1.0 and 4.2.0 Editor grants found nothing. Grant it only where it was missing.
SET @add_client_edit = (SELECT COUNT(*) = 0 FROM `perm_items` WHERE `name` = 'zone_content_edit_own_as_client');

INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'zone_content_edit_own_as_client', 'User is allowed to edit record, but not SOA and NS.'
FROM DUAL
WHERE @add_client_edit = 1;

INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pt.`id`, pi.`id`
FROM `perm_templ` pt
JOIN `perm_items` pi ON pi.`name` = 'zone_content_edit_own_as_client'
WHERE @add_client_edit = 1
  AND ((pt.`name` = 'Editor' AND pt.`template_type` = 'user') OR (pt.`name` = 'Editors' AND pt.`template_type` = 'group'));
