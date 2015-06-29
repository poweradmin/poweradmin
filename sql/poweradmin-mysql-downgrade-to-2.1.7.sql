START TRANSACTION;

DROP TABLE IF EXISTS `powerdns`.`log_zones`;
DROP TABLE IF EXISTS `powerdns`.`log_zones_type`;
DROP TABLE IF EXISTS `powerdns`.`log_records`;
DROP TABLE IF EXISTS `powerdns`.`log_records_type`;
DROP TABLE IF EXISTS `powerdns`.`log_records_data`;

COMMIT;
