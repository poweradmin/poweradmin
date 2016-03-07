START TRANSACTION;

# Logging
DROP TABLE IF EXISTS `log_domains`;
DROP TABLE IF EXISTS `log_domains_type`;
DROP TABLE IF EXISTS `log_records`;
DROP TABLE IF EXISTS `log_records_type`;
DROP TABLE IF EXISTS `log_records_data`;

DELETE FROM `perm_templ_items` WHERE `perm_id` IN (
    SELECT `id` from `perm_items` WHERE name in ('zone_content_rfc_own', 'zone_content_rfc_other', 'rfc_can_commit')
);

# RFCs
DELETE FROM `perm_items` WHERE `name` = 'zone_content_rfc_own' LIMIT 1;
DELETE FROM `perm_items` WHERE `name` = 'zone_content_rfc_other' LIMIT 1;
DELETE FROM `perm_items` WHERE `name` = 'rfc_can_commit' LIMIT 1;
DROP TABLE IF EXISTS `rfc_change`;
DROP TABLE IF EXISTS `rfc`;
DROP TABLE IF EXISTS `rfc_data`;

COMMIT;
