START TRANSACTION;

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
  `zone`   VARCHAR(255) NOT NULL,
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
