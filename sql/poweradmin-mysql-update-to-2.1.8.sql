START TRANSACTION;

#################################################
# Type tables
#################################################
CREATE TABLE IF NOT EXISTS `powerdns`.`log_zones_type` (
  `id`    INT         NOT NULL AUTO_INCREMENT,
  `name`  VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),

  UNIQUE INDEX `name_UNIQUE` (`name` ASC)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `powerdns`.`log_records_type` (
  `id`    INT         NOT NULL AUTO_INCREMENT,
  `name`  VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),

  UNIQUE INDEX `name_UNIQUE` (`name` ASC)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

#################################################
# Log data
#################################################

# For a record
CREATE TABLE IF NOT EXISTS `powerdns`.`log_records_data` (
  `id`          INT             NOT NULL AUTO_INCREMENT,
  `domain_id`   INT,
  `name`        VARCHAR(255),
  `type`        VARCHAR(10),
  `content`     VARCHAR(64000),
  `ttl`         INT,
  `prio`        INT,
  `change_date` INT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

#################################################
# Log management
#################################################

# Logs when (maybe with help of an approving user) a zone was created or deleted
CREATE TABLE IF NOT EXISTS `powerdns`.`log_zones` (
  `id`                INT         NOT NULL AUTO_INCREMENT,
  `log_zones_type_id` INT         NOT NULL,
  `timestamp`         DATETIME    NOT NULL,
  `user`              VARCHAR(64) NOT NULL,
  `user_approve`      VARCHAR(64),
  PRIMARY KEY (`id`),

  INDEX `fk_log_zones_1_idx` (`log_zones_type_id` ASC),

  CONSTRAINT `fk_log_zones_1`
  FOREIGN KEY (`log_zones_type_id`)
  REFERENCES `powerdns`.`log_zones_type` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

# Logs when (maybe with help of an approving user) a record was created/edited/deleted
CREATE TABLE IF NOT EXISTS `powerdns`.`log_records` (
  `id`                    INT         NOT NULL AUTO_INCREMENT,
  `log_records_type_id`   INT         NOT NULL,
  `timestamp`             DATETIME    NOT NULL,
  `user`                  VARCHAR(64) NOT NULL,
  `user_approve`          VARCHAR(64),
  `prior`                 INT         NOT NULL,
  `after`                 INT         NOT NULL,
  PRIMARY KEY (`id`),

  INDEX `fk_log_records_1_idx` (`log_records_type_id` ASC),
  INDEX `fk_log_records_2_idx` (`prior` ASC),
  INDEX `fk_log_records_3_idx` (`after` ASC),

  CONSTRAINT `fk_log_records_1`
  FOREIGN KEY (`log_records_type_id`)
  REFERENCES `powerdns`.`log_records_type` (`id`),

  CONSTRAINT `fk_log_records_2`
  FOREIGN KEY (`prior`)
  REFERENCES `powerdns`.`log_records_data` (`id`),

  CONSTRAINT `fk_log_records_3`
  FOREIGN KEY (`after`)
  REFERENCES `powerdns`.`log_records_data` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

#################################################
# Data
#################################################
LOCK TABLES `powerdns`.`log_zones_type` WRITE;
INSERT INTO `powerdns`.`log_zones_type` VALUES
  (1, 'zone_create'), (2, 'zone_delete');
UNLOCK TABLES;

LOCK TABLES `powerdns`.`log_records_type` WRITE;
INSERT INTO `powerdns`.`log_records_type` VALUES
  (1, 'record_create'), (2, 'record_edit'), (3, 'record_delete');
UNLOCK TABLES;

COMMIT;
