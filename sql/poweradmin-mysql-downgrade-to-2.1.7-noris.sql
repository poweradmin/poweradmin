START TRANSACTION;

DELETE FROM `perm_items` WHERE `name` = 'zone_content_rfc_own' LIMIT 1;
DELETE FROM `perm_items` WHERE `name` = 'zone_content_rfc_other' LIMIT 1;

DROP TABLE IF EXISTS `rfc_change`;
DROP TABLE IF EXISTS `rfc`;
DROP TABLE IF EXISTS `rfc_data`;


COMMIT;
