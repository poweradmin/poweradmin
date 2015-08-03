START TRANSACTION;

DELETE FROM `perm_items` WHERE `name` = 'zone_content_rfc_own' LIMIT 1;
DELETE FROM `perm_items` WHERE `name` = 'zone_content_rfc_other' LIMIT 1;

DROP TABLE IF EXISTS `rfc_data`;
DROP TABLE IF EXISTS `rfcs`;

COMMIT;
