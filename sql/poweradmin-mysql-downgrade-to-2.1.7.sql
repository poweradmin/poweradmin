START TRANSACTION;

DROP TABLE IF EXISTS `powerdns`.`log_domains`;
DROP TABLE IF EXISTS `powerdns`.`log_domains_type`;
DROP TABLE IF EXISTS `powerdns`.`log_records`;
DROP TABLE IF EXISTS `powerdns`.`log_records_type`;
DROP TABLE IF EXISTS `powerdns`.`log_records_data`;

COMMIT;
