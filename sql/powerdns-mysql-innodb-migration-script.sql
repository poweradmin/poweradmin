-- 
-- InnoDB Migration Script
-- 
ALTER TABLE users ENGINE=InnoDB;
ALTER TABLE perm_items ENGINE=InnoDB;
ALTER TABLE perm_templ ENGINE=InnoDB;
ALTER TABLE perm_templ_items ENGINE=InnoDB;
ALTER TABLE zones ENGINE=InnoDB;
ALTER TABLE zone_templ ENGINE=InnoDB;
ALTER TABLE zone_templ_records ENGINE=InnoDB;
ALTER TABLE domainmetadata ENGINE=InnoDB;
ALTER TABLE Cryptokeys ENGINE=InnoDB;
ALTER TABLE tsigkeys ENGINE=InnoDB;
ALTER TABLE domains ENGINE=InnoDB;
ALTER TABLE records ENGINE=InnoDB;
ALTER TABLE supermasters ENGINE=InnoDB;

