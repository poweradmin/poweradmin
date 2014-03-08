-- 
-- InnoDB Migration Script
-- 

SET SESSION SQL_MODE='ANSI,ANSI_QUOTES,TRADITIONAL';

ALTER TABLE users ORDER BY id;
ALTER TABLE users ENGINE=InnoDB;

ALTER TABLE perm_items ORDER BY id;
ALTER TABLE perm_items ENGINE=InnoDB;

ALTER TABLE perm_templ ORDER BY id;
ALTER TABLE perm_templ ENGINE=InnoDB;

ALTER TABLE perm_templ_items ORDER BY id;
ALTER TABLE perm_templ_items ENGINE=InnoDB;

ALTER TABLE zones ORDER BY id;
ALTER TABLE zones ENGINE=InnoDB;

ALTER TABLE zone_templ ORDER BY id;
ALTER TABLE zone_templ ENGINE=InnoDB;

ALTER TABLE zone_templ_records ORDER BY id;
ALTER TABLE zone_templ_records ENGINE=InnoDB;

ALTER TABLE domainmetadata ORDER BY id;
ALTER TABLE domainmetadata ENGINE=InnoDB;

ALTER TABLE Cryptokeys ORDER BY id;
ALTER TABLE Cryptokeys ENGINE=InnoDB;

ALTER TABLE tsigkeys ORDER BY id;
ALTER TABLE tsigkeys ENGINE=InnoDB;

ALTER TABLE domains ORDER BY id;
ALTER TABLE domains ENGINE=InnoDB;

ALTER TABLE records ORDER BY id;
ALTER TABLE records ENGINE=InnoDB;

ALTER TABLE supermasters ORDER BY ip;
ALTER TABLE supermasters ENGINE=InnoDB;

