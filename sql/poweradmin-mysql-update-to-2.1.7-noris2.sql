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
CREATE TABLE IF NOT EXISTS `rfcs` (
  `id`        INT         NOT NULL AUTO_INCREMENT,
  `timestamp` DATETIME    NOT NULL,
  `initiator` VARCHAR(64) NOT NULL,
  `expired`   BOOLEAN               DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

# The actual data for RFCs (shadow version of `records`)
CREATE TABLE IF NOT EXISTS `rfc_data` (
  `id`          INT           NOT NULL AUTO_INCREMENT,
  `domain_id`   VARCHAR(45),
  `name`        VARCHAR(255),
  `type`        VARCHAR(10),
  `content`     VARCHAR(64000),
  `ttl`         INT(11),
  `prio`        INT(11),
  `change_date` INT(11),
  `rfc` INT(11)               NOT NULL,
  PRIMARY KEY (`id`, `rfc`),

  INDEX `fk_rfc_data_1_idx` (`rfc` ASC),

  CONSTRAINT `fk_rfc_data_1`
  FOREIGN KEY (`rfc`)
  REFERENCES `rfcs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

COMMIT;
