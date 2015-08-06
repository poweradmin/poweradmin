START TRANSACTION;

#################################################
# Type tables
#################################################
CREATE TABLE IF NOT EXISTS `log_domains_type` (
  `id`    INT         NOT NULL AUTO_INCREMENT,
  `name`  VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),

  UNIQUE INDEX `name_UNIQUE` (`name` ASC)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `log_records_type` (
  `id`    INT         NOT NULL AUTO_INCREMENT,
  `name`  VARCHAR(32) NOT NULL,
  PRIMARY KEY (`id`),

  UNIQUE INDEX `name_UNIQUE` (`name` ASC)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

#################################################
# Log data
#################################################

# For a record
CREATE TABLE IF NOT EXISTS `log_records_data` (
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

# Logs when (maybe with help of an approving user) a domain was created or deleted
CREATE TABLE IF NOT EXISTS `log_domains` (
  `id`                  INT          NOT NULL AUTO_INCREMENT,
  `log_domains_type_id` INT          NOT NULL,
  `domain_name`         VARCHAR(255) NOT NULL,
  `timestamp`           DATETIME     NOT NULL,
  `user`                VARCHAR(64)  NOT NULL,
  `user_approve`        VARCHAR(64),
  PRIMARY KEY (`id`),

  INDEX `fk_log_domains_1_idx` (`log_domains_type_id` ASC),

  CONSTRAINT `fk_log_domains_1`
  FOREIGN KEY (`log_domains_type_id`)
  REFERENCES `log_domains_type` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

# Logs when (maybe with help of an approving user) a record was created/edited/deleted
CREATE TABLE IF NOT EXISTS `log_records` (
  `id`                    INT         NOT NULL AUTO_INCREMENT,
  `log_records_type_id`   INT         NOT NULL,
  `timestamp`             DATETIME    NOT NULL,
  `user`                  VARCHAR(64) NOT NULL,
  `user_approve`          VARCHAR(64),
  `prior`                 INT             NULL,
  `after`                 INT             NULL,
  PRIMARY KEY (`id`),

  INDEX `fk_log_records_1_idx` (`log_records_type_id` ASC),
  INDEX `fk_log_records_2_idx` (`prior` ASC),
  INDEX `fk_log_records_3_idx` (`after` ASC),

  CONSTRAINT `fk_log_records_1`
  FOREIGN KEY (`log_records_type_id`)
  REFERENCES `log_records_type` (`id`),

  CONSTRAINT `fk_log_records_2`
  FOREIGN KEY (`prior`)
  REFERENCES `log_records_data` (`id`),

  CONSTRAINT `fk_log_records_3`
  FOREIGN KEY (`after`)
  REFERENCES `log_records_data` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

#################################################
# Data
#################################################
LOCK TABLES `log_domains_type` WRITE;
INSERT INTO `log_domains_type` VALUES
  (1, 'domain_create'), (2, 'domain_delete');
UNLOCK TABLES;

LOCK TABLES `log_records_type` WRITE;
INSERT INTO `log_records_type` VALUES
  (1, 'record_create'), (2, 'record_edit'), (3, 'record_delete');
UNLOCK TABLES;

#################################################
# Add new permissions (RFCs)
#################################################
LOCK TABLES `perm_items` WRITE;
INSERT INTO `perm_items` (`name`, `descr`) VALUES
  ('zone_content_rfc_own', 'User can create RFCs for changes in zones he owns.'),
  ('zone_content_rfc_other', 'User can create RFCs for changes in zones he does not own.');
UNLOCK TABLES;

#################################################
# Add new Tables
#################################################

# The metadata for RFCs
CREATE TABLE IF NOT EXISTS `rfc` (
  `id`        INT         NOT NULL AUTO_INCREMENT,
  `timestamp` DATETIME    NOT NULL,
  `initiator` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

# The actual data for RFCs (shadow version of `records`)
CREATE TABLE IF NOT EXISTS `rfc_data` (
  `id`          INT             NOT NULL AUTO_INCREMENT,
  `domain_id`   VARCHAR(45),
  `name`        VARCHAR(255),
  `type`        VARCHAR(10),
  `content`     VARCHAR(64000),
  `ttl`         INT(11),
  `prio`        INT(11),
  `change_date` INT(11),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

# A change in a zone
CREATE TABLE IF NOT EXISTS `rfc_change` (
  `id`     INT          NOT NULL AUTO_INCREMENT,
  `zone`   INT          NOT NULL,
  `serial` VARCHAR(45)  NOT NULL,
  `prior`  INT,
  `after`  INT,
  `rfc`    INT          NOT NULL,
  PRIMARY KEY (`id`),

  INDEX `fk_rfc_change_1_idx` (`prior` ASC),
  INDEX `fk_rfc_change_2_idx` (`after` ASC),
  INDEX `fk_rfc_change_3_idx` (`rfc` ASC),

  CONSTRAINT `fk_rfc_change_1`
  FOREIGN KEY (`prior`)
  REFERENCES `rfc_data` (`id`),

  CONSTRAINT `fk_rfc_change_2`
  FOREIGN KEY (`after`)
  REFERENCES `rfc_data` (`id`),

  CONSTRAINT `fk_rfc_change_3`
  FOREIGN KEY (`rfc`)
  REFERENCES `rfc` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

COMMIT;
