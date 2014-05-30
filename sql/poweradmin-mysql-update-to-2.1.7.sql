ALTER TABLE users ADD use_ldap BOOLEAN NOT NULL;

ALTER TABLE users ENGINE=InnoDB;
ALTER TABLE perm_items ENGINE=InnoDB;
ALTER TABLE perm_templ ENGINE=InnoDB;
ALTER TABLE perm_templ_items ENGINE=InnoDB;
ALTER TABLE zones ENGINE=InnoDB;
ALTER TABLE zone_templ ENGINE=InnoDB;
ALTER TABLE zone_templ_records ENGINE=InnoDB;
ALTER TABLE domainmetadata ENGINE=InnoDB;
ALTER TABLE cryptokeys ENGINE=InnoDB;
ALTER TABLE tsigkeys ENGINE=InnoDB;
ALTER TABLE domains ENGINE=InnoDB;
ALTER TABLE records ENGINE=InnoDB;
ALTER TABLE supermasters ENGINE=InnoDB;

CREATE TABLE records_zone_templ (
    domain_id INTEGER NOT NULL,
    record_id INTEGER NOT NULL,
    zone_templ_id INTEGER NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE migrations (
    version VARCHAR(255) NOT NULL,
    apply_time INTEGER NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
