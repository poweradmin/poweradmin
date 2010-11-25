ALTER TABLE zones ADD zone_templ_id INT( 11 ) NOT NULL;
ALTER TABLE zones ENGINE = InnoDB;
ALTER TABLE zone_templ ENGINE = InnoDB;
ALTER TABLE zone_templ_records ENGINE = InnoDB;
