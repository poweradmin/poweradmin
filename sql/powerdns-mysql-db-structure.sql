CREATE TABLE domains (
    id              INTEGER                    AUTO_INCREMENT,
    name            VARCHAR(255)     NOT NULL,
    master          VARCHAR(128) DEFAULT NULL,
    last_check      INTEGER      DEFAULT NULL,
    type            VARCHAR(6)       NOT NULL,
    notified_serial INTEGER      DEFAULT NULL, 
    account         VARCHAR(40)  DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX name_index ( name )
) ENGINE=InnoDB;

CREATE TABLE records (
    id              INT auto_increment,
    domain_id       INT DEFAULT NULL,
    name            VARCHAR(255) DEFAULT NULL,
    type            VARCHAR(10) DEFAULT NULL,
    content         VARCHAR(64000) DEFAULT NULL,
    ttl             INT DEFAULT NULL,
    prio            INT DEFAULT NULL,
    change_date     INT DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX rec_name_index ( name ),
    INDEX nametype_index ( name, type ),
    INDEX domain_id ( domain_id )
) ENGINE=InnoDB;

CREATE TABLE supermasters (
  ip VARCHAR(25) NOT NULL, 
  nameserver VARCHAR(255) NOT NULL, 
  account VARCHAR(40) DEFAULT NULL,
  PRIMARY KEY ( ip )
) ENGINE=InnoDB;
